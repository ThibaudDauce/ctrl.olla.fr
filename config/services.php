<?php

return [

    'lektrico' => [
        'host' => env('LEKTRICO_HOST'),
    ],

    'meter' => [
        'host' => env('METER_HOST'),
    ],

    'envoy' => [
        'host' => env('ENVOY_HOST'),
        'email' => env('ENVOY_EMAIL'),
        'token' => env('ENVOY_TOKEN'),
        'token_expires_at' => env('ENVOY_TOKEN_EXPIRES_AT'),
    ],

    'free_sms' => [
        'user' => env('FREE_SMS_USER'),
        'key' => env('FREE_SMS_KEY'),
    ],

];
