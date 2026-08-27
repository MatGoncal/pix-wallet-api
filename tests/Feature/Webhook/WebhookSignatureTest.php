<?php

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function signaturePayment(): Payment
{
    $partner = Partner::query()->create([
        'name' => 'Signature Partner',
        'api_key_hash' => Partner::hashApiKey('signature_partner_key'),
        'api_key_prefix' => 'signatur',
        'is_active' => true,
    ]);

    return Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);
}

function signedPaidPayload(Payment $payment, string $eventId): array
{
    return [
        'event_id' => $eventId,
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'provider_tx_id' => 'pix_tx_'.$eventId,
            'amount' => 1500,
            'currency' => 'BRL',
        ],
    ];
}

function postSignedWebhook(string $body, string $signature): TestResponse
{
    return test()->call(
        'POST',
        '/v1/webhooks/payment',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ACMEPAY_SIGNATURE' => $signature,
        ],
        $body,
    );
}

it('rejects a timestamp older than the tolerance window with 1044', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-26T18:00:00Z'));

    $payment = signaturePayment();
    $payload = signedPaidPayload($payment, 'evt_old_ts');
    [$body, $signature] = signWebhook($payload, now()->subSeconds(301)->getTimestamp());

    postSignedWebhook($body, $signature)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 1044)
        ->assertJsonPath('error.name', 'webhook_timestamp_expired');
});

it('rejects a timestamp in the future beyond the tolerance window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-26T18:00:00Z'));

    $payment = signaturePayment();
    $payload = signedPaidPayload($payment, 'evt_future_ts');
    [$body, $signature] = signWebhook($payload, now()->addSeconds(301)->getTimestamp());

    postSignedWebhook($body, $signature)->assertUnauthorized();
});

it('rejects a valid timestamp with the wrong v1', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-26T18:00:00Z'));

    $payment = signaturePayment();
    $payload = signedPaidPayload($payment, 'evt_bad_v1');
    [$body] = signWebhook($payload);
    $signature = 't='.now()->getTimestamp().',v1='.str_repeat('ab', 32);

    postSignedWebhook($body, $signature)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 401);
});

it('accepts a signed webhook when the clock is frozen inside the window', function () {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow(Carbon::parse('2026-08-26T18:00:00Z'));

    $payment = signaturePayment();
    $payload = signedPaidPayload($payment, 'evt_frozen');
    [$body, $signature] = signWebhook($payload, now()->getTimestamp());

    postSignedWebhook($body, $signature)
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('duplicate', false);

    expect($payment->refresh()->status)->toBe(PaymentStatusEnum::Paid);
});
