<?php

namespace App\Support\Envoy;

use Illuminate\Support\Facades\Http;

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

    public function production(): ProductionInfo
    {
        $response = Http::withoutVerifying()
            ->withToken($this->token)
            ->get("https://{$this->host}/api/v1/production");

        $data = $response->json();

        if (! is_array($data) || ! isset($data['wattsNow'], $data['wattHoursToday'])) {
            throw new \RuntimeException("Réponse Envoy invalide (HTTP {$response->status()}): {$response->body()}");
        }

        return new ProductionInfo(
            wattsNow: $data['wattsNow'],
            wattHoursToday: $data['wattHoursToday'],
        );
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
