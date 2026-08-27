<?php

use App\Support\WebhookSignature;

it('parses t and v1 in either order', function () {
    expect(WebhookSignature::parse('t=123,v1=abc'))->toBe(['t' => 123, 'v1' => 'abc'])
        ->and(WebhookSignature::parse('v1=abc,t=123'))->toBe(['t' => 123, 'v1' => 'abc']);
});

it('rejects a malformed header', function () {
    expect(WebhookSignature::parse('sha256=deadbeef'))->toBeNull()
        ->and(WebhookSignature::parse('t=nope,v1=abc'))->toBeNull()
        ->and(WebhookSignature::parse(''))->toBeNull();
});

it('accepts a signature inside the tolerance window', function () {
    $now = 1_724_000_000;
    $header = WebhookSignature::sign('{"ok":true}', 'secret', $now - 10);

    expect(WebhookSignature::verify($header, '{"ok":true}', 'secret', $now, 300))
        ->toBe(WebhookSignature::OK);
});

it('rejects a timestamp older than the window', function () {
    $now = 1_724_000_000;
    $header = WebhookSignature::sign('{"ok":true}', 'secret', $now - 301);

    expect(WebhookSignature::verify($header, '{"ok":true}', 'secret', $now, 300))
        ->toBe(WebhookSignature::EXPIRED);
});

it('rejects a timestamp in the future beyond the window', function () {
    $now = 1_724_000_000;
    $header = WebhookSignature::sign('{"ok":true}', 'secret', $now + 301);

    expect(WebhookSignature::verify($header, '{"ok":true}', 'secret', $now, 300))
        ->toBe(WebhookSignature::EXPIRED);
});

it('rejects a valid t with the wrong v1', function () {
    $now = 1_724_000_000;
    $header = 't='.$now.',v1='.str_repeat('ab', 32);

    expect(WebhookSignature::verify($header, '{"ok":true}', 'secret', $now, 300))
        ->toBe(WebhookSignature::INVALID);
});
