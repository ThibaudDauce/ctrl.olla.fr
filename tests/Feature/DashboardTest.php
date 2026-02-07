<?php

use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Lektrico\ChargerState;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.lektrico.host' => '198.51.100.10',
        'charging.min_charge_amps' => 6,
        'charging.max_charge_amps' => 32,
    ]);
});

it('renders the dashboard page', function () {
    $this->get('/')->assertSuccessful();
});

it('shows empty state when no metrics', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee("Pas encore de donn\u{e9}es aujourd'hui", false);
});

it('shows chart when metrics exist', function () {
    Metric::factory()->count(3)->sequence(
        ['recorded_at' => now()->subMinutes(2)],
        ['recorded_at' => now()->subMinute()],
        ['recorded_at' => now()],
    )->create();

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Consommation')
        ->assertSee('Autoconso. solaire')
        ->assertSee('Injection');
});

it('shows charging status when charger is active', function () {
    Metric::factory()->charging(16)->create([
        'recorded_at' => now(),
    ]);

    ChargingSession::factory()->active()->create([
        'mode' => ChargingMode::OffPeak,
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Charge en cours')
        ->assertSee('Heures creuses');
});

it('shows start button when charger is connectable', function () {
    Metric::factory()->create([
        'recorded_at' => now(),
        'charger_state' => ChargerState::NeedAuth,
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Lancer la charge');
});

it('shows charger state badge when not charging or connectable', function () {
    Metric::factory()->create([
        'recorded_at' => now(),
        'charger_state' => ChargerState::Available,
    ]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Disponible');
});

it('can start a manual charge', function () {
    Metric::factory()->create([
        'recorded_at' => now(),
        'charger_state' => ChargerState::NeedAuth,
    ]);

    fakeLektricoResponses();

    Livewire::test('pages::dashboard')
        ->assertSet('requestedAmps', 32)
        ->set('requestedAmps', 20)
        ->assertSet('requestedAmps', 20)
        ->call('startCharge')
        ->assertSet('requestedAmps', 20);

    $session = ChargingSession::query()->first();
    expect($session)->not->toBeNull()
        ->and($session->mode)->toBe(ChargingMode::Manual)
        ->and($session->max_current)->toBe(20);
});

it('clamps requestedAmps to valid range on start', function () {
    Metric::factory()->create([
        'recorded_at' => now(),
        'charger_state' => ChargerState::NeedAuth,
    ]);

    fakeLektricoResponses();

    Livewire::test('pages::dashboard')
        ->set('requestedAmps', 100)
        ->call('startCharge');

    $session = ChargingSession::query()->first();
    expect($session)->not->toBeNull()
        ->and($session->max_current)->toBe(32);
});

it('clamps requestedAmps to minimum on update', function () {
    Metric::factory()->charging()->create(['recorded_at' => now()]);
    ChargingSession::factory()->active()->create(['max_current' => 16]);

    fakeLektricoResponses();

    Livewire::test('pages::dashboard')
        ->set('requestedAmps', 1);

    Http::assertSent(function ($r) {
        return $r->url() === 'http://198.51.100.10/rpc'
            && ($r['method'] ?? null) === 'app_config.set'
            && ($r['params']['config_key'] ?? null) === 'user_power'
            && ($r['params']['config_value'] ?? null) === 6 * 230;
    });
});

it('can stop a charge', function () {
    Metric::factory()->charging()->create(['recorded_at' => now()]);
    ChargingSession::factory()->active()->create();

    fakeLektricoResponses();

    Livewire::test('pages::dashboard')
        ->call('stopCharge');

    $session = ChargingSession::query()->first();
    expect($session->ended_at)->not->toBeNull();
});
