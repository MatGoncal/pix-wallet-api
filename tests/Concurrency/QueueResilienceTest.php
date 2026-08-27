<?php

use App\Enums\LedgerDirectionEnum;
use App\Enums\PayoutStatusEnum;
use App\Jobs\ProcessPayout;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Services\BalanceService;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Always throws, so the worker has to exhaust the job's retries.
 */
function unreachableProviderPayouts(): PayoutService
{
    return new class(app(BalanceService::class)) extends PayoutService
    {
        public function process(string $payoutId): void
        {
            throw new RuntimeException('provider unreachable');
        }
    };
}

it('retries a failing payout job and records the failure when it gives up', function () {
    // The database driver keeps attempts in a row we can fast-forward, which
    // beats sleeping through the real exponential backoff.
    config(['queue.default' => 'database']);

    $this->app->bind(PayoutService::class, fn () => unreachableProviderPayouts());

    Log::spy();

    $job = new ProcessPayout('11111111-1111-1111-1111-111111111111');
    dispatch($job);

    expect($job->backoff())->toBe([5, 15, 45, 135])
        ->and($job->tries)->toBe(5);

    // Land on the last allowed attempt instead of waiting out the backoff.
    DB::table('jobs')->update(['attempts' => $job->tries - 1]);

    Artisan::call('queue:work', ['--once' => true, '--quiet' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'Payout processing gave up after exhausting retries'
            && $context['payout_id'] === $job->payoutId
            && $context['exception'] === 'provider unreachable')
        ->once();
});

it('releases a failing payout job for another attempt while retries remain', function () {
    config(['queue.default' => 'database']);

    $this->app->bind(PayoutService::class, fn () => unreachableProviderPayouts());

    dispatch(new ProcessPayout('22222222-2222-2222-2222-222222222222'));

    Artisan::call('queue:work', ['--once' => true, '--quiet' => true]);

    $queued = DB::table('jobs')->sole();

    expect((int) $queued->attempts)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        // Released with the first backoff step rather than retried immediately.
        ->and((int) $queued->available_at)->toBeGreaterThan(now()->getTimestamp());
});

it('debits once when the same payout is processed twice', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(5000)->create();

    $payout = app(PayoutService::class)->create($partner, [
        'amount' => 2000,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'replay@acme.test'],
        'external_id' => 'payout-replay-once',
    ]);

    // A worker can pick the same job up twice: after a crash between the debit
    // and the ack, or when a duplicate was enqueued.
    app(PayoutService::class)->process($payout->id);
    app(PayoutService::class)->process($payout->id);

    expect($payout->refresh()->status)->toBe(PayoutStatusEnum::Completed)
        ->and(PartnerBalance::query()->sole()->available)->toBe(3000)
        ->and(PartnerBalance::query()->sole()->pending)->toBe(0)
        ->and(BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->count())->toBe(1);
});
