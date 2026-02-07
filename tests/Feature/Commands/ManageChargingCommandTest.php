<?php

use App\Console\Commands\ManageChargingCommand;
use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerState;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.lektrico.host' => '198.51.100.10',
        'services.meter.host' => '198.51.100.11',
        'charging.off_peak_start' => '22:15',
        'charging.off_peak_end' => '05:55',
        'charging.phase_max_amps' => 20,
        'charging.min_charge_amps' => 6,
        'charging.max_charge_amps' => 32,
    ]);

    fakeLektricoResponses();
});

it('skips when no metrics exist', function () {
    $this->artisan('app:manage-charging')->assertSuccessful();

    expect(ChargingSession::count())->toBe(0);
    Http::assertNothingSent();
});

it('skips when meter data is missing', function () {
    Metric::factory()->create([
        'meter_power_total' => null,
        'charger_state' => ChargerState::Available,
    ]);

    $this->artisan('app:manage-charging')->assertSuccessful();
    Http::assertNothingSent();
});

it('skips when charger state is missing', function () {
    Metric::factory()->create([
        'meter_power_total' => 1500,
        'charger_state' => null,
    ]);

    $this->artisan('app:manage-charging')->assertSuccessful();
    Http::assertNothingSent();
});

describe('off-peak', function () {
    it('starts charging when entering off-peak window', function () {
        $this->travelTo(now()->setTime(22, 15));

        Metric::factory()->create([
            'charger_state' => ChargerState::NeedAuth,
            'meter_current_l1' => 5,
            'meter_current_l2' => 5,
            'meter_current_l3' => 5,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session = ChargingSession::query()->first();
        expect($session)->not->toBeNull()
            ->and($session->mode)->toBe(ChargingMode::OffPeak)
            ->and($session->ended_at)->toBeNull();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.start');
    });

    it('stops charging when leaving off-peak window', function () {
        $this->travelTo(now()->setTime(5, 55));

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::OffPeak,
        ]);

        Metric::factory()->charging()->create([
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');
    });

    it('does not stop solar charge when leaving off-peak', function () {
        $this->travelTo(now()->setTime(5, 55));

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
        ]);

        Metric::factory()->charging()->create([
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->ended_at)->toBeNull();
    });
});

describe('load shedding', function () {
    it('reduces current when a phase is overloaded', function () {
        Metric::factory()->charging(16)->create([
            'meter_current_l1' => 22,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        ChargingSession::factory()->active()->create();

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_value'] ?? null) === 15;
        });
    });

    it('stops charge when at minimum amps and still overloaded', function () {
        Metric::factory()->charging(6)->create([
            'charger_current' => 6,
            'meter_current_l1' => 22,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        $session = ChargingSession::factory()->active()->create();

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();
    });
});

describe('solar', function () {
    it('starts solar charging when surplus is sustained for 3 minutes', function () {
        $this->travelTo(now()->setTime(12, 0));

        $minSurplus = 6 * 230;

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -($minSurplus + 200),
                'charger_state' => ChargerState::NeedAuth,
                'meter_current_l1' => 5,
                'meter_current_l2' => 5,
                'meter_current_l3' => 5,
            ]);
        }

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session = ChargingSession::query()->first();
        expect($session)->not->toBeNull()
            ->and($session->mode)->toBe(ChargingMode::Solar);

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.start');
    });

    it('stops solar charging when consuming from grid for 2 minutes', function () {
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => 500,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');
    });

    it('increases current when surplus is available during solar charge', function () {
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(10)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -2000,
                'charger_current' => 10,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_value'] ?? null) === 11;
        });
    });

    it('decreases current when partially consuming from grid during solar charge', function () {
        $this->travelTo(now()->setTime(12, 0));

        // 2 surplus, 1 consuming → average positive (+267W) → should decrease
        Metric::factory()->charging(10)->create([
            'recorded_at' => now()->subMinutes(2),
            'meter_power_total' => -500,
            'charger_current' => 10,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);
        Metric::factory()->charging(10)->create([
            'recorded_at' => now()->subMinute(),
            'meter_power_total' => -200,
            'charger_current' => 10,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);
        Metric::factory()->charging(10)->create([
            'recorded_at' => now(),
            'meter_power_total' => 1500,
            'charger_current' => 10,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_value'] ?? null) === 9;
        });
    });

    it('does not start solar during off-peak hours', function () {
        $this->travelTo(now()->setTime(23, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -3000,
                'charger_state' => ChargerState::NeedAuth,
                'meter_current_l1' => 5,
                'meter_current_l2' => 5,
                'meter_current_l3' => 5,
            ]);
        }

        $this->artisan('app:manage-charging')->assertSuccessful();

        expect(ChargingSession::query()->where('mode', ChargingMode::Solar)->count())->toBe(0);
    });
});

describe('off-peak window', function () {
    it('detects off-peak window correctly', function () {
        $command = new ManageChargingCommand;

        // During off-peak (23:00 with window 22:15-05:55)
        $this->travelTo(now()->setTime(23, 0));
        expect($command->isInOffPeakWindow(now()))->toBeTrue();

        // During off-peak (03:00)
        $this->travelTo(now()->setTime(3, 0));
        expect($command->isInOffPeakWindow(now()))->toBeTrue();

        // Outside off-peak (12:00)
        $this->travelTo(now()->setTime(12, 0));
        expect($command->isInOffPeakWindow(now()))->toBeFalse();

        // Boundary: exactly at start (22:15)
        $this->travelTo(now()->setTime(22, 15));
        expect($command->isInOffPeakWindow(now()))->toBeTrue();

        // Boundary: exactly at end (05:55)
        $this->travelTo(now()->setTime(5, 55));
        expect($command->isInOffPeakWindow(now()))->toBeFalse();
    });
});

describe('session tracking', function () {
    it('updates three-phase detection during charge', function () {
        Metric::factory()->create([
            'charger_state' => ChargerState::Charging,
            'charger_power' => 11000,
            'charger_current' => 16,
            'charger_current_l1' => 16,
            'charger_current_l2' => 16,
            'charger_current_l3' => 16,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        $session = ChargingSession::factory()->active()->create([
            'is_three_phase' => null,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->is_three_phase)->toBeTrue();
    });

    it('updates max current when it increases', function () {
        Metric::factory()->create([
            'charger_state' => ChargerState::Charging,
            'charger_power' => 7360,
            'charger_current' => 32,
            'charger_current_l1' => 32,
            'charger_current_l2' => 0,
            'charger_current_l3' => 0,
            'meter_current_l1' => 15,
            'meter_current_l2' => 5,
            'meter_current_l3' => 5,
        ]);

        $session = ChargingSession::factory()->active()->create([
            'max_current' => 16,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->max_current)->toBe(32);
    });
});
