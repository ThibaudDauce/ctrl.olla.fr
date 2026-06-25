<?php

use App\Console\Commands\ManageChargingCommand;
use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
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
        'charging.solar_margin_watts' => 230,
        'charging.tempo.margin' => 0.20,
        'charging.tempo.rates.bleu_hc' => 0.1056,
        'charging.tempo.rates.bleu_hp' => 0.1369,
        'charging.tempo.rates.blanc_hp' => 0.1553,
        'charging.tempo.rates.rouge_hp' => 0.7324,
    ]);

    Cache::forget('tempo_day_color');
    fakeLektricoResponses();
    fakeTempoResponses();
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

    it('starts charging when car is plugged in during off-peak window', function () {
        $this->travelTo(now()->setTime(23, 30));

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
    it('reduces current based on phase overage', function () {
        // L1 at 22A, max 20A → overage 2A → 16A - 2A = 14A
        Http::swap(new Factory);
        fakeLektricoResponses(['app_config' => ['user_power' => 16 * 230]]);

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
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 14 * 230;
        });
    });

    it('stops charge when overage would go below minimum amps', function () {
        // L1 at 25A, max 20A → overage 5A → 8A - 5A = 3A < 6A min → stop
        Http::swap(new Factory);
        fakeLektricoResponses(['app_config' => ['user_power' => 8 * 230]]);

        Metric::factory()->charging(8)->create([
            'meter_current_l1' => 25,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        $session = ChargingSession::factory()->active()->create();

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();
    });

    it('recovers amps when phases have headroom after load shedding', function () {
        // All phases at 15A, max 20A → headroom 5A → 20A + 5A = 25A
        Http::swap(new Factory);
        fakeLektricoResponses(['app_config' => ['user_power' => 20 * 230]]);

        Metric::factory()->charging(20)->create([
            'meter_current_l1' => 15,
            'meter_current_l2' => 15,
            'meter_current_l3' => 15,
        ]);

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::OffPeak,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 25 * 230;
        });
    });

    it('does not recover beyond max charge amps', function () {
        // All phases at 10A, max 20A → headroom 10A → 28A + 10A = 38A, capped at 32A
        Http::swap(new Factory);
        fakeLektricoResponses(['app_config' => ['user_power' => 28 * 230]]);

        Metric::factory()->charging(28)->create([
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::OffPeak,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 32 * 230;
        });
    });
});

describe('solar', function () {
    it('starts solar charging with full surplus', function () {
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -1580,
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

    it('starts solar on blue day with small surplus', function () {
        // Jour bleu HP 0.1369€, seuil = HC bleue 0.1056 × 1.20 = 0.12672
        // minSurplusRatio = 1 - 0.12672/0.1369 = 0.0744 → minSurplus = 1380 × 0.0744 ≈ 103W
        // 200W surplus > 103W → charge démarre
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -200,
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
    });

    it('starts solar charge a step below the available surplus to keep an export margin', function () {
        // Surplus moyen de 2000W. Sans marge on démarrerait à 8A (floor(2000/230)) ;
        // avec la marge de 230W on démarre un cran en dessous, à 7A.
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -2000,
                'charger_state' => ChargerState::NeedAuth,
                'meter_current_l1' => 5,
                'meter_current_l2' => 5,
                'meter_current_l3' => 5,
            ]);
        }

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session = ChargingSession::query()->first();
        expect($session)->not->toBeNull()
            ->and($session->mode)->toBe(ChargingMode::Solar)
            ->and($session->max_current)->toBe(7);

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['method'] ?? null) === 'app_config.set'
            && ($r['params']['config_key'] ?? null) === 'user_power'
            && ($r['params']['config_value'] ?? null) === 7 * 230);
        Http::assertNotSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['params']['config_value'] ?? null) === 8 * 230);
    });

    it('does not start solar on rouge day with small surplus', function () {
        // Jour rouge HP 0.7324€, seuil = 0.12672
        // minSurplusRatio = 1 - 0.12672/0.7324 = 0.8270 → minSurplus = 1380 × 0.827 ≈ 1141W
        // 200W surplus < 1141W → pas de charge
        Http::swap(new Factory);
        fakeLektricoResponses();
        fakeTempoResponses('Rouge', 3);

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -200,
                'charger_state' => ChargerState::NeedAuth,
                'meter_current_l1' => 5,
                'meter_current_l2' => 5,
                'meter_current_l3' => 5,
            ]);
        }

        $this->artisan('app:manage-charging')->assertSuccessful();

        expect(ChargingSession::query()->count())->toBe(0);
    });

    it('stops solar on rouge day when grid cost exceeds threshold', function () {
        // Jour rouge, charge à 6A (1380W), meter=400W → grid=400W
        // cost = (400/1380) × 0.7324 = 0.2124 > seuil 0.12672 → arrêt
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 6 * 230]]);
        fakeTempoResponses('Rouge', 3);

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(6)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => 400,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');
    });

    it('keeps solar on blue day with moderate grid consumption', function () {
        // Jour bleu, charge à 6A (1380W), meter=500W → grid=500W
        // cost = (500/1380) × 0.1369 = 0.0496 < seuil 0.12672 → continue
        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(6)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => 500,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session = ChargingSession::query()->whereNull('ended_at')->first();
        expect($session)->not->toBeNull();
    });

    it('increases current when surplus is available during solar charge', function () {
        // Charge à 10A (2300W) avec 2000W d'export → surplus dispo = 4300W.
        // Sans marge on viserait 18A ; avec la marge de 230W on reste un cran en dessous → 17A.
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 10 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(10)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -2000,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 17 * 230;
        });
    });

    it('keeps an export margin instead of charging right at the edge', function () {
        // Charge à 10A (2300W) en important 690W → surplus solaire réel = 1610W (pile 7A).
        // Sans marge on viserait 7A et on consommerait tout le surplus ; avec la marge de 230W
        // on vise 6A et on garde ~230W d'export.
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 10 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(10)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => 690,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['method'] ?? null) === 'app_config.set'
            && ($r['params']['config_key'] ?? null) === 'user_power'
            && ($r['params']['config_value'] ?? null) === 6 * 230);
        Http::assertNotSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['params']['config_value'] ?? null) === 7 * 230);
    });

    it('caps solar charge at the maximum amps when surplus is very large', function () {
        // Charge à 10A (2300W) avec 9000W d'export → surplus dispo = 11300W (~48A théoriques),
        // plafonné à la limite de 32A.
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 10 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(10)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -9000,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['method'] ?? null) === 'app_config.set'
            && ($r['params']['config_key'] ?? null) === 'user_power'
            && ($r['params']['config_value'] ?? null) === 32 * 230);
    });

    it('decreases current when partially consuming from grid during solar charge', function () {
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 10 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        Metric::factory()->charging(10)->create([
            'recorded_at' => now()->subMinutes(2),
            'meter_power_total' => -500,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);
        Metric::factory()->charging(10)->create([
            'recorded_at' => now()->subMinute(),
            'meter_power_total' => -200,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);
        Metric::factory()->charging(10)->create([
            'recorded_at' => now(),
            'meter_power_total' => 1500,
            'meter_current_l1' => 10,
            'meter_current_l2' => 10,
            'meter_current_l3' => 10,
        ]);

        ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 10,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 7 * 230;
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

    it('defaults to rouge when tempo API fails', function () {
        // API fail → Rouge → minSurplusRatio élevé → 200W surplus insuffisant
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses();
        Http::fake([
            'www.api-couleur-tempo.fr/*' => Http::response([], 500),
        ]);

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -200,
                'charger_state' => ChargerState::NeedAuth,
                'meter_current_l1' => 5,
                'meter_current_l2' => 5,
                'meter_current_l3' => 5,
            ]);
        }

        $this->artisan('app:manage-charging')->assertSuccessful();

        expect(ChargingSession::query()->count())->toBe(0);
    });

    it('waits for fresh metrics before adjusting after a power change', function () {
        // Régression oscillation : juste après un changement d'ampérage, les métriques d'avant
        // le changement montrent encore le surplus précédent. Ré-ajuster sur cette fenêtre « sale »
        // faisait grimper l'ampérage au-delà de la production, puis couper. On doit attendre une
        // fenêtre entièrement postérieure au dernier changement avant de décider.
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 6 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        // Changement de puissance il y a 90s → seules les 2 métriques les plus récentes sont valides.
        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(6)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => -3000,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 6,
            'current_set_at' => now()->subSeconds(90),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertNotSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc'
            && ($r['params']['config_key'] ?? null) === 'user_power');
        $session->refresh();
        expect($session->ended_at)->toBeNull();
    });

    it('throttles down to minimum instead of stopping on a cheap day', function () {
        // Régression oscillation : sur un jour bleu, une chute du surplus doit réduire l'ampérage
        // (et tolérer un peu de réseau bon marché), pas couper la charge pour la relancer ensuite.
        Http::swap(new Factory);
        Cache::forget('tempo_day_color');
        fakeLektricoResponses(['app_config' => ['user_power' => 12 * 230]]);
        fakeTempoResponses();

        $this->travelTo(now()->setTime(12, 0));

        foreach (range(0, 2) as $i) {
            Metric::factory()->charging(12)->create([
                'recorded_at' => now()->subMinutes(2 - $i),
                'meter_power_total' => 2000,
                'meter_current_l1' => 10,
                'meter_current_l2' => 10,
                'meter_current_l3' => 10,
            ]);
        }

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::Solar,
            'max_current' => 12,
            'current_set_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        Http::assertSent(function ($r) {
            return $r->url() === 'http://198.51.100.10/rpc'
                && ($r['method'] ?? null) === 'app_config.set'
                && ($r['params']['config_key'] ?? null) === 'user_power'
                && ($r['params']['config_value'] ?? null) === 6 * 230;
        });
        Http::assertNotSent(fn ($r) => $r->url() === 'http://198.51.100.10/rpc' && ($r['method'] ?? null) === 'charge.stop');
        $session->refresh();
        expect($session->ended_at)->toBeNull();
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

    it('closes session when car finishes charging', function () {
        $this->travelTo(now()->setTime(3, 0));

        $session = ChargingSession::factory()->active()->create([
            'mode' => ChargingMode::OffPeak,
            'started_at' => now()->subHours(2),
        ]);

        Metric::factory()->create([
            'charger_state' => ChargerState::Connected,
            'meter_current_l1' => 5,
            'meter_current_l2' => 5,
            'meter_current_l3' => 5,
        ]);

        $this->artisan('app:manage-charging')->assertSuccessful();

        $session->refresh();
        expect($session->ended_at)->not->toBeNull();
    });

    it('updates max current when it increases', function () {
        Metric::factory()->create([
            'charger_state' => ChargerState::Charging,
            'charger_power' => 7360,
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
