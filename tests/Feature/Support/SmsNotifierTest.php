<?php

use App\Support\SmsNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.free_sms.user' => 'test-user',
        'services.free_sms.key' => 'test-key',
    ]);
});

it('sends an SMS', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;
    $result = $notifier->send('Test message');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'smsapi.free-mobile.fr')
            && $request['user'] === 'test-user'
            && $request['pass'] === 'test-key'
            && $request['msg'] === 'Test message';
    });
});

it('throttles SMS with the same key', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $first = $notifier->send('First', 'same-key');
    $second = $notifier->send('Second', 'same-key');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();

    Http::assertSentCount(1);
});

it('allows SMS with different keys', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $first = $notifier->send('First', 'key-1');
    $second = $notifier->send('Second', 'key-2');

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue();

    Http::assertSentCount(2);
});

it('does not throttle when HTTP request fails', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 500),
    ]);

    $notifier = new SmsNotifier;
    $result = $notifier->send('Test', 'fail-key');

    expect($result)->toBeFalse();
    expect(Cache::has('sms_throttle:fail-key'))->toBeFalse();
});

it('can retry after a failed send', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::sequence()
            ->push('', 500)
            ->push('', 200),
    ]);

    $notifier = new SmsNotifier;

    $first = $notifier->send('First', 'retry-key');
    expect($first)->toBeFalse();

    $second = $notifier->send('Retry', 'retry-key');
    expect($second)->toBeTrue();
});

it('returns false when not configured', function () {
    Http::fake();

    config([
        'services.free_sms.user' => null,
        'services.free_sms.key' => null,
    ]);

    $notifier = new SmsNotifier;
    $result = $notifier->send('Test');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});
