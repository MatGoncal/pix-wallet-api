<?php

namespace App\Jobs;

use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayout implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $payoutId,
    ) {}

    public function handle(PayoutService $payouts): void
    {
        $payouts->process($this->payoutId);
    }
}
