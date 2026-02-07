<?php

namespace Database\Seeders;

use App\Models\Metric;
use App\Support\Lektrico\ChargerState;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class MetricSeeder extends Seeder
{
    public function run(): void
    {
        $sunriseHour = 7;
        $sunsetHour = 19;
        $solarPeakHour = 13;
        $solarMaxWatts = 5500;

        $baseConsumption = function (CarbonInterface $time): float {
            $hour = $time->hour + $time->minute / 60;

            $base = match (true) {
                $hour < 6 => 350,
                $hour < 7 => 350 + ($hour - 6) * 600,
                $hour < 8 => 950 + ($hour - 7) * 200,
                $hour < 11 => 700 + sin(($hour - 8) / 3 * M_PI) * 200,
                $hour < 12 => 700 + ($hour - 11) * 800,
                $hour < 13.5 => 1500 + sin(($hour - 12) / 1.5 * M_PI) * 500,
                $hour < 17 => 600 + ($hour - 13.5) / 3.5 * 400,
                $hour < 18 => 1000 + ($hour - 17) * 1000,
                $hour < 21 => 2000 + sin(($hour - 18) / 3 * M_PI) * 800,
                $hour < 23 => 2000 - ($hour - 21) * 700,
                default => 600,
            };

            return max(200, $base + rand(-80, 80));
        };

        $solarPower = function (CarbonInterface $time) use ($sunriseHour, $sunsetHour, $solarPeakHour, $solarMaxWatts): float {
            $hour = $time->hour + $time->minute / 60;

            if ($hour < $sunriseHour || $hour > $sunsetHour) {
                return 0;
            }

            if ($hour <= $solarPeakHour) {
                $progress = ($hour - $sunriseHour) / ($solarPeakHour - $sunriseHour);
            } else {
                $progress = 1 - (($hour - $solarPeakHour) / ($sunsetHour - $solarPeakHour));
            }

            // Passage nuageux entre 14h et 15h30
            $cloud = 1.0;
            if ($hour >= 14 && $hour <= 15.5) {
                $cloud = 0.35 + 0.25 * sin(($hour - 14) / 1.5 * M_PI * 3);
            }

            $jitter = rand(95, 105) / 100;

            return max(0, round($solarMaxWatts * pow($progress, 1.2) * $cloud * $jitter));
        };

        $time = today()->startOfDay();
        $end = today()->endOfDay();

        while ($time->lte($end)) {
            $solar = $solarPower($time);
            $consumption = $baseConsumption($time);
            $meterTotal = $consumption - $solar;

            $l1Ratio = rand(30, 40) / 100;
            $l2Ratio = rand(28, 38) / 100;
            $l3Ratio = 1 - $l1Ratio - $l2Ratio;

            Metric::create([
                'recorded_at' => $time,
                'meter_power_total' => round($meterTotal, 1),
                'meter_power_l1' => round($meterTotal * $l1Ratio, 1),
                'meter_power_l2' => round($meterTotal * $l2Ratio, 1),
                'meter_power_l3' => round($meterTotal * $l3Ratio, 1),
                'meter_current_l1' => round(abs($meterTotal * $l1Ratio) / 230, 2),
                'meter_current_l2' => round(abs($meterTotal * $l2Ratio) / 230, 2),
                'meter_current_l3' => round(abs($meterTotal * $l3Ratio) / 230, 2),
                'solar_power' => $solar,
                'charger_state' => ChargerState::Available,
                'charger_power' => 0,
                'charger_current' => 0,
                'charger_current_l1' => 0,
                'charger_current_l2' => 0,
                'charger_current_l3' => 0,
                'created_at' => $time,
            ]);

            $time = $time->addMinute();
        }
    }
}
