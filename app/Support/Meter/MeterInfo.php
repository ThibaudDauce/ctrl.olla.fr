<?php

namespace App\Support\Meter;

class MeterInfo
{
    /**
     * @param  float[]  $activePowerPerPhase
     * @param  float[]  $currentPerPhase
     */
    public function __construct(
        public float $totalActivePower,
        public array $activePowerPerPhase,
        public array $currentPerPhase,
    ) {}
}
