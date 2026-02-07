<?php

namespace App\Support\Envoy;

class ProductionInfo
{
    public function __construct(
        public float $wattsNow,
        public float $wattHoursToday,
    ) {}
}
