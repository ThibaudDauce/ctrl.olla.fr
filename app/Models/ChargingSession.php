<?php

namespace App\Models;

use App\Support\ChargingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargingSession extends Model
{
    /** @use HasFactory<\Database\Factories\ChargingSessionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'mode' => ChargingMode::class,
            'is_three_phase' => 'boolean',
        ];
    }
}
