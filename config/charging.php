<?php

return [

    'off_peak_start' => env('OFF_PEAK_START', '22:15'),

    'off_peak_end' => env('OFF_PEAK_END', '05:55'),

    'load_shedding_enabled' => (bool) env('LOAD_SHEDDING_ENABLED', true),

    'phase_max_amps' => (int) env('PHASE_MAX_AMPS', 20),

    'min_charge_amps' => 6,

    'max_charge_amps' => 16,

    'solar_margin_watts' => (int) env('SOLAR_MARGIN_WATTS', 230),

    'tempo' => [
        'margin' => (float) env('TEMPO_MARGIN', 0.20),
        'rates' => [
            'bleu_hc' => (float) env('TEMPO_BLEU_HC', 0.1056),
            'bleu_hp' => (float) env('TEMPO_BLEU_HP', 0.1369),
            'blanc_hp' => (float) env('TEMPO_BLANC_HP', 0.1553),
            'rouge_hp' => (float) env('TEMPO_ROUGE_HP', 0.7324),
        ],
    ],

];
