<?php

namespace App\Support\Tempo;

enum TempoDay: int
{
    case Bleu = 1;
    case Blanc = 2;
    case Rouge = 3;

    public function hpRate(): float
    {
        return match ($this) {
            self::Bleu => (float) config('charging.tempo.rates.bleu_hp'),
            self::Blanc => (float) config('charging.tempo.rates.blanc_hp'),
            self::Rouge => (float) config('charging.tempo.rates.rouge_hp'),
        };
    }
}
