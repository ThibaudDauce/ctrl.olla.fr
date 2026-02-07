<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\LektricoClient;
use App\Support\SmsNotifier;
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
            if ($this->handleLoadShedding($latest, $charger, $session, $sms)) {
                return;
            }

            $this->updateSession($latest, $session, $charger);
        }

        $this->handleOffPeak($latest, $charger, $session, $sms);
        $this->handleSolar($latest, $charger, $session, $sms);
    }

    private function handleLoadShedding(Metric $latest, LektricoClient $charger, ?ChargingSession $session, SmsNotifier $sms): bool
    {
        $maxAmps = config('charging.phase_max_amps');
        $overloaded = $latest->meter_current_l1 > $maxAmps
            || $latest->meter_current_l2 > $maxAmps
            || $latest->meter_current_l3 > $maxAmps;

        if (! $overloaded) {
            return false;
        }

        $currentAmps = $latest->charger_current;
        $minAmps = config('charging.min_charge_amps');

        if ($currentAmps <= $minAmps) {
            Log::warning('Load shedding: stopping charge, already at minimum amps');
            $charger->stop();
            $this->closeSession($session, $latest, $sms);
            $sms->send('Délestage : charge arrêtée, dépassement ampérage', 'load_shedding');

            return true;
        }

        $newAmps = $currentAmps - 1;
        Log::info("Load shedding: reducing from {$currentAmps}A to {$newAmps}A");
        $charger->setDynamicCurrent($newAmps);
        $sms->send("Délestage : {$currentAmps}A → {$newAmps}A");

        return true;
    }

    private function handleOffPeak(Metric $latest, LektricoClient $charger, ?ChargingSession &$session, SmsNotifier $sms): void
    {
        $wasInOffPeak = $this->isInOffPeakWindow(now()->subMinute());
        $isInOffPeak = $this->isInOffPeakWindow(now());

        // Entering off-peak
        if ($isInOffPeak && ! $wasInOffPeak && $latest->charger_state->isConnectable()) {
            $amps = config('charging.max_charge_amps');
            Log::info('Off-peak: starting charge');
            $charger->setDynamicCurrent($amps);
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
        if (! $isInOffPeak && $wasInOffPeak && $latest->charger_state->isCharging()) {
            if ($session?->mode === ChargingMode::Solar) {
                return;
            }

            Log::info('Off-peak ended: stopping charge');
            $charger->stop();
            $this->closeSession($session, $latest, $sms);
        }
    }

    private function handleSolar(Metric $latest, LektricoClient $charger, ?ChargingSession &$session, SmsNotifier $sms): void
    {
        if ($this->isInOffPeakWindow(now())) {
            return;
        }

        $recentMetrics = Metric::query()
            ->latest('recorded_at')
            ->take(3)
            ->get();

        $isCharging = $latest->charger_state->isCharging();

        if (! $isCharging && $latest->charger_state->isConnectable()) {
            $minAmps = config('charging.min_charge_amps');
            $minSurplus = $minAmps * 230;

            $allHaveSurplus = $recentMetrics->count() >= 3
                && $recentMetrics->every(fn (Metric $m) => $m->meter_power_total !== null && $m->meter_power_total < -$minSurplus);

            if ($allHaveSurplus) {
                $avgSurplus = abs($recentMetrics->avg('meter_power_total'));
                $amps = min(config('charging.max_charge_amps'), max($minAmps, (int) floor($avgSurplus / 230)));

                Log::info("Solar: starting charge at {$amps}A");
                $charger->setDynamicCurrent($amps);
                $charger->start();
                $session = ChargingSession::query()->create([
                    'started_at' => now(),
                    'mode' => ChargingMode::Solar,
                    'max_current' => $amps,
                ]);
                $sms->send("Charge solaire démarrée à {$amps}A");
            }

            return;
        }

        if ($isCharging && $session?->mode === ChargingMode::Solar) {
            $positiveCount = $recentMetrics
                ->filter(fn (Metric $m) => $m->meter_power_total !== null && $m->meter_power_total > 0)
                ->count();

            if ($positiveCount >= 2) {
                Log::info('Solar: stopping charge, consuming from grid');
                $charger->stop();
                $this->closeSession($session, $latest, $sms);

                return;
            }

            if ($recentMetrics->count() >= 3) {
                $avgPower = $recentMetrics->avg('meter_power_total');
                // Negative avgPower = surplus → increase amps, positive = consuming → decrease
                $targetAmps = $latest->charger_current + (int) floor(-$avgPower / 230);
                $targetAmps = max(config('charging.min_charge_amps'), min(config('charging.max_charge_amps'), $targetAmps));

                $currentAmps = $latest->charger_current;
                if ($targetAmps !== $currentAmps) {
                    $newAmps = $targetAmps > $currentAmps ? $currentAmps + 1 : $currentAmps - 1;
                    Log::info("Solar: adjusting from {$currentAmps}A to {$newAmps}A");
                    $charger->setDynamicCurrent($newAmps);
                    $sms->send("Charge solaire : {$currentAmps}A → {$newAmps}A");

                    if ($newAmps > ($session->max_current ?? 0)) {
                        $session->update(['max_current' => $newAmps]);
                    }
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

    private function updateSession(Metric $latest, ?ChargingSession $session, LektricoClient $charger): void
    {
        if (! $session) {
            return;
        }

        if ($latest->charger_current > ($session->max_current ?? 0)) {
            $session->update(['max_current' => $latest->charger_current]);
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
