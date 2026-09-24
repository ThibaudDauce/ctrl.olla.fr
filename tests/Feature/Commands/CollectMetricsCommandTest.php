<?php

use App\Models\Metric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.lektrico.host' => '198.51.100.10',
        'services.meter.host' => '198.51.100.11',
        'services.envoy.host' => '198.51.100.12',
        'services.envoy.token' => 'test-token',
    ]);
});

it('creates a metric from all devices', function () {
    fakeAllDevices([
        'meter' => [
            'total_active_power' => 2500.0,
            'active_p' => [800.0, 900.0, 800.0],
            'current' => [4.0, 4.5, 4.0],
        ],
        'envoy_watts' => 3000,
        'charger_info' => [
            'extended_charger_state' => 'A',
            'instant_power' => 0,
            'charging_time' => 0,
            'session_energy' => 0,
            'currents' => [0, 0, 0],
            'voltages' => [230, 230, 230],
        ],
    ]);

    $this->artisan('app:collect-metrics')->assertSuccessful();

    $metric = Metric::query()->first();

    expect($metric)->not->toBeNull()
        ->and($metric->meter_power_total)->toBe(2500.0)
        ->and($metric->solar_power)->toBe(3000.0)
        ->and($metric->charger_state->value)->toBe('A');
});

it('creates a partial metric when a device fails', function () {
    \Illuminate\Support\Facades\Http::fake([
        'http://198.51.100.10/rpc/charger_info.get' => \Illuminate\Support\Facades\Http::response(null, 500),
        'http://198.51.100.10/rpc/app_config.get' => \Illuminate\Support\Facades\Http::response(null, 500),
        'http://198.51.100.10/rpc' => \Illuminate\Support\Facades\Http::response(['result' => true]),
        'http://198.51.100.11/rpc/Meter_info.Get' => \Illuminate\Support\Facades\Http::response([
            'total_active_power' => 1500.0,
            'active_p' => [500.0, 500.0, 500.0],
            'current' => [3.0, 3.0, 3.0],
        ]),
        'https://198.51.100.12/production.json' => \Illuminate\Support\Facades\Http::response(envoyProductionPayload(2500)),
    ]);

    $this->artisan('app:collect-metrics')->assertSuccessful();

    $metric = Metric::query()->first();

    expect($metric)->not->toBeNull()
        ->and($metric->meter_power_total)->toBe(1500.0)
        ->and($metric->solar_power)->toBe(2500.0)
        ->and($metric->charger_state)->toBeNull();
});

it('still records the other devices when the envoy fails and the sms cannot be sent', function () {
    Sleep::fake();

    config([
        'services.free_sms.user' => 'user',
        'services.free_sms.key' => 'key',
    ]);

    Http::fake([
        'http://198.51.100.10/rpc/charger_info.get' => Http::response([
            'extended_charger_state' => 'A',
            'instant_power' => 0,
            'charging_time' => 0,
            'session_energy' => 0,
            'currents' => [0, 0, 0],
            'voltages' => [230, 230, 230],
        ]),
        'http://198.51.100.10/rpc/dynamic_current.get' => Http::response(['dynamic_current' => 16]),
        'http://198.51.100.10/rpc/app_config.get' => Http::response(['user_current' => 32, 'user_power' => 7360]),
        'http://198.51.100.11/rpc/Meter_info.Get' => Http::response([
            'total_active_power' => 1500.0,
            'active_p' => [500.0, 500.0, 500.0],
            'current' => [3.0, 3.0, 3.0],
        ]),
        'https://198.51.100.12/production.json' => Http::failedConnection('Connection refused'),
        'https://smsapi.free-mobile.fr/*' => Http::failedConnection('Connection refused'),
    ]);

    // Deux collectes : la seconde erreur déclenche réellement l'envoi du SMS, qui échoue.
    $this->artisan('app:collect-metrics')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('app:collect-metrics')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'smsapi.free-mobile.fr'));

    $metric = Metric::query()->latest('recorded_at')->first();

    expect($metric)->not->toBeNull()
        ->and($metric->solar_power)->toBeNull()
        ->and($metric->meter_power_total)->toBe(1500.0)
        ->and($metric->charger_state->value)->toBe('A');
});

it('does not send an SMS on an isolated device error', function () {
    config([
        'services.free_sms.user' => 'user',
        'services.free_sms.key' => 'key',
    ]);

    Http::fake([
        'http://198.51.100.10/rpc/charger_info.get' => Http::response([
            'extended_charger_state' => 'A',
            'instant_power' => 0,
            'charging_time' => 0,
            'session_energy' => 0,
            'currents' => [0, 0, 0],
            'voltages' => [230, 230, 230],
        ]),
        'http://198.51.100.10/rpc/app_config.get' => Http::response(['user_current' => 32, 'user_power' => 7360]),
        'http://198.51.100.11/rpc/Meter_info.Get' => Http::response([
            'total_active_power' => 1500.0,
            'active_p' => [500.0, 500.0, 500.0],
            'current' => [3.0, 3.0, 3.0],
        ]),
        'https://198.51.100.12/production.json' => Http::response(null, 500),
        'https://smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $this->artisan('app:collect-metrics')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'smsapi.free-mobile.fr'));
});

it('skips envoy when no token is configured', function () {
    config(['services.envoy.token' => null]);

    fakeAllDevices();

    $this->artisan('app:collect-metrics')->assertSuccessful();

    $metric = Metric::query()->first();
    expect($metric->solar_power)->toBeNull();
});
