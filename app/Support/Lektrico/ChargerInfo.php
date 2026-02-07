<?php

namespace App\Support\Lektrico;

class ChargerInfo
{
    /**
     * @param  float[]  $currents
     * @param  float[]  $voltages
     */
    public function __construct(
        public ChargerState $state,
        public float $instantPower,
        public int $dynamicCurrent,
        public int $chargingTime,
        public float $sessionEnergy,
        public array $currents,
        public array $voltages,
    ) {}
}
