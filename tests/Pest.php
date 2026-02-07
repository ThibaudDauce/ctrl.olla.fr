<?php

use App\Support\Lektrico\ChargerState;
use Illuminate\Support\Facades\Http;

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

function fakeLektricoResponses(array $overrides = []): void
{
    $host = config('services.lektrico.host');

    $chargerInfo = array_merge([
        'extended_charger_state' => ChargerState::Available->value,
        'instant_power' => 0,
        'charging_time' => 0,
        'session_energy' => 0,
        'currents' => [0, 0, 0],
        'voltages' => [230, 230, 230],
    ], $overrides['charger_info'] ?? []);

    $dynamicCurrent = array_merge([
        'dynamic_current' => 16,
    ], $overrides['dynamic_current'] ?? []);

    $appConfig = array_merge([
        'user_current' => 32,
        'user_power' => 7360,
    ], $overrides['app_config'] ?? []);

    Http::fake([
        "http://{$host}/rpc/charger_info.get" => Http::response($chargerInfo),
        "http://{$host}/rpc/dynamic_current.get" => Http::response($dynamicCurrent),
        "http://{$host}/rpc/app_config.get" => Http::response($appConfig),
        "http://{$host}/rpc" => Http::response(['result' => true]),
    ]);
}

function fakeMeterResponses(array $overrides = []): void
{
    $host = config('services.meter.host');

    $data = array_merge([
        'total_active_power' => 1500.0,
        'active_p' => [500.0, 500.0, 500.0],
        'current' => [3.0, 3.0, 3.0],
    ], $overrides);

    Http::fake([
        "http://{$host}/rpc/Meter_info.Get" => Http::response($data),
    ]);
}

function fakeEnvoyResponses(array $overrides = []): void
{
    $host = config('services.envoy.host');

    $data = array_merge([
        'wattsNow' => 2500,
        'wattHoursToday' => 12000,
    ], $overrides);

    Http::fake([
        "https://{$host}/api/v1/production" => Http::response($data),
    ]);
}

function fakeAllDevices(array $overrides = []): void
{
    $host_lektrico = config('services.lektrico.host');
    $host_meter = config('services.meter.host');
    $host_envoy = config('services.envoy.host');

    $chargerInfo = array_merge([
        'extended_charger_state' => ChargerState::Available->value,
        'instant_power' => 0,
        'charging_time' => 0,
        'session_energy' => 0,
        'currents' => [0, 0, 0],
        'voltages' => [230, 230, 230],
    ], $overrides['charger_info'] ?? []);

    $dynamicCurrent = array_merge([
        'dynamic_current' => 16,
    ], $overrides['dynamic_current'] ?? []);

    $appConfig = array_merge([
        'user_current' => 32,
        'user_power' => 7360,
    ], $overrides['app_config'] ?? []);

    $meter = array_merge([
        'total_active_power' => 1500.0,
        'active_p' => [500.0, 500.0, 500.0],
        'current' => [3.0, 3.0, 3.0],
    ], $overrides['meter'] ?? []);

    $envoy = array_merge([
        'wattsNow' => 2500,
        'wattHoursToday' => 12000,
    ], $overrides['envoy'] ?? []);

    Http::fake([
        "http://{$host_lektrico}/rpc/charger_info.get" => Http::response($chargerInfo),
        "http://{$host_lektrico}/rpc/dynamic_current.get" => Http::response($dynamicCurrent),
        "http://{$host_lektrico}/rpc/app_config.get" => Http::response($appConfig),
        "http://{$host_lektrico}/rpc" => Http::response(['result' => true]),
        "http://{$host_meter}/rpc/Meter_info.Get" => Http::response($meter),
        "https://{$host_envoy}/api/v1/production" => Http::response($envoy),
    ]);
}
