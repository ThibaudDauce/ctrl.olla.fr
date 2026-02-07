<?php

namespace App\Support\Lektrico;

use Illuminate\Support\Facades\Http;

class LektricoClient
{
    public function __construct(public string $host) {}

    public static function make(): static
    {
        return new static(config('services.lektrico.host'));
    }

    public function info(): ChargerInfo
    {
        $info = Http::get("http://{$this->host}/rpc/charger_info.get")->json();
        $dynamic = Http::get("http://{$this->host}/rpc/dynamic_current.get")->json();

        return new ChargerInfo(
            state: ChargerState::from($info['extended_charger_state']),
            instantPower: $info['instant_power'],
            dynamicCurrent: $dynamic['dynamic_current'],
            chargingTime: $info['charging_time'],
            sessionEnergy: $info['session_energy'],
            currents: $info['currents'],
            voltages: $info['voltages'],
        );
    }

    public function start(): void
    {
        $this->post([
            'method' => 'charge.start',
            'params' => ['tag' => 'ctrl'],
        ]);
    }

    public function stop(): void
    {
        $this->post([
            'method' => 'charge.stop',
        ]);
    }

    public function setDynamicCurrent(int $amps): void
    {
        $this->post([
            'method' => 'dynamic_current.set',
            'params' => ['dynamic_current' => $amps],
        ]);
    }

    private function post(array $data): void
    {
        Http::post("http://{$this->host}/rpc", [
            'src' => 'ctrl',
            'id' => random_int(10_000_000, 99_999_999),
            ...$data,
        ]);
    }
}
