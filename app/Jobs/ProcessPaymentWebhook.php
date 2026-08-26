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

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $webhookEventId,
    ) {}

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
        if ($payment->status === PaymentStatusEnum::Paid) {
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
        if ($payment->status === PaymentStatusEnum::Paid) {
            return;
        }

        $payment->forceFill(['status' => $status])->save();
    }
}
