<?php

return [

    'off_peak_start' => env('OFF_PEAK_START', '22:15'),

    'off_peak_end' => env('OFF_PEAK_END', '05:55'),

    'load_shedding_enabled' => (bool) env('LOAD_SHEDDING_ENABLED', true),

    'phase_max_amps' => (int) env('PHASE_MAX_AMPS', 20),

    'min_charge_amps' => 6,

    'max_charge_amps' => 16,

];
