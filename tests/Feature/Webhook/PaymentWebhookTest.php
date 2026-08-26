<?php

use App\Enums\PaymentStatusEnum;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function webhookPartner(): Partner
{
    return Partner::query()->create([
        'name' => 'Webhook Partner',
        'api_key_hash' => Partner::hashApiKey('webhook_partner_key'),
        'api_key_prefix' => 'webhook_',
        'is_active' => true,
    ]);
}

function signWebhook(array $payload): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = (string) config('acmepay.webhook_secret');
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

    return [$body, $signature];
}

it('rejects webhooks with invalid signature', function () {
    $payload = [
        'event_id' => 'evt_1',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => '550e8400-e29b-41d4-a716-446655440000',
        'occurred_at' => now()->toIso8601String(),
        'data' => ['amount' => 1500, 'currency' => 'BRL'],
    ];

    $this->call(
        'POST',
        '/v1/webhooks/payment',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ACMEPAY_SIGNATURE' => 'sha256=deadbeef',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR),
    )->assertUnauthorized();
});

it('marks payment as paid via signed webhook job', function () {
    config(['queue.default' => 'sync']);

    $partner = webhookPartner();
    $payment = Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $payload = [
        'event_id' => 'evt_paid_1',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'provider_tx_id' => 'pix_tx_1',
            'amount' => 1500,
            'currency' => 'BRL',
        ],
    ];

    [$body, $signature] = signWebhook($payload);

    $this->call(
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
    )->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('duplicate', false);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatusEnum::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->provider_tx_id)->toBe('pix_tx_1');
});

it('is idempotent on duplicate webhook event_id', function () {
    config(['queue.default' => 'sync']);

    $partner = webhookPartner();
    $payment = Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $payload = [
        'event_id' => 'evt_dup_1',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'provider_tx_id' => 'pix_tx_dup',
            'amount' => 1500,
            'currency' => 'BRL',
        ],
    ];

    [$body, $signature] = signWebhook($payload);

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ACMEPAY_SIGNATURE' => $signature,
    ];

    $this->call('POST', '/v1/webhooks/payment', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('duplicate', false);

    $paidAt = $payment->refresh()->paid_at;

    $this->call('POST', '/v1/webhooks/payment', [], [], [], $headers, $body)
        ->assertOk()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('error.code', 1042);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and($payment->refresh()->paid_at?->eq($paidAt))->toBeTrue();
});

it('dispatches process job on first delivery', function () {
    Queue::fake();

    $partner = webhookPartner();
    $payment = Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 100,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $payload = [
        'event_id' => 'evt_queue_1',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => ['amount' => 100, 'currency' => 'BRL'],
    ];

    [$body, $signature] = signWebhook($payload);

    $this->call(
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
    )->assertOk();

    Queue::assertPushed(ProcessPaymentWebhook::class);
});
