<?php

use App\Models\ChargingSession;
use App\Models\Metric;
use App\Support\ChargingMode;
use App\Support\Envoy\EnvoyClient;
use App\Support\Lektrico\ChargerState;
use App\Support\Lektrico\LektricoClient;
use App\Support\Meter\MeterClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public ?int $requestedAmps = null;

    public function mount(): void
    {
        $this->requestedAmps = $this->latest?->charger_current;
    }

    #[Computed]
    public function liveSolar(): float
    {
        try {
            return config('services.envoy.token') ? EnvoyClient::make()->production()->wattsNow : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    #[Computed]
    public function liveMeter(): float
    {
        try {
            return config('services.meter.host') ? MeterClient::make()->info()->totalActivePower : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    #[Computed]
    public function liveCharger(): float
    {
        try {
            return config('services.lektrico.host') ? LektricoClient::make()->info()->instantPower : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    #[Computed]
    public function todayMetrics(): \Illuminate\Support\Collection
    {
        return Metric::query()->whereDate('recorded_at', today())->orderBy('recorded_at')->get();
    }

    #[Computed]
    public function solarSparkline(): array
    {
        return $this->padSparkline($this->todayMetrics->pluck('solar_power')->map(fn($v) => $v ?? 0));
    }

    #[Computed]
    public function meterSparkline(): array
    {
        return $this->padSparkline($this->todayMetrics->pluck('meter_power_total')->map(fn($v) => $v ?? 0));
    }

    #[Computed]
    public function chargerSparkline(): array
    {
        return $this->padSparkline($this->todayMetrics->pluck('charger_power')->map(fn($v) => $v ?? 0));
    }

    private function padSparkline(\Illuminate\Support\Collection $values): array
    {
        return array_pad($values->values()->all(), 1440, null);
    }

    private function clampCurrent(int $value): int
    {
        return max(config('charging.min_charge_amps'), min(config('charging.max_charge_amps'), $value));
    }

    #[Computed]
    public function hasMetrics(): bool
    {
        return Metric::query()->whereDate('recorded_at', today())->exists();
    }

    #[Computed]
    public function chartData(): array
    {
        $fmt = 'Y-m-d\TH:i:s';

        return $this->todayMetrics
            ->map(function (Metric $m) use ($fmt) {
                $meter = $m->meter_power_total ?? 0;
                $solar = $m->solar_power ?? 0;
                $consumption = max(0, $solar + $meter);
                $importing = $meter >= 0;

                return [
                    'time' => $m->recorded_at->format($fmt),
                    'production' => $solar,
                    'consumption' => $consumption,
                    // Jaune uniquement en mode import, pour couvrir la base bleue
                    'solar_contrib' => $importing ? $solar : 0,
                ];
            })
            ->all();
    }

    #[Computed]
    public function latest(): ?Metric
    {
        return Metric::query()->latest('recorded_at')->first();
    }

    #[Computed]
    public function activeSession(): ?ChargingSession
    {
        return ChargingSession::query()->whereNull('ended_at')->latest('started_at')->first();
    }

    public function startCharge(): void
    {
        $this->requestedAmps = $this->clampCurrent($this->requestedAmps ?? config('charging.min_charge_amps'));

        $charger = LektricoClient::make();
        $charger->setUserCurrent($this->requestedAmps);
        $charger->start();

        ChargingSession::query()->create([
            'started_at' => now(),
            'mode' => ChargingMode::Manual,
            'max_current' => $this->requestedAmps,
        ]);

        unset($this->latest, $this->activeSession);
    }

    public function stopCharge(): void
    {
        LektricoClient::make()->stop();

        $session = $this->activeSession;
        if ($session) {
            $session->update([
                'ended_at' => now(),
                'energy_kwh' => $session->computeEnergyKwh(),
            ]);
        }

        unset($this->latest, $this->activeSession);
    }

    public function updatedRequestedAmps(): void
    {
        $this->requestedAmps = $this->clampCurrent($this->requestedAmps ?? config('charging.min_charge_amps'));

        LektricoClient::make()->setUserCurrent($this->requestedAmps);

        $session = $this->activeSession;
        if ($session && $this->requestedAmps > $session->max_current) {
            $session->update(['max_current' => $this->requestedAmps]);
        }

        unset($this->latest);
    }
};
?>

<div wire:poll.5s class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    <flux:heading size="xl" class="mb-8">Dashboard</flux:heading>

    <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-3">
        @island(name: 'solar', defer: true)
            @placeholder
                <flux:card class="relative overflow-hidden h-[8.5rem]">
                    <flux:skeleton.group animate="pulse">
                        <flux:skeleton.line class="w-1/3" />
                        <flux:skeleton class="mt-2 h-7 w-1/2 rounded" />
                        <flux:skeleton class="absolute bottom-0 inset-x-0 h-[3rem] rounded-none" />
                    </flux:skeleton.group>
                </flux:card>
            @endplaceholder

            <flux:card class="relative overflow-hidden h-[8.5rem]" wire:poll.3s>
                <flux:text>Production solaire</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">
                    {{ number_format($this->liveSolar, 0, ',', "\u{202f}") }} W</flux:heading>
                @if (count($this->solarSparkline) > 1)
                    <flux:chart class="absolute -bottom-1.5 -inset-x-2 h-[3rem]" :value="$this->solarSparkline">
                        <flux:chart.svg gutter="0">
                            <flux:chart.line class="text-amber-300 dark:text-amber-400" />
                            <flux:chart.area class="text-amber-100 dark:text-amber-400/30" />
                        </flux:chart.svg>
                    </flux:chart>
                @endif
            </flux:card>
        @endisland

        @island(name: 'meter', defer: true)
            @placeholder
                <flux:card class="relative overflow-hidden h-[8.5rem]">
                    <flux:skeleton.group animate="pulse">
                        <flux:skeleton.line class="w-1/3" />
                        <flux:skeleton class="mt-2 h-7 w-1/2 rounded" />
                        <flux:skeleton class="absolute bottom-0 inset-x-0 h-[3rem] rounded-none" />
                    </flux:skeleton.group>
                </flux:card>
            @endplaceholder

            <flux:card class="relative overflow-hidden h-[8.5rem]" wire:poll.3s>
                <flux:text>{{ $this->liveMeter >= 0 ? 'Consommation réseau' : 'Injection réseau' }}</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">
                    {{ number_format(abs($this->liveMeter), 0, ',', "\u{202f}") }} W</flux:heading>
                @if (count($this->meterSparkline) > 1)
                    <flux:chart class="absolute -bottom-1.5 -inset-x-2 h-[3rem]" :value="$this->meterSparkline">
                        <flux:chart.svg gutter="0">
                            <flux:chart.line class="text-blue-300 dark:text-blue-400" />
                            <flux:chart.area class="text-blue-100 dark:text-blue-400/30" />
                        </flux:chart.svg>
                    </flux:chart>
                @endif
            </flux:card>
        @endisland

        @island(name: 'charger', defer: true)
            @placeholder
                <flux:card class="relative overflow-hidden h-[8.5rem]">
                    <flux:skeleton.group animate="pulse">
                        <flux:skeleton.line class="w-1/3" />
                        <flux:skeleton class="mt-2 h-7 w-1/2 rounded" />
                        <flux:skeleton class="absolute bottom-0 inset-x-0 h-[3rem] rounded-none" />
                    </flux:skeleton.group>
                </flux:card>
            @endplaceholder

            <flux:card class="relative overflow-hidden h-[8.5rem]" wire:poll.3s>
                <flux:text>Charge véhicule</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">
                    {{ number_format($this->liveCharger, 0, ',', "\u{202f}") }} W</flux:heading>
                @if (count($this->chargerSparkline) > 1)
                    <flux:chart class="absolute -bottom-1.5 -inset-x-2 h-[3rem]" :value="$this->chargerSparkline">
                        <flux:chart.svg gutter="0">
                            <flux:chart.line class="text-green-300 dark:text-green-400" />
                            <flux:chart.area class="text-green-100 dark:text-green-400/30" />
                        </flux:chart.svg>
                    </flux:chart>
                @endif
            </flux:card>
        @endisland
    </div>

    {{-- Chart --}}
    @if ($this->hasMetrics)
        <flux:card class="mb-6">
            <flux:chart :value="$this->chartData">
                <flux:chart.viewport class="aspect-3/1">
                    <flux:chart.svg>
                        {{-- Ordre de peinture SVG : vert (fond) → bleu (milieu) → jaune (dessus) --}}
                        {{-- Import : jaune couvre la base bleue, bleu visible au-dessus = import réseau --}}
                        {{-- Injection : bleu couvre la base verte, vert visible au-dessus = injection --}}
                        <flux:chart.area field="production" class="text-lime-100 dark:text-lime-400/25" />
                        <flux:chart.line field="production" class="text-lime-500 dark:text-lime-400" />

                        <flux:chart.area field="consumption" class="text-blue-100 dark:text-blue-400/25" />
                        <flux:chart.line field="consumption" class="text-blue-500 dark:text-blue-400" />

                        <flux:chart.area field="solar_contrib" class="text-amber-200 dark:text-amber-400/40" />
                        <flux:chart.line field="solar_contrib" class="text-amber-500 dark:text-amber-400" />

                        <flux:chart.axis axis="x" field="time" scale="time"
                            :format="['hour' => 'numeric', 'minute' => '2-digit', 'hour12' => false]">
                            <flux:chart.axis.line />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.cursor />
                    </flux:chart.svg>
                </flux:chart.viewport>

                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="time"
                        :format="['hour' => 'numeric', 'minute' => '2-digit', 'hour12' => false]" />
                    <flux:chart.tooltip.value field="consumption" label="Consommation" suffix=" W" />
                    <flux:chart.tooltip.value field="production" label="Production solaire" suffix=" W" />
                </flux:chart.tooltip>

                <div class="flex justify-center gap-4 pt-4">
                    <flux:chart.legend label="Import réseau">
                        <flux:chart.legend.indicator class="bg-blue-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="Autoconso. solaire">
                        <flux:chart.legend.indicator class="bg-amber-500" />
                    </flux:chart.legend>
                    <flux:chart.legend label="Injection">
                        <flux:chart.legend.indicator class="bg-lime-500" />
                    </flux:chart.legend>
                </div>
            </flux:chart>
        </flux:card>
    @else
        <flux:card class="mb-6">
            <div class="flex flex-col items-center justify-center py-12 text-zinc-400">
                <flux:icon.chart-bar class="size-12 mb-2" />
                <flux:text>Pas encore de données aujourd'hui</flux:text>
            </div>
        </flux:card>
    @endif

    @if ($this->latest)
        @php
            $latest = $this->latest;
            $session = $this->activeSession;
            $isCharging = $latest->charger_state?->isCharging();
            $isConnectable = $latest->charger_state?->isConnectable();
        @endphp

        {{-- Charging status --}}
        @if ($isCharging && $session)
            <flux:card class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">Charge en cours</flux:heading>
                    <div class="flex gap-2">
                        <flux:badge color="green">{{ $session->mode->label() }}</flux:badge>
                        @if (
                            $latest->meter_current_l1 > config('charging.phase_max_amps') ||
                                $latest->meter_current_l2 > config('charging.phase_max_amps') ||
                                $latest->meter_current_l3 > config('charging.phase_max_amps'))
                            <flux:badge color="red">Délestage</flux:badge>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Puissance</flux:text>
                        <flux:heading size="lg">{{ number_format($latest->charger_power / 1000, 1, ',', ' ') }} kW
                        </flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Ampérage</flux:text>
                        <flux:heading size="lg">{{ $latest->charger_current }}A / {{ $latest->charger_current }}A
                        </flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Durée</flux:text>
                        <flux:heading size="lg">
                            {{ $session->started_at->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }}
                        </flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">Énergie</flux:text>
                        <flux:heading size="lg">{{ number_format($session->computeEnergyKwh(), 1, ',', ' ') }} kWh</flux:heading>
                    </div>
                </div>

                <div class="mt-4">
                    <flux:button variant="danger" wire:click="stopCharge" wire:confirm="Arrêter la charge ?">Arrêter
                    </flux:button>
                </div>
            </flux:card>
        @endif

        {{-- Controls --}}
        @if ($isConnectable || $isCharging)
            <flux:card class="mb-6">
                <flux:heading size="lg" class="mb-4">Contrôles</flux:heading>

                @if ($isConnectable)
                    <flux:button variant="primary" wire:click="startCharge">Lancer la charge</flux:button>
                @endif

                <div class="mt-4">
                    <flux:text class="text-sm text-zinc-500 mb-2">Ampérage : <span
                            x-text="$wire.requestedAmps + 'A'">{{ $this->requestedAmps }}A</span></flux:text>
                    <flux:slider wire:model.live.debounce.500ms="requestedAmps" min="6" max="32" step="1">
                        @for ($a = 6; $a <= 32; $a++)
                            <flux:slider.tick :value="$a">{{ $a % 2 === 0 ? $a : '' }}</flux:slider.tick>
                        @endfor
                    </flux:slider>
                </div>
            </flux:card>
        @endif

        {{-- Charger state when not charging/connectable --}}
        @if (!$isCharging && !$isConnectable && $latest->charger_state)
            <flux:card class="mb-6">
                <div class="flex items-center gap-3">
                    <flux:text>Borne :</flux:text>
                    <flux:badge>{{ $latest->charger_state->label() }}</flux:badge>
                </div>
            </flux:card>
        @endif
    @endif
</div>
