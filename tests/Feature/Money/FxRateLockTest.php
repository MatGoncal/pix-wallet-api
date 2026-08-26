<?php

use App\Exceptions\DomainException;
use App\Models\FxQuote;
use App\Models\Partner;
use App\Services\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lockedQuote(?Partner $partner = null): FxQuote
{
    return app(FxService::class)->createQuote($partner ?? Partner::factory()->create(), [
        'source_currency' => 'BRL',
        'target_currency' => 'USD',
        'amount' => 10000,
    ]);
}

it('stamps consumed_at when a quote is claimed', function () {
    $quote = lockedQuote();

    expect($quote->consumed_at)->toBeNull();

    $consumed = app(FxService::class)->consume($quote);

    expect($consumed->consumed_at)->not->toBeNull()
        ->and($quote->fresh()->consumed_at)->not->toBeNull();
});

it('refuses to consume the same rate lock twice', function () {
    $quote = lockedQuote();
    $fx = app(FxService::class);

    $fx->consume($quote);

    $secondUse = fn () => $fx->consume($quote->fresh());

    expect($secondUse)->toThrow(function (DomainException $e) {
        expect($e->errorCode)->toBe(1032)
            ->and($e->errorName)->toBe('quote_consumed');
    });
});

it('keeps the original consumption timestamp when a reuse is rejected', function () {
    $quote = lockedQuote();
    $fx = app(FxService::class);

    $claimedAt = $fx->consume($quote)->consumed_at;

    try {
        $fx->consume($quote->fresh());
    } catch (DomainException) {
        // The rejection is the subject of this test.
    }

    expect($quote->fresh()->consumed_at?->eq($claimedAt))->toBeTrue();
});

it('refuses to consume a quote past its rate lock window', function () {
    $quote = lockedQuote();
    $quote->forceFill(['expires_at' => now()->subMinute()])->save();

    $expiredUse = fn () => app(FxService::class)->consume($quote->fresh());

    expect($expiredUse)->toThrow(function (DomainException $e) {
        expect($e->errorCode)->toBe(1031)
            ->and($e->errorName)->toBe('quote_expired');
    });

    expect($quote->fresh()->consumed_at)->toBeNull();
});

it('rejects an expired quote before anything else looks at it', function () {
    $quote = lockedQuote();
    $quote->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => app(FxService::class)->assertUsable($quote->fresh()))
        ->toThrow(DomainException::class);
});
