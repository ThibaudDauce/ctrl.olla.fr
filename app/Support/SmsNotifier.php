<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsNotifier
{
    /**
     * N'alerte qu'à partir de la deuxième occurrence dans les 15 minutes. Les capteurs
     * sont interrogés chaque minute : une panne réelle se répète à la collecte suivante,
     * alors qu'un timeout isolé se résout tout seul et n'a pas à réveiller qui que ce soit.
     */
    public function sendIfRepeated(string $message, string $key): bool
    {
        $cacheKey = "sms_repeat:{$key}";
        $alreadySeen = Cache::has($cacheKey);

        Cache::put($cacheKey, true, now()->addMinutes(15));

        if (! $alreadySeen) {
            return false;
        }

        return $this->send($message, $key);
    }

    public function send(string $message, ?string $throttleKey = null): bool
    {
        if ($throttleKey) {
            $cacheKey = "sms_throttle:{$throttleKey}";

            if (Cache::has($cacheKey)) {
                return false;
            }
        }

        $user = config('services.free_sms.user');
        $key = config('services.free_sms.key');

        if (! $user || ! $key) {
            Log::warning('SMS not configured', ['message' => $message]);

            return false;
        }

        // Appelé depuis les `catch` qui signalent une panne de capteur : une exception
        // qui sortirait d'ici ne serait rattrapée par personne et ferait échouer toute
        // la commande appelante.
        try {
            $response = Http::timeout(10)->get('https://smsapi.free-mobile.fr/sendmsg', [
                'user' => $user,
                'pass' => $key,
                'msg' => $message,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('SMS send failed', ['error' => $e->getMessage(), 'message' => $message]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('SMS send failed', ['status' => $response->status(), 'message' => $message]);

            return false;
        }

        if ($throttleKey) {
            Cache::put($cacheKey, true, now()->addHour());
        }

        return true;
    }
}
