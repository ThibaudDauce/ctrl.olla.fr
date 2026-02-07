<?php

namespace App\Console\Commands;

use App\Models\Metric;
use App\Support\Envoy\EnvoyClient;
use App\Support\Lektrico\LektricoClient;
use App\Support\Meter\MeterClient;
use App\Support\SmsNotifier;
use Illuminate\Console\Command;
use Throwable;

class CollectMetricsCommand extends Command
{
    protected $signature = 'app:collect-metrics';

    protected $description = 'Collect metrics from all devices';

    public function handle(SmsNotifier $sms): void
    {
        $data = [
            'recorded_at' => now()->startOfMinute(),
            'created_at' => now(),
        ];

        $this->collectMeter($data, $sms);
        $this->collectSolar($data, $sms);
        $this->collectCharger($data, $sms);

        Metric::query()->create($data);
    }

    private function collectMeter(array &$data, SmsNotifier $sms): void
    {
        try {
            $info = MeterClient::make()->info();

            $data['meter_power_total'] = $info->totalActivePower;
            $data['meter_power_l1'] = $info->activePowerPerPhase[0] ?? null;
            $data['meter_power_l2'] = $info->activePowerPerPhase[1] ?? null;
            $data['meter_power_l3'] = $info->activePowerPerPhase[2] ?? null;
            $data['meter_current_l1'] = $info->currentPerPhase[0] ?? null;
            $data['meter_current_l2'] = $info->currentPerPhase[1] ?? null;
            $data['meter_current_l3'] = $info->currentPerPhase[2] ?? null;
        } catch (Throwable $e) {
            report($e);
            $sms->send("Erreur meter: {$e->getMessage()}", 'device_meter');
        }
    }

    private function collectSolar(array &$data, SmsNotifier $sms): void
    {
        try {
            $token = config('services.envoy.token');
            if (! $token) {
                return;
            }

            $production = EnvoyClient::make()->production();

            $data['solar_power'] = $production->wattsNow;
        } catch (Throwable $e) {
            report($e);
            $sms->send("Erreur Envoy: {$e->getMessage()}", 'device_envoy');
        }
    }

    private function collectCharger(array &$data, SmsNotifier $sms): void
    {
        try {
            $info = LektricoClient::make()->info();

            $data['charger_state'] = $info->state;
            $data['charger_power'] = $info->instantPower;
            $data['charger_current_l1'] = $info->currents[0] ?? null;
            $data['charger_current_l2'] = $info->currents[1] ?? null;
            $data['charger_current_l3'] = $info->currents[2] ?? null;
        } catch (Throwable $e) {
            report($e);
            $sms->send("Erreur borne: {$e->getMessage()}", 'device_charger');
        }
    }
}
