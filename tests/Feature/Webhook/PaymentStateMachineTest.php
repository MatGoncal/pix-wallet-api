<?php

use App\Enums\PaymentStatusEnum;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array{0: string, 1: string}
 */
function settlementEvent(Payment $payment, string $eventId, array $overrides = []): array
{
    $payload = array_replace_recursive([
        'event_id' => $eventId,
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'provider_tx_id' => 'pix_tx_'.$eventId,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ],
    ], $overrides);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = (string) config('acmepay.webhook_secret');

    return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
}

function deliverWebhook(string $body, string $signature): TestResponse
{
    return test()->call('POST', '/v1/webhooks/payment', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ACMEPAY_SIGNATURE' => $signature,
    ], $body);
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
});

it('does not settle a payment that already expired', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->expired()->create();

    [$body, $signature] = settlementEvent($payment, 'evt_expired_then_paid');

    deliverWebhook($body, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Expired)
        ->and($payment->paid_at)->toBeNull()
        ->and(BalanceLedgerEntry::query()->count())->toBe(0)
        ->and(PartnerBalance::query()->count())->toBe(0);
});

it('does not reopen a failed payment', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()
        ->forPartner($partner)
        ->ofAmount(1500)
        ->status(PaymentStatusEnum::Failed)
        ->create();

    [$body, $signature] = settlementEvent($payment, 'evt_failed_then_paid');

    deliverWebhook($body, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Failed)
        ->and(BalanceLedgerEntry::query()->count())->toBe(0);
});

it('keeps a paid payment on its original expiry event', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()
        ->forPartner($partner)
        ->ofAmount(1500)
        ->status(PaymentStatusEnum::Paid)
        ->create();

    [$body, $signature] = settlementEvent($payment, 'evt_paid_then_expired', [
        'type' => 'payment.expired',
    ]);

    deliverWebhook($body, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Paid);
});

it('refuses to credit a settlement whose amount does not match the charge', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    [$body, $signature] = settlementEvent($payment, 'evt_wrong_amount', [
        'data' => ['amount' => 9900],
    ]);

    deliverWebhook($body, $signature)->assertOk();

    // The charge stays open: a corrected event must still be able to settle it.
    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Pending)
        ->and($payment->paid_at)->toBeNull()
        ->and(BalanceLedgerEntry::query()->count())->toBe(0)
        ->and(PartnerBalance::query()->count())->toBe(0);
});

it('refuses to credit a settlement in another currency', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    [$body, $signature] = settlementEvent($payment, 'evt_wrong_currency', [
        'data' => ['currency' => 'USD'],
    ]);

    deliverWebhook($body, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Pending)
        ->and(BalanceLedgerEntry::query()->count())->toBe(0);
});

it('rejects a settlement event that omits the amount', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    $payload = [
        'event_id' => 'evt_no_amount',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => ['provider_tx_id' => 'pix_tx_no_amount'],
    ];

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $body, (string) config('acmepay.webhook_secret'));

    deliverWebhook($body, $signature)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['data.amount', 'data.currency']);

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Pending);
});

it('still accepts an expiry event without settlement data', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    $payload = [
        'event_id' => 'evt_expired_no_amount',
        'provider' => 'fake_pix',
        'type' => 'payment.expired',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => [],
    ];

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $body, (string) config('acmepay.webhook_secret'));

    deliverWebhook($body, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Expired);
});
