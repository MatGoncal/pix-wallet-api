<?php

namespace App\Jobs;

use App\Enums\PaymentStatusEnum;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\BalanceService;
use App\Services\SplitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $webhookEventId,
    ) {}

    /**
     * Exponential backoff in seconds. A contended row or a database blip clears
     * in seconds, while a real outage should not be hammered by every worker at
     * once — the last attempt lands a bit over three minutes after the first.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 45, 135];
    }

    public function failed(?Throwable $exception): void
    {
        // The event row is still there, unprocessed: the failed job keeps the
        // payload for a replay and this line is what makes the gap visible.
        Log::error('Webhook processing gave up after exhausting retries', [
            'job' => self::class,
            'webhook_event_id' => $this->webhookEventId,
            'max_attempts' => $this->tries,
            'exception' => $exception?->getMessage(),
        ]);
    }

    public function handle(BalanceService $balances, SplitService $splits): void
    {
        DB::transaction(function () use ($balances, $splits) {
            /** @var WebhookEvent|null $event */
            $event = WebhookEvent::query()
                ->whereKey($this->webhookEventId)
                ->lockForUpdate()
                ->first();

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->whereKey($event->payment_id)
                ->lockForUpdate()
                ->first();

            if ($payment !== null) {
                match ($event->type) {
                    'payment.paid' => $this->markPaid($payment, $event, $balances, $splits),
                    'payment.expired' => $this->markStatus($payment, PaymentStatusEnum::Expired),
                    'payment.failed' => $this->markStatus($payment, PaymentStatusEnum::Failed),
                    default => null,
                };
            }

            $event->forceFill(['processed_at' => now()])->save();
        });
    }

    private function markPaid(
        Payment $payment,
        WebhookEvent $event,
        BalanceService $balances,
        SplitService $splits,
    ): void {
        if (! $payment->status->canTransitionTo(PaymentStatusEnum::Paid)) {
            Log::warning('Ignored payment.paid for a closed payment', [
                'payment_id' => $payment->id,
                'webhook_event_id' => $event->id,
                'status' => $payment->status->value,
            ]);

            return;
        }

        if (! $this->payloadMatchesPayment($payment, $event)) {
            return;
        }

        try {
            $splits->assertValidForSettlement($payment);
        } catch (DomainException $e) {
            Log::warning('Settlement failed', [
                'payment_id' => $payment->id,
                'error_code' => $e->errorCode,
            ]);
            $payment->forceFill(['status' => PaymentStatusEnum::Failed])->save();

            return;
        }

        $payload = $event->payload;
        $providerTxId = is_array($payload)
            ? ($payload['data']['provider_tx_id'] ?? null)
            : null;

        $payment->forceFill([
            'status' => PaymentStatusEnum::Paid,
            'paid_at' => now(),
            'provider_tx_id' => is_string($providerTxId) ? $providerTxId : $payment->provider_tx_id,
        ])->save();

        $balances->creditPayment($payment);
    }

    private function markStatus(Payment $payment, PaymentStatusEnum $status): void
    {
        if (! $payment->status->canTransitionTo($status)) {
            return;
        }

        $payment->forceFill(['status' => $status])->save();
    }

    /**
     * The provider tells us what it settled; we only credit what we charged.
     * A divergence is a reconciliation problem, so the payment stays open for a
     * corrected event instead of being credited or closed on a wrong figure.
     */
    private function payloadMatchesPayment(Payment $payment, WebhookEvent $event): bool
    {
        $payload = $event->payload;
        $data = is_array($payload) && is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        $amount = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : null;
        $currency = is_string($data['currency'] ?? null)
            ? strtoupper($data['currency'])
            : null;

        if ($amount === $payment->amount && $currency === $payment->currency) {
            return true;
        }

        Log::warning('Settlement rejected: webhook does not match the payment', [
            'payment_id' => $payment->id,
            'webhook_event_id' => $event->id,
            'error_code' => 1015,
            'expected' => ['amount' => $payment->amount, 'currency' => $payment->currency],
            'received' => ['amount' => $amount, 'currency' => $currency],
        ]);

        return false;
    }
}
