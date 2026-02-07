<?php

use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerState;

it('has a label for every ChargerState', function () {
    foreach (ChargerState::cases() as $state) {
        expect($state->label())->toBeString()->not->toBeEmpty();
    }
});

it('has a label for every ChargingMode', function () {
    foreach (ChargingMode::cases() as $mode) {
        expect($mode->label())->toBeString()->not->toBeEmpty();
    }
});

it('ChargerState isConnectable returns bool for all cases', function () {
    foreach (ChargerState::cases() as $state) {
        expect($state->isConnectable())->toBeBool();
    }
});

it('ChargerState isCharging returns bool for all cases', function () {
    foreach (ChargerState::cases() as $state) {
        expect($state->isCharging())->toBeBool();
    }
});
