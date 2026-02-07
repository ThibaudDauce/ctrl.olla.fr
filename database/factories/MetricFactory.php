<?php

namespace Database\Factories;

use App\Support\Lektrico\ChargerState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Metric>
 */
class MetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recorded_at' => now(),
            'meter_power_total' => fake()->randomFloat(1, 500, 5000),
            'meter_power_l1' => fake()->randomFloat(1, 100, 2000),
            'meter_power_l2' => fake()->randomFloat(1, 100, 2000),
            'meter_power_l3' => fake()->randomFloat(1, 100, 2000),
            'meter_current_l1' => fake()->randomFloat(2, 1, 15),
            'meter_current_l2' => fake()->randomFloat(2, 1, 15),
            'meter_current_l3' => fake()->randomFloat(2, 1, 15),
            'solar_power' => fake()->randomFloat(1, 0, 3000),
            'charger_state' => ChargerState::Available,
            'charger_power' => 0,
            'charger_current' => 0,
            'charger_current_l1' => 0,
            'charger_current_l2' => 0,
            'charger_current_l3' => 0,
            'created_at' => now(),
        ];
    }

    public function charging(int $amps = 16): static
    {
        $powerPerPhase = $amps * 230;

        return $this->state(fn () => [
            'charger_state' => ChargerState::Charging,
            'charger_power' => $powerPerPhase,
            'charger_current' => $amps,
            'charger_current_l1' => $amps,
            'charger_current_l2' => 0,
            'charger_current_l3' => 0,
        ]);
    }

    public function withSolarSurplus(float $surplus = 2000): static
    {
        return $this->state(fn () => [
            'meter_power_total' => -$surplus,
            'solar_power' => $surplus + 500,
        ]);
    }
}
