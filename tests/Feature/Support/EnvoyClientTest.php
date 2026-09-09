<?php

use App\Support\Envoy\EnvoyClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.envoy.host' => '198.51.100.12',
        'services.envoy.token' => 'test-token',
    ]);
});

it('reads the production meter, not the inverters aggregate', function () {
    fakeEnvoyResponses(3200);

    $client = new EnvoyClient('198.51.100.12', 'test-token');

    // Le bloc `inverters` du fake vaut 3 W : le lire donnerait la valeur périmée.
    expect($client->productionWatts())->toBe(3200.0);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://198.51.100.12/production.json'
            && $request->hasHeader('Authorization', 'Bearer test-token');
    });
});

it('retries when the envoy answers 503 resource busy', function () {
    Sleep::fake();

    Http::fake([
        'https://198.51.100.12/production.json' => Http::sequence()
            ->push(['status' => 503, 'info' => 'Resource busy, please retry after 30 seconds'], 503)
            ->push(envoyProductionPayload(1800)),
    ]);

    $client = new EnvoyClient('198.51.100.12', 'test-token');

    expect($client->productionWatts())->toBe(1800.0);

    Http::assertSentCount(2);
});

it('retries when the envoy refuses the connection', function () {
    Sleep::fake();

    Http::fake([
        'https://198.51.100.12/production.json' => Http::sequence()
            ->pushFailedConnection('Connection refused')
            ->push(envoyProductionPayload(1800)),
    ]);

    $client = new EnvoyClient('198.51.100.12', 'test-token');

    expect($client->productionWatts())->toBe(1800.0);
});

it('fails loudly when the envoy never returns a production meter', function () {
    Sleep::fake();

    Http::fake([
        'https://198.51.100.12/production.json' => Http::response(['status' => 503, 'info' => 'Resource busy, please retry after 30 seconds'], 503),
    ]);

    $client = new EnvoyClient('198.51.100.12', 'test-token');

    expect(fn () => $client->productionWatts())
        ->toThrow(RuntimeException::class, 'Réponse Envoy invalide (HTTP 503)');
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
