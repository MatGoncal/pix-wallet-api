<?php

namespace App\Services;

use App\Enums\PayoutStatusEnum;
use App\Exceptions\DomainException;
use App\Jobs\ProcessPayout;
use App\Models\Partner;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayoutService
{
    public function __construct(
        private readonly BalanceService $balances,
    ) {}

    /**
     * @param  array{amount: int, currency: string, destination: array{type: string, value: string}, external_id?: string|null}  $data
     */
    public function create(Partner $partner, array $data): Payout
    {
        $payout = Payout::query()->create([
            'id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'status' => PayoutStatusEnum::Queued,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'destination_type' => $data['destination']['type'],
            'destination_value' => $data['destination']['value'],
            'external_id' => $data['external_id'] ?? null,
        ]);

        ProcessPayout::dispatch($payout->id);

        return $payout;
    }

    public function process(string $payoutId): void
    {
        DB::transaction(function () use ($payoutId) {
            /** @var Payout|null $payout */
            $payout = Payout::query()->whereKey($payoutId)->lockForUpdate()->first();

            if ($payout === null || $payout->status !== PayoutStatusEnum::Queued) {
                return;
            }

            $payout->forceFill(['status' => PayoutStatusEnum::Processing])->save();

            try {
                $this->balances->debit(
                    partnerId: $payout->partner_id,
                    currency: $payout->currency,
                    amount: $payout->amount,
                    referenceType: 'payout',
                    referenceId: $payout->id,
                    description: 'Payout debit on confirm',
                );

                $payout->forceFill([
                    'status' => PayoutStatusEnum::Completed,
                    'completed_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
            } catch (DomainException $e) {
                $payout->forceFill([
                    'status' => PayoutStatusEnum::Failed,
                    'failure_code' => (string) $e->errorCode,
                    'failure_message' => $e->getMessage(),
                ])->save();
            }
        });
    }
}
