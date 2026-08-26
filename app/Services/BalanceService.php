<?php

namespace App\Services;

use App\Enums\LedgerDirectionEnum;
use App\Exceptions\DomainException;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BalanceService
{
    /**
     * @return list<array{currency: string, available: int, pending: int}>
     */
    public function listForPartner(Partner $partner): array
    {
        return PartnerBalance::query()
            ->where('partner_id', $partner->id)
            ->orderBy('currency')
            ->get()
            ->map(fn (PartnerBalance $row) => [
                'currency' => $row->currency,
                'available' => $row->available,
                'pending' => $row->pending,
            ])
            ->all();
    }

    public function creditPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $exists = BalanceLedgerEntry::query()
                ->where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->where('direction', LedgerDirectionEnum::Credit)
                ->exists();

            if ($exists) {
                return;
            }

            $this->apply(
                partnerId: $payment->partner_id,
                currency: $payment->currency,
                direction: LedgerDirectionEnum::Credit,
                amount: $payment->amount,
                referenceType: 'payment',
                referenceId: $payment->id,
                description: 'Settlement credit',
            );
        });
    }

    /**
     * @throws DomainException
     */
    public function debit(
        string $partnerId,
        string $currency,
        int $amount,
        string $referenceType,
        string $referenceId,
        string $description,
    ): PartnerBalance {
        return $this->apply(
            partnerId: $partnerId,
            currency: $currency,
            direction: LedgerDirectionEnum::Debit,
            amount: $amount,
            referenceType: $referenceType,
            referenceId: $referenceId,
            description: $description,
        );
    }

    /**
     * @throws DomainException
     */
    private function apply(
        string $partnerId,
        string $currency,
        LedgerDirectionEnum $direction,
        int $amount,
        string $referenceType,
        string $referenceId,
        string $description,
    ): PartnerBalance {
        if ($amount <= 0) {
            throw new DomainException(
                1015,
                'settlement_failed',
                'Amount must be a positive integer in minor units.',
                ['amount' => $amount],
            );
        }

        $balance = PartnerBalance::query()
            ->where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            $balance = PartnerBalance::query()->create([
                'id' => (string) Str::uuid(),
                'partner_id' => $partnerId,
                'currency' => $currency,
                'available' => 0,
                'pending' => 0,
            ]);

            $balance = PartnerBalance::query()
                ->whereKey($balance->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        if ($direction === LedgerDirectionEnum::Debit && $balance->available < $amount) {
            throw new DomainException(
                1027,
                'insufficient_balance',
                'Partner balance is insufficient for this debit.',
                [
                    'currency' => $currency,
                    'available' => $balance->available,
                    'required' => $amount,
                ],
            );
        }

        $next = $direction === LedgerDirectionEnum::Credit
            ? $balance->available + $amount
            : $balance->available - $amount;

        $balance->forceFill(['available' => $next])->save();

        BalanceLedgerEntry::query()->create([
            'id' => (string) Str::uuid(),
            'partner_id' => $partnerId,
            'currency' => $currency,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $next,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ]);

        return $balance;
    }
}
