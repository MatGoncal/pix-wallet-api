<?php

namespace App\Services;

use App\Enums\PaymentStatusEnum;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentSplit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SplitService
{
    /**
     * @param  list<array{party: string, amount: int}>  $splits
     * @return Collection<int, PaymentSplit>
     *
     * @throws DomainException
     */
    public function define(Partner $partner, string $paymentId, array $splits): Collection
    {
        return DB::transaction(function () use ($partner, $paymentId, $splits) {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->where('partner_id', $partner->id)
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new DomainException(
                    404,
                    'not_found',
                    'Payment not found.',
                    [],
                    404,
                );
            }

            if ($payment->status === PaymentStatusEnum::Cancelled) {
                throw new DomainException(
                    1015,
                    'settlement_failed',
                    'Cannot define splits on a cancelled payment.',
                    ['payment_id' => $payment->id],
                );
            }

            $sum = 0;
            foreach ($splits as $line) {
                $sum += $line['amount'];
            }

            if ($sum !== $payment->amount) {
                throw new DomainException(
                    1015,
                    'settlement_failed',
                    'Split amounts must equal payment amount.',
                    [
                        'payment_amount' => $payment->amount,
                        'splits_sum' => $sum,
                    ],
                );
            }

            PaymentSplit::query()->where('payment_id', $payment->id)->delete();

            $created = collect();
            foreach ($splits as $line) {
                $created->push(PaymentSplit::query()->create([
                    'id' => (string) Str::uuid(),
                    'payment_id' => $payment->id,
                    'party' => $line['party'],
                    'amount' => $line['amount'],
                ]));
            }

            return $created;
        });
    }

    /**
     * Validate existing splits at settlement time (optional guard).
     *
     * @throws DomainException
     */
    public function assertValidForSettlement(Payment $payment): void
    {
        $lines = PaymentSplit::query()->where('payment_id', $payment->id)->get();

        if ($lines->isEmpty()) {
            return;
        }

        $sum = (int) $lines->sum('amount');

        if ($sum !== $payment->amount) {
            throw new DomainException(
                1015,
                'settlement_failed',
                'Split allocation invalid at settlement.',
                [
                    'payment_id' => $payment->id,
                    'payment_amount' => $payment->amount,
                    'splits_sum' => $sum,
                ],
            );
        }
    }
}
