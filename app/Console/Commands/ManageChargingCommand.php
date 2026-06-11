<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerInfo;
use App\Support\Lektrico\LektricoClient;
use App\Support\SmsNotifier;
use App\Support\Tempo\TempoClient;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ManageChargingCommand extends Command
{
    protected $signature = 'app:manage-charging';

    protected $description = 'Manage charging decisions based on latest metrics';

    public function handle(SmsNotifier $sms): void
    {
        $latest = Metric::query()->latest('recorded_at')->first();

        if (! $latest || $latest->meter_power_total === null || $latest->charger_state === null) {
            Log::warning('manage-charging: missing data, skipping');

            return;
        }

        $charger = LektricoClient::make();
        $session = ChargingSession::query()->whereNull('ended_at')->latest('started_at')->first();
        $isCharging = $latest->charger_state->isCharging();

        if ($isCharging) {
            $chargerInfo = $charger->info();

            if (! $session) {
                $currentAmps = (int) floor($chargerInfo->userPower / 230);
                $session = ChargingSession::query()->create([
                    'started_at' => now(),
                    'mode' => ChargingMode::Manual,
                    'max_current' => $currentAmps,
                ]);
                Log::info("Adopted external charge as manual session at {$currentAmps}A");
            }

            if ($this->handleLoadShedding($latest, $charger, $chargerInfo, $session, $sms)) {
                return;
            }

            $this->updateSession($latest, $session, $chargerInfo);
        } elseif ($session) {
            $this->closeSession($session, $latest, $sms);
            $session = null;
        }

        $this->handleOffPeak($latest, $charger, $session, $sms);
        $this->handleSolar($latest, $charger, $session, $sms, $chargerInfo ?? null);
    }

    private function handleLoadShedding(Metric $latest, LektricoClient $charger, ChargerInfo $chargerInfo, ?ChargingSession $session, SmsNotifier $sms): bool
    {
        $currentAmps = (int) floor($chargerInfo->userPower / 230);
        $maxChargeAmps = config('charging.max_charge_amps');

        if (config('charging.load_shedding_enabled')) {
            $phaseMaxAmps = config('charging.phase_max_amps');
            $maxPhase = max($latest->meter_current_l1, $latest->meter_current_l2, $latest->meter_current_l3);
            $minAmps = config('charging.min_charge_amps');

            if ($maxPhase > $phaseMaxAmps) {
                $overage = (int) ceil($maxPhase - $phaseMaxAmps);
                $targetAmps = $currentAmps - $overage;

                if ($targetAmps < $minAmps) {
                    Log::warning('Load shedding: stopping charge, would go below minimum amps');
                    $charger->stop();
                    $this->closeSession($session, $latest, $sms);
                    $sms->send('Délestage : charge arrêtée, dépassement ampérage', 'load_shedding');

                    return true;
                }

                Log::info("Load shedding: reducing from {$currentAmps}A to {$targetAmps}A");
                $charger->setUserPower($targetAmps);
                $sms->send("Délestage : {$currentAmps}A → {$targetAmps}A");

                return true;
            }
        }

        // Recovery: increase back when below max (solar adjusts itself)
        if ($currentAmps < $maxChargeAmps && $session?->mode !== ChargingMode::Solar) {
            if (config('charging.load_shedding_enabled')) {
                $headroom = (int) floor($phaseMaxAmps - $maxPhase);
                $targetAmps = min($maxChargeAmps, $currentAmps + $headroom);
            } else {
                $targetAmps = $maxChargeAmps;
            }

            if ($targetAmps > $currentAmps) {
                Log::info("Recovery: increasing from {$currentAmps}A to {$targetAmps}A");
                $charger->setUserPower($targetAmps);
                $sms->send("Récupération : {$currentAmps}A → {$targetAmps}A");
            }
        }

        return false;
    }

    private function handleOffPeak(Metric $latest, LektricoClient $charger, ?ChargingSession &$session, SmsNotifier $sms): void
    {
        $isInOffPeak = $this->isInOffPeakWindow(now());

        if ($isInOffPeak && ! $session && $latest->charger_state->isConnectable()) {
            $amps = config('charging.max_charge_amps');
            Log::info('Off-peak: starting charge');
            $charger->setUserPower($amps);
            $charger->start();
            $session = ChargingSession::query()->create([
                'started_at' => now(),
                'mode' => ChargingMode::OffPeak,
                'max_current' => $amps,
            ]);
            $sms->send("Charge HC démarrée à {$amps}A");

            return;
        }

        // Leaving off-peak
        if (! $isInOffPeak && $latest->charger_state->isCharging()) {
            if ($session?->mode === ChargingMode::Solar || $session?->mode === ChargingMode::Manual) {
                return;
            }

            Log::info('Off-peak ended: stopping charge');
            $charger->stop();
            $this->closeSession($session, $latest, $sms);
        }
    }

    private function handleSolar(Metric $latest, LektricoClient $charger, ?ChargingSession &$session, SmsNotifier $sms, ?ChargerInfo $chargerInfo = null): void
    {
        if ($this->isInOffPeakWindow(now())) {
            return;
        }

        $tempo = new TempoClient;
        $threshold = $tempo->costThreshold();
        $hpRate = $tempo->today()->hpRate();

        $minAmps = config('charging.min_charge_amps');
        $maxAmps = config('charging.max_charge_amps');

        // Surplus solaire minimal pour qu'une charge à l'ampérage minimum reste sous le seuil de coût.
        // Sert à la fois à démarrer et à arrêter, ce qui garantit la symétrie (pas de flapping autour du seuil).
        $minSurplus = $minAmps * 230 * max(0, 1 - $threshold / $hpRate);

        $recentMetrics = Metric::query()
            ->latest('recorded_at')
            ->take(3)
            ->get();

        $isCharging = $latest->charger_state->isCharging();

        if (! $isCharging && $latest->charger_state->isConnectable()) {
            $allBelowThreshold = $recentMetrics->count() >= 3
                && $recentMetrics->every(fn (Metric $m) => $m->meter_power_total !== null && $m->meter_power_total < -$minSurplus);

            if ($allBelowThreshold) {
                $avgSurplus = abs($recentMetrics->avg('meter_power_total'));
                $amps = min($maxAmps, max($minAmps, (int) floor($avgSurplus / 230)));

                Log::info("Solar: starting charge at {$amps}A (tempo={$tempo->today()->name}, threshold={$threshold}€)");
                $charger->setUserPower($amps);
                $charger->start();
                $session = ChargingSession::query()->create([
                    'started_at' => now(),
                    'mode' => ChargingMode::Solar,
                    'max_current' => $amps,
                    'current_set_at' => now(),
                ]);
                $sms->send("Charge solaire démarrée à {$amps}A");
            }

            return;
        }

        if ($isCharging && $session?->mode === ChargingMode::Solar) {
            // On ne décide qu'à partir de métriques qui reflètent l'ampérage actuel : sans ce filtre,
            // la fenêtre mélange des mesures prises avant le dernier changement de puissance et la
            // régulation sur-réagit (rampe qui dépasse la production, puis coupure → oscillation).
            $sinceLastChange = $session->current_set_at ?? $session->started_at;
            $stableMetrics = $recentMetrics->filter(
                fn (Metric $m) => $m->meter_power_total !== null
                    && $m->recorded_at->greaterThanOrEqualTo($sinceLastChange)
            );

            if ($stableMetrics->count() < 3) {
                return;
            }

            $currentAmps = (int) floor($chargerInfo->userPower / 230);
            $avgGrid = $stableMetrics->avg('meter_power_total');

            // Puissance solaire réellement disponible pour la voiture = ce qu'elle tire déjà moins l'import réseau.
            $availableSolar = $chargerInfo->userPower - $avgGrid;

            if ($availableSolar < $minSurplus) {
                Log::info("Solar: stopping charge, surplus below threshold (tempo={$tempo->today()->name})");
                $charger->stop();
                $this->closeSession($session, $latest, $sms);

                return;
            }

            $targetAmps = max($minAmps, min($maxAmps, (int) floor($availableSolar / 230)));

            if ($targetAmps !== $currentAmps) {
                Log::info("Solar: adjusting from {$currentAmps}A to {$targetAmps}A");
                $charger->setUserPower($targetAmps);
                $session->update(['current_set_at' => now()]);
                $sms->send("Charge solaire : {$currentAmps}A → {$targetAmps}A");

                if ($targetAmps > ($session->max_current ?? 0)) {
                    $session->update(['max_current' => $targetAmps]);
                }
            }
        }
    }

    public function isInOffPeakWindow(?CarbonInterface $time = null): bool
    {
        $time ??= now();
        $start = \Carbon\Carbon::parse(config('charging.off_peak_start'));
        $end = \Carbon\Carbon::parse(config('charging.off_peak_end'));

        $timeMinutes = $time->hour * 60 + $time->minute;
        $startMinutes = $start->hour * 60 + $start->minute;
        $endMinutes = $end->hour * 60 + $end->minute;

        // Overnight window (e.g. 22:15 → 05:55)
        if ($startMinutes > $endMinutes) {
            return $timeMinutes >= $startMinutes || $timeMinutes < $endMinutes;
        }

        return $timeMinutes >= $startMinutes && $timeMinutes < $endMinutes;
    }

    private function updateSession(Metric $latest, ?ChargingSession $session, ChargerInfo $chargerInfo): void
    {
        if (! $session) {
            return;
        }

        $currentAmps = (int) floor($chargerInfo->userPower / 230);
        if ($currentAmps > ($session->max_current ?? 0)) {
            $session->update(['max_current' => $currentAmps]);
        }

        $isThreePhase = ($latest->charger_current_l2 ?? 0) > 0.5 || ($latest->charger_current_l3 ?? 0) > 0.5;
        if ($session->is_three_phase !== $isThreePhase) {
            $session->update(['is_three_phase' => $isThreePhase]);
        }
    }

    private function closeSession(?ChargingSession $session, Metric $latest, ?SmsNotifier $sms = null): void
    {
        if (! $session) {
            return;
        }

        $energy = $session->computeEnergyKwh();
        $session->update([
            'ended_at' => now(),
            'energy_kwh' => $energy,
        ]);

        $sms?->send("Charge {$session->mode->label()} terminée ({$energy} kWh)");
    }
}
