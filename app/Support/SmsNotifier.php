<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsNotifier
{
    public function send(string $message, string $throttleKey = 'default'): bool
    {
        $cacheKey = "sms_throttle:{$throttleKey}";

        if (Cache::has($cacheKey)) {
            return false;
        }

        $user = config('services.free_sms.user');
        $key = config('services.free_sms.key');

        if (! $user || ! $key) {
            Log::warning('SMS not configured', ['message' => $message]);

            return false;
        }

        Http::get('https://smsapi.free-mobile.fr/sendmsg', [
            'user' => $user,
            'pass' => $key,
            'msg' => $message,
        ]);

        Cache::put($cacheKey, true, now()->addHour());

        return true;
    }
}
