<?php

namespace App\Console\Commands;

use App\Models\Metric;
use App\Support\SmsNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HealthcheckCommand extends Command
{
    protected $signature = 'app:healthcheck';

    protected $description = 'Check system health and alert via SMS';

    public function handle(SmsNotifier $sms): void
    {
        $this->checkMetrics($sms);
        $this->checkEnvoyToken($sms);
    }

    private function checkMetrics(SmsNotifier $sms): void
    {
        $latest = Metric::query()->latest('recorded_at')->first();

        if (! $latest || $latest->recorded_at->lt(now()->subMinutes(3))) {
            $sms->send('Healthcheck : pas de métrique depuis plus de 3 minutes', 'healthcheck_metrics');
        }
    }

    private function checkEnvoyToken(SmsNotifier $sms): void
    {
        $expiresAt = config('services.envoy.token_expires_at');

        if (! $expiresAt) {
            return;
        }

        $expiry = Carbon::parse($expiresAt);

        if ($expiry->lt(now()->addDays(7))) {
            $sms->send("Healthcheck : token Envoy expire le {$expiry->format('d/m/Y')}", 'healthcheck_envoy_token');
        }
    }
}
