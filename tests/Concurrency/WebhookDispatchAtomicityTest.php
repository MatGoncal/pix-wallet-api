<?php

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function atomicitySignedWebhook(Payment $payment, string $eventId): array
{
    $payload = [
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
    ];

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = (string) config('acmepay.webhook_secret');

    return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
}

it('enqueues the webhook job only after the transaction commits', function () {
    $partner = Partner::factory()->withApiKey('atomicity_key')->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    [$body, $signature] = atomicitySignedWebhook($payment, 'evt_atomic_ok');

    $this->call('POST', '/v1/webhooks/payment', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ACMEPAY_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $event = WebhookEvent::query()->sole();

    expect($this->queueSize())->toBe(1);

    $payload = json_decode(
        (string) Queue::connection('redis')->pop('default')->getRawBody(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($payload['displayName'])->toBe(ProcessPaymentWebhook::class)
        ->and($payload['data']['command'])->toContain($event->id);
});

it('leaves no orphan job behind when the surrounding transaction rolls back', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->create();

    try {
        DB::transaction(function () use ($payment) {
            $event = WebhookEvent::query()->create([
                'provider' => 'fake_pix',
                'event_id' => 'evt_atomic_rollback',
                'type' => 'payment.paid',
                'payload' => ['payment_id' => $payment->id],
                'payment_id' => $payment->id,
            ]);

            ProcessPaymentWebhook::dispatch($event->id)->afterCommit();

            throw new RuntimeException('something downstream failed after the insert');
        });
    } catch (RuntimeException) {
        // The rollback is the subject of this test.
    }

    // Redis is not part of the rollback: a job pushed eagerly would survive and
    // be retried against an event row that never existed.
    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($this->queueSize())->toBe(0);
});
