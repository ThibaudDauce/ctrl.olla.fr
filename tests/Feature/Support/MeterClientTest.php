<?php

use App\Support\Meter\MeterClient;

beforeEach(function () {
    config(['services.meter.host' => '198.51.100.11']);
});

it('fetches meter info', function () {
    fakeMeterResponses([
        'total_active_power' => 2500.5,
        'active_p' => [800.0, 900.0, 800.5],
        'current' => [4.2, 4.5, 4.1],
    ]);

    $client = new MeterClient('198.51.100.11');
    $info = $client->info();

    expect($info->totalActivePower)->toBe(2500.5)
        ->and($info->activePowerPerPhase)->toEqualCanonicalizing([800.0, 900.0, 800.5])
        ->and($info->currentPerPhase)->toEqualCanonicalizing([4.2, 4.5, 4.1]);
});
