<?php

namespace App\Support\Envoy;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EnvoyClient
{
    public function __construct(
        public string $host,
        public string $token,
    ) {}

    public static function make(): static
    {
        return new static(config('services.envoy.host'), config('services.envoy.token'));
    }

    /**
     * Puissance produite instantanée, en watts.
     */
    public function productionWatts(): float
    {
        // Pas /api/v1/production : depuis le firmware D8.3.5169, cet endpoint renvoie
        // wattsNow: -1. Sur un Envoy-S metered, la production réelle est celle du
        // compteur de production (bloc eim), pas celle remontée par les onduleurs.
        // L'Envoy répond par ailleurs 503 « Resource busy » ou refuse la connexion
        // quand il est occupé : on retente avant d'abandonner la minute.
        $response = Http::withoutVerifying()
            ->withToken($this->token)
            ->timeout(5)
            ->retry(3, 3000, throw: false)
            ->get("https://{$this->host}/production.json");

        $meter = collect($response->json('production'))
            ->first(fn ($block) => is_array($block) && ($block['measurementType'] ?? null) === 'production');

        if (! isset($meter['wNow'])) {
            throw new RuntimeException("Réponse Envoy invalide (HTTP {$response->status()}): {$response->body()}");
        }

        return (float) $meter['wNow'];
    }

    public function serialNumber(): string
    {
        $xml = Http::withoutVerifying()
            ->get("http://{$this->host}/info.xml")
            ->body();

        $info = simplexml_load_string($xml);

        return (string) $info->device->sn;
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public static function fetchToken(string $email, string $password, string $serial): array
    {
        $login = Http::asForm()
            ->post('https://enlighten.enphaseenergy.com/login/login.json', [
                'user[email]' => $email,
                'user[password]' => $password,
            ])
            ->json();

        $tokenResponse = Http::post('https://entrez.enphaseenergy.com/tokens', [
            'session_id' => $login['session_id'],
            'serial_num' => $serial,
            'username' => $email,
        ]);

        $token = $tokenResponse->body();

        $payload = json_decode(base64_decode(explode('.', $token)[1]), true);

        return [
            'token' => $token,
            'expires_at' => date('Y-m-d H:i:s', $payload['exp']),
        ];
    }
}
