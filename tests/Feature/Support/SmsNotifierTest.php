<?php

use App\Support\SmsNotifier;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.free_sms.user' => 'test-user',
        'services.free_sms.key' => 'test-key',
    ]);

    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);
});

it('sends an SMS', function () {
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
    $notifier = new SmsNotifier;

    $first = $notifier->send('First', 'same-key');
    $second = $notifier->send('Second', 'same-key');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();

    Http::assertSentCount(1);
});

it('allows SMS with different keys', function () {
    $notifier = new SmsNotifier;

    $first = $notifier->send('First', 'key-1');
    $second = $notifier->send('Second', 'key-2');

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue();

    Http::assertSentCount(2);
});

it('returns false when not configured', function () {
    config([
        'services.free_sms.user' => null,
        'services.free_sms.key' => null,
    ]);

    $notifier = new SmsNotifier;
    $result = $notifier->send('Test');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});
