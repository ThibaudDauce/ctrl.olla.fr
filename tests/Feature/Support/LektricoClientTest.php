<?php

use App\Support\Lektrico\ChargerState;
use App\Support\Lektrico\LektricoClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.lektrico.host' => '198.51.100.10']);
});

it('fetches charger info', function () {
    fakeLektricoResponses([
        'charger_info' => [
            'extended_charger_state' => 'C',
            'instant_power' => 3680,
            'charging_time' => 3600,
            'session_energy' => 3.5,
            'currents' => [16.1, 0, 0],
            'voltages' => [230, 231, 229],
        ],
        'dynamic_current' => [
            'dynamic_current' => 16,
        ],
    ]);

    $client = LektricoClient::make();
    $info = $client->info();

    expect($info->state)->toBe(ChargerState::Charging)
        ->and($info->instantPower)->toBe(3680.0)
        ->and($info->dynamicCurrent)->toBe(16)
        ->and($info->chargingTime)->toBe(3600)
        ->and($info->sessionEnergy)->toBe(3.5)
        ->and($info->currents)->toBe([16.1, 0, 0])
        ->and($info->voltages)->toBe([230, 231, 229]);
});

it('sends start command', function () {
    fakeLektricoResponses();

    $client = LektricoClient::make();
    $client->start();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://198.51.100.10/rpc'
            && $request['method'] === 'charge.start'
            && $request['src'] === 'ctrl'
            && $request['params']['tag'] === 'ctrl';
    });
});

it('sends stop command', function () {
    fakeLektricoResponses();

    $client = LektricoClient::make();
    $client->stop();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://198.51.100.10/rpc'
            && $request['method'] === 'charge.stop';
    });
});

it('sends set dynamic current command', function () {
    fakeLektricoResponses();

    $client = LektricoClient::make();
    $client->setDynamicCurrent(24);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://198.51.100.10/rpc'
            && $request['method'] === 'dynamic_current.set'
            && $request['params']['dynamic_current'] === 24;
    });
});
