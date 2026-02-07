<?php

namespace App\Support;

enum ChargingMode: string
{
    case OffPeak = 'off_peak';
    case Solar = 'solar';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::OffPeak => 'Heures creuses',
            self::Solar => 'Solaire',
            self::Manual => 'Manuel',
        };
    }
}
