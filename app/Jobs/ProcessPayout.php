<?php

namespace App\Jobs;

use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $payoutId,
    ) {}

    /**
     * Exponential backoff in seconds. Retrying is safe at any point: the payout
     * only leaves `QUEUED` inside the transaction that debits it, so an attempt
     * that failed moved no money and the next one starts from scratch.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 45, 135];
    }

    public function handle(PayoutService $payouts): void
    {
        $payouts->process($this->payoutId);
    }

    public function failed(?Throwable $exception): void
    {
        // The payout is still QUEUED and the balance untouched, so this is an
        // operational alert rather than a money problem.
        Log::error('Payout processing gave up after exhausting retries', [
            'job' => self::class,
            'payout_id' => $this->payoutId,
            'max_attempts' => $this->tries,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
