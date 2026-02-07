<?php

namespace Database\Seeders;

use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerState;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class MetricSeeder extends Seeder
{
    public function run(): void
    {
        $start = today()->startOfDay();
        $now = now();

        $sunriseHour = 7;
        $sunsetHour = 19;
        $solarPeakHour = 13;
        $solarMaxWatts = 4500;

        // Base household consumption varies by time of day
        $baseConsumption = function (int $hour): float {
            return match (true) {
                $hour >= 0 && $hour < 6 => rand(300, 600),
                $hour >= 6 && $hour < 8 => rand(800, 1500),
                $hour >= 8 && $hour < 12 => rand(500, 1000),
                $hour >= 12 && $hour < 14 => rand(1200, 2000),
                $hour >= 14 && $hour < 18 => rand(600, 1000),
                $hour >= 18 && $hour < 22 => rand(1500, 2500),
                default => rand(400, 800),
            };
        };

        $solarPower = function (CarbonInterface $time) use ($sunriseHour, $sunsetHour, $solarPeakHour, $solarMaxWatts): float {
            $hour = $time->hour + $time->minute / 60;

            if ($hour < $sunriseHour || $hour > $sunsetHour) {
                return 0;
            }

            $progress = ($hour - $sunriseHour) / ($solarPeakHour - $sunriseHour);
            if ($hour > $solarPeakHour) {
                $progress = 1 - (($hour - $solarPeakHour) / ($sunsetHour - $solarPeakHour));
            }

            $cloud = rand(70, 100) / 100;

            return max(0, round($solarMaxWatts * $progress * $cloud));
        };

        // Off-peak charging session: 22:15 yesterday → 05:55 today
        $offPeakSession = ChargingSession::create([
            'started_at' => today()->subDay()->setTime(22, 15),
            'ended_at' => today()->setTime(5, 55),
            'mode' => ChargingMode::OffPeak,
            'energy_kwh' => 28.3,
            'is_three_phase' => false,
            'max_current' => 32,
        ]);

        // Solar charging session: 10:30 → 15:45 today
        $solarSession = null;
        if ($now->hour >= 16) {
            $solarSession = ChargingSession::create([
                'started_at' => today()->setTime(10, 30),
                'ended_at' => today()->setTime(15, 45),
                'mode' => ChargingMode::Solar,
                'energy_kwh' => 12.1,
                'is_three_phase' => false,
                'max_current' => 16,
            ]);
        } elseif ($now->hour >= 11) {
            $solarSession = ChargingSession::create([
                'started_at' => today()->setTime(10, 30),
                'ended_at' => null,
                'mode' => ChargingMode::Solar,
                'energy_kwh' => 0,
                'is_three_phase' => false,
                'max_current' => 14,
            ]);
        }

        $time = $start;
        while ($time->lte($now)) {
            $hour = $time->hour;
            $solar = $solarPower($time);
            $consumption = $baseConsumption($hour);

            $isOffPeakCharging = ($hour >= 22 && $hour <= 23) || ($hour >= 0 && $hour < 6);
            $isSolarCharging = $solarSession
                && $time->gte($solarSession->started_at)
                && ($solarSession->ended_at === null || $time->lte($solarSession->ended_at));

            $chargerPower = 0;
            $chargerCurrent = 0;
            $chargerState = ChargerState::Available;

            if ($isOffPeakCharging && $time->lt(today()->setTime(5, 55))) {
                $chargerPower = 7360;
                $chargerCurrent = 32;
                $chargerState = ChargerState::Charging;
            } elseif ($isSolarCharging) {
                $solarAmps = max(6, min(16, (int) floor($solar / 230)));
                $chargerPower = $solarAmps * 230;
                $chargerCurrent = $solarAmps;
                $chargerState = ChargerState::Charging;
            } elseif ($hour >= 8 && $hour < 22) {
                $chargerState = ChargerState::NeedAuth;
            }

            $meterTotal = $consumption + $chargerPower - $solar;

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
                'charger_state' => $chargerState,
                'charger_power' => $chargerPower,
                'charger_current' => $chargerCurrent,
                'charger_current_l1' => $chargerCurrent,
                'charger_current_l2' => 0,
                'charger_current_l3' => 0,
                'created_at' => $time,
            ]);

            $time = $time->addMinute();
        }
    }
}
