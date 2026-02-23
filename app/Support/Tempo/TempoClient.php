<?php

namespace App\Support\Tempo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TempoClient
{
    private const API_URL = 'https://www.api-couleur-tempo.fr/api/jourTempo/today';

    public function today(): TempoDay
    {
        $nextTransition = now()->hour >= 6
            ? now()->addDay()->setTime(6, 0)
            : now()->setTime(6, 0);

        return Cache::remember('tempo_day_color', $nextTransition, function () {
            try {
                $response = Http::timeout(5)->get(self::API_URL);
                $data = $response->json();

                return TempoDay::from($data['codeJour']);
            } catch (\Throwable $e) {
                Log::warning("Tempo API failed, defaulting to Rouge: {$e->getMessage()}");

                return TempoDay::Rouge;
            }
        });
    }

    /**
     * Coût effectif par kWh pour une charge avec couverture solaire partielle.
     *
     * @param  float  $gridPowerForCharging  Puissance tirée du réseau pour la charge (W)
     * @param  float  $totalChargePower  Puissance totale de charge (W)
     */
    public function effectiveCostPerKwh(float $gridPowerForCharging, float $totalChargePower): float
    {
        if ($totalChargePower <= 0) {
            return 0;
        }

        return ($gridPowerForCharging / $totalChargePower) * $this->today()->hpRate();
    }

    public function costThreshold(): float
    {
        return (float) config('charging.tempo.rates.bleu_hc') * (1 + (float) config('charging.tempo.margin'));
    }
}
