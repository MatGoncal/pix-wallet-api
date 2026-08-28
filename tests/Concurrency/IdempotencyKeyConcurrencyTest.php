<?php

use App\Models\IdempotencyKey;
use App\Models\Partner;
use App\Models\Payment;
use App\Services\IdempotencyService;
use App\Services\PaymentService;

it('creates only one payment when two requests race with the same key', function () {
    $partner = Partner::factory()->create();
    $body = json_encode([
        'amount' => 1500,
        'currency' => 'BRL',
    ], JSON_THROW_ON_ERROR);

    $task = function () use ($partner, $body): void {
        app(IdempotencyService::class)->runKeyed(
            $partner,
            'race-pay-1',
            'POST',
            'v1/payments',
            $body,
            function (?string $resourceId) use ($partner) {
                $payment = app(PaymentService::class)->create($partner, [
                    'amount' => 1500,
                    'currency' => 'BRL',
                ], $resourceId);

                return response()->json([
                    'id' => $payment->id,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ], 201);
            },
            retainResource: true,
        );
    };

    $this->runConcurrently([$task, $task]);

    expect(Payment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});
