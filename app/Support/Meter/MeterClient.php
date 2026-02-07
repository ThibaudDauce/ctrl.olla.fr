<?php

namespace App\Support\Meter;

use Illuminate\Support\Facades\Http;

class MeterClient
{
    public function __construct(public string $host) {}

    public function info(): MeterInfo
    {
        $data = Http::get("http://{$this->host}/rpc/Meter_info.Get")->json();

        return new MeterInfo(
            totalActivePower: $data['total_active_power'],
            activePowerPerPhase: $data['active_p'],
            currentPerPhase: $data['current'],
        );
    }
}
