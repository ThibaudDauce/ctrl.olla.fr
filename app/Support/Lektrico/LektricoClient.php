<?php

namespace App\Support\Lektrico;

use Illuminate\Support\Facades\Http;

class LektricoClient
{
    public function __construct(public string $host) {}

    public function info(): ChargerInfo
    {
        $info = Http::get("http://{$this->host}/rpc/charger_info.get")->json();
        $config = Http::get("http://{$this->host}/rpc/app_config.get")->json();

        return new ChargerInfo(
            state: ChargerState::from($info['extended_charger_state']),
            instantPower: $info['instant_power'],
            requestedCurrent: $config['user_current'],
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

    public function setUserCurrent(int $amps): void
    {
        $this->post([
            'method' => 'app_config.set',
            'params' => ['config_key' => 'user_current', 'config_value' => $amps],
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
