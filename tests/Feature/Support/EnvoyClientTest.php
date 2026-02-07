<?php

use App\Support\Envoy\EnvoyClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.envoy.host' => '198.51.100.12',
        'services.envoy.token' => 'test-token',
    ]);
});

it('fetches production info', function () {
    fakeEnvoyResponses([
        'wattsNow' => 3200,
        'wattHoursToday' => 15000,
    ]);

    $client = new EnvoyClient('198.51.100.12', 'test-token');
    $production = $client->production();

    expect($production->wattsNow)->toBe(3200.0)
        ->and($production->wattHoursToday)->toBe(15000.0);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://198.51.100.12/api/v1/production'
            && $request->hasHeader('Authorization', 'Bearer test-token');
    });
});

it('fetches token from enphase cloud', function () {
    $payload = base64_encode(json_encode(['exp' => now()->addYear()->timestamp]));
    $fakeToken = "header.{$payload}.signature";

    Http::fake([
        'enlighten.enphaseenergy.com/login/login.json' => Http::response([
            'session_id' => 'test-session-id',
        ]),
        'entrez.enphaseenergy.com/tokens' => Http::response($fakeToken),
    ]);

    $result = EnvoyClient::fetchToken('test@example.com', 'password', 'SERIAL123');

    expect($result)->toHaveKeys(['token', 'expires_at'])
        ->and($result['token'])->toBe($fakeToken);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'login.json');
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'tokens')
            && $request['session_id'] === 'test-session-id'
            && $request['serial_num'] === 'SERIAL123';
    });
});
