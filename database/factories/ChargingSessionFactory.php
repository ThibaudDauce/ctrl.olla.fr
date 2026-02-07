<?php

namespace Database\Factories;

use App\Support\ChargingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChargingSession>
 */
class ChargingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'started_at' => now(),
            'mode' => ChargingMode::OffPeak,
            'energy_kwh' => 0,
            'max_current' => 16,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'ended_at' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'ended_at' => now(),
            'energy_kwh' => fake()->randomFloat(2, 1, 50),
        ]);
    }
}
