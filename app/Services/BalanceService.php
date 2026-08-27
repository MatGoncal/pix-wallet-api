<?php

namespace App\Services;

use App\Enums\LedgerDirectionEnum;
use App\Exceptions\DomainException;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BalanceService
{
    private const REFERENCE_UNIQUE_CONSTRAINT = 'balance_ledger_reference_unique';

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
        $this->applyOnce(
            partnerId: $payment->partner_id,
            currency: $payment->currency,
            direction: LedgerDirectionEnum::Credit,
            amount: $payment->amount,
            referenceType: 'payment',
            referenceId: $payment->id,
            description: 'Settlement credit',
        );
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
        return $this->applyOnce(
            partnerId: $partnerId,
            currency: $currency,
            direction: LedgerDirectionEnum::Debit,
            amount: $amount,
            referenceType: $referenceType,
            referenceId: $referenceId,
            description: $description,
        ) ?? $this->currentBalance($partnerId, $currency);
    }

    /**
     * Hold funds for a queued payout. No ledger row — the money is still the
     * platform's until confirmDebit.
     *
     * @throws DomainException
     */
    public function reserve(string $partnerId, string $currency, int $amount): PartnerBalance
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($partnerId, $currency, $amount) {
            $this->ensureBalanceRow($partnerId, $currency);

            $affected = DB::table('partner_balances')
                ->where('partner_id', $partnerId)
                ->where('currency', $currency)
                ->where('available', '>=', $amount)
                ->update([
                    'available' => DB::raw(sprintf('available - %d', $amount)),
                    'pending' => DB::raw(sprintf('pending + %d', $amount)),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw $this->insufficientBalance($partnerId, $currency, $amount, 'available');
            }

            return $this->currentBalance($partnerId, $currency);
        });
    }

    /**
     * Return a hold to available (payout FAILED). No ledger row.
     *
     * @throws DomainException
     */
    public function release(string $partnerId, string $currency, int $amount): PartnerBalance
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($partnerId, $currency, $amount) {
            $affected = DB::table('partner_balances')
                ->where('partner_id', $partnerId)
                ->where('currency', $currency)
                ->where('pending', '>=', $amount)
                ->update([
                    'pending' => DB::raw(sprintf('pending - %d', $amount)),
                    'available' => DB::raw(sprintf('available + %d', $amount)),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw $this->insufficientBalance($partnerId, $currency, $amount, 'pending');
            }

            return $this->currentBalance($partnerId, $currency);
        });
    }

    /**
     * Consume a hold and write the ledger debit. Returns null when this
     * reference was already applied so a job replay does not touch pending.
     *
     * @throws DomainException
     */
    public function confirmDebit(
        string $partnerId,
        string $currency,
        int $amount,
        string $referenceType,
        string $referenceId,
        string $description,
    ): ?PartnerBalance {
        $this->assertPositiveAmount($amount);

        try {
            return DB::transaction(function () use (
                $partnerId,
                $currency,
                $amount,
                $referenceType,
                $referenceId,
                $description,
            ) {
                $this->ensureBalanceRow($partnerId, $currency);

                // Claim the reference before moving pending so a replay hits
                // the unique index and never decrements twice.
                BalanceLedgerEntry::query()->create([
                    'id' => (string) Str::uuid(),
                    'partner_id' => $partnerId,
                    'currency' => $currency,
                    'direction' => LedgerDirectionEnum::Debit,
                    'amount' => $amount,
                    'balance_after' => 0,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => $description,
                ]);

                $affected = DB::table('partner_balances')
                    ->where('partner_id', $partnerId)
                    ->where('currency', $currency)
                    ->where('pending', '>=', $amount)
                    ->update([
                        'pending' => DB::raw(sprintf('pending - %d', $amount)),
                        'updated_at' => now(),
                    ]);

                if ($affected === 0) {
                    throw $this->insufficientBalance($partnerId, $currency, $amount, 'pending');
                }

                $balance = $this->currentBalance($partnerId, $currency);

                BalanceLedgerEntry::query()
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('direction', LedgerDirectionEnum::Debit)
                    ->update([
                        'balance_after' => $balance->available + $balance->pending,
                    ]);

                return $balance;
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateReference($e)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Returns null when the reference had already been applied.
     *
     * @throws DomainException
     */
    private function applyOnce(
        string $partnerId,
        string $currency,
        LedgerDirectionEnum $direction,
        int $amount,
        string $referenceType,
        string $referenceId,
        string $description,
    ): ?PartnerBalance {
        try {
            return $this->apply(
                partnerId: $partnerId,
                currency: $currency,
                direction: $direction,
                amount: $amount,
                referenceType: $referenceType,
                referenceId: $referenceId,
                description: $description,
            );
        } catch (QueryException $e) {
            if (! $this->isDuplicateReference($e)) {
                throw $e;
            }

            // The entry already exists, so the money already moved. apply() runs
            // in its own transaction (a savepoint when nested), which means the
            // balance update it attempted was rolled back with the failed insert.
            return null;
        }
    }

    /**
     * @throws DomainException
     * @throws QueryException when the reference has already been applied
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

        return DB::transaction(function () use (
            $partnerId,
            $currency,
            $direction,
            $amount,
            $referenceType,
            $referenceId,
            $description,
        ) {
            $this->ensureBalanceRow($partnerId, $currency);

            $query = DB::table('partner_balances')
                ->where('partner_id', $partnerId)
                ->where('currency', $currency);

            // The guard travels with the write, so a debit can never observe a
            // balance that another transaction has already spent.
            if ($direction === LedgerDirectionEnum::Debit) {
                $query->where('available', '>=', $amount);
            }

            $affected = $query->update([
                'available' => DB::raw(sprintf(
                    'available %s %d',
                    $direction === LedgerDirectionEnum::Credit ? '+' : '-',
                    $amount,
                )),
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                throw new DomainException(
                    1027,
                    'insufficient_balance',
                    'Partner balance is insufficient for this debit.',
                    [
                        'currency' => $currency,
                        'available' => (int) DB::table('partner_balances')
                            ->where('partner_id', $partnerId)
                            ->where('currency', $currency)
                            ->value('available'),
                        'required' => $amount,
                    ],
                );
            }

            $balance = $this->currentBalance($partnerId, $currency);

            BalanceLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'partner_id' => $partnerId,
                'currency' => $currency,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balance->available,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);

            return $balance;
        });
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new DomainException(
                1015,
                'settlement_failed',
                'Amount must be a positive integer in minor units.',
                ['amount' => $amount],
            );
        }
    }

    /**
     * @param  'available'|'pending'  $column
     */
    private function insufficientBalance(
        string $partnerId,
        string $currency,
        int $amount,
        string $column,
    ): DomainException {
        return new DomainException(
            1027,
            'insufficient_balance',
            'Partner balance is insufficient for this debit.',
            [
                'currency' => $currency,
                $column => (int) DB::table('partner_balances')
                    ->where('partner_id', $partnerId)
                    ->where('currency', $currency)
                    ->value($column),
                'required' => $amount,
            ],
        );
    }

    private function ensureBalanceRow(string $partnerId, string $currency): void
    {
        DB::table('partner_balances')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'partner_id' => $partnerId,
            'currency' => $currency,
            'available' => 0,
            'pending' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function currentBalance(string $partnerId, string $currency): PartnerBalance
    {
        return PartnerBalance::query()
            ->where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->firstOrFail();
    }

    private function isDuplicateReference(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505'
            && str_contains($e->getMessage(), self::REFERENCE_UNIQUE_CONSTRAINT);
    }
}
