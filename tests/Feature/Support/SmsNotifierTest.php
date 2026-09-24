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

it('returns false instead of throwing when the SMS gateway is unreachable', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::failedConnection('Connection refused'),
    ]);

    $notifier = new SmsNotifier;
    $result = $notifier->send('Test', 'unreachable-key');

    expect($result)->toBeFalse()
        ->and(Cache::has('sms_throttle:unreachable-key'))->toBeFalse();
});

it('stays silent on an isolated error', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    expect($notifier->sendIfRepeated('Erreur meter', 'device_meter'))->toBeFalse();

    Http::assertNothingSent();
});

it('alerts on the second error within 15 minutes', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $notifier->sendIfRepeated('Erreur meter', 'device_meter');
    $this->travel(14)->minutes();

    expect($notifier->sendIfRepeated('Erreur meter', 'device_meter'))->toBeTrue();

    Http::assertSentCount(1);
});

it('stays silent when errors are more than 15 minutes apart', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $notifier->sendIfRepeated('Erreur meter', 'device_meter');
    $this->travel(16)->minutes();

    expect($notifier->sendIfRepeated('Erreur meter', 'device_meter'))->toBeFalse();

    Http::assertNothingSent();
});

it('counts repeated errors per key', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $notifier->sendIfRepeated('Erreur meter', 'device_meter');

    expect($notifier->sendIfRepeated('Erreur Envoy', 'device_envoy'))->toBeFalse();

    Http::assertNothingSent();
});

it('still throttles repeated alerts for an hour once sent', function () {
    Http::fake([
        'smsapi.free-mobile.fr/*' => Http::response('', 200),
    ]);

    $notifier = new SmsNotifier;

    $notifier->sendIfRepeated('Erreur meter', 'device_meter');
    $notifier->sendIfRepeated('Erreur meter', 'device_meter');

    expect($notifier->sendIfRepeated('Erreur meter', 'device_meter'))->toBeFalse();

    Http::assertSentCount(1);
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
