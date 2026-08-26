<?php

use App\Enums\LedgerDirectionEnum;
use App\Enums\PayoutStatusEnum;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\BalanceService;
use App\Services\PayoutService;
use Illuminate\Support\Str;

it('lets only one of two simultaneous debits through when the balance funds one', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(3000)->create();

    // debit() is a public entry point: it has to be safe on its own, not only
    // when a caller like PayoutService happens to wrap it in a transaction.
    $debit = fn (string $reference) => fn () => app(BalanceService::class)->debit(
        partnerId: $partner->id,
        currency: 'BRL',
        amount: 2500,
        referenceType: 'payout',
        referenceId: $reference,
        description: 'Concurrent debit',
    );

    $this->runConcurrently([
        $debit((string) Str::uuid()),
        $debit((string) Str::uuid()),
    ]);

    $balance = PartnerBalance::query()->sole();

    expect($balance->available)->toBe(500)
        ->and(BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->count())->toBe(1);
});

it('credits a payment once even when two workers settle it at the same time', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    // Two deliveries of the same webhook can be picked up by two workers. The
    // check-then-insert this replaced let both pass the existence check.
    $this->runConcurrently([
        fn () => app(BalanceService::class)->creditPayment($payment),
        fn () => app(BalanceService::class)->creditPayment($payment),
        fn () => app(BalanceService::class)->creditPayment($payment),
    ]);

    $balance = PartnerBalance::query()->sole();

    expect($balance->available)->toBe(1500)
        ->and(BalanceLedgerEntry::query()->count())->toBe(1);
});

it('lets only one of two simultaneous payouts through when the balance funds one', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(3000)->create();

    $first = Payout::factory()->forPartner($partner)->ofAmount(2500)->create(['external_id' => 'race-a']);
    $second = Payout::factory()->forPartner($partner)->ofAmount(2500)->create(['external_id' => 'race-b']);

    $this->runConcurrently([
        fn () => app(PayoutService::class)->process($first->id),
        fn () => app(PayoutService::class)->process($second->id),
    ]);

    $statuses = Payout::query()->pluck('status');

    expect($statuses->filter(fn ($status) => $status === PayoutStatusEnum::Completed))->toHaveCount(1)
        ->and($statuses->filter(fn ($status) => $status === PayoutStatusEnum::Failed))->toHaveCount(1)
        ->and(Payout::query()->where('failure_code', '1027')->count())->toBe(1);

    $balance = PartnerBalance::query()->sole();

    expect($balance->available)->toBe(500)
        ->and(BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->count())->toBe(1);
});

it('never overdraws when many payouts race for the same balance', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(3000)->create();

    // Eight payouts of 1000 against 3000 of funding: at most three can win.
    $payouts = collect(range(1, 8))->map(
        fn (int $index) => Payout::factory()
            ->forPartner($partner)
            ->ofAmount(1000)
            ->create(['external_id' => 'race-'.$index]),
    );

    $this->runConcurrently(
        $payouts->map(
            fn (Payout $payout) => fn () => app(PayoutService::class)->process($payout->id),
        )->all(),
    );

    $balance = PartnerBalance::query()->sole();
    $debits = BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->get();

    expect($balance->available)->toBe(0)
        ->and($balance->available)->toBeGreaterThanOrEqual(0)
        ->and(Payout::query()->where('status', PayoutStatusEnum::Completed)->count())->toBe(3)
        ->and(Payout::query()->where('status', PayoutStatusEnum::Failed)->count())->toBe(5)
        ->and($debits)->toHaveCount(3)
        ->and($debits->pluck('balance_after')->sort()->values()->all())->toBe([0, 1000, 2000]);
});

it('keeps the ledger consistent when credits and debits interleave', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(5000)->create();

    $payouts = collect(range(1, 5))->map(
        fn (int $index) => Payout::factory()
            ->forPartner($partner)
            ->ofAmount(600)
            ->create(['external_id' => 'mixed-'.$index]),
    );

    $payments = collect(range(1, 5))->map(
        fn () => Payment::factory()->forPartner($partner)->ofAmount(400)->create(),
    );

    $tasks = $payouts
        ->map(fn (Payout $payout) => fn () => app(PayoutService::class)->process($payout->id))
        ->merge($payments->map(
            fn (Payment $payment) => fn () => app(BalanceService::class)->creditPayment($payment),
        ))
        ->all();

    $this->runConcurrently($tasks);

    $balance = PartnerBalance::query()->sole();
    $entries = BalanceLedgerEntry::query()->get();

    // Every entry that exists must have moved money exactly once, so the final
    // balance is the funding plus the credits minus the debits — no lost update.
    $credited = (int) $entries->where('direction', LedgerDirectionEnum::Credit)->sum('amount');
    $debited = (int) $entries->where('direction', LedgerDirectionEnum::Debit)->sum('amount');

    expect($entries)->toHaveCount(10)
        ->and($credited)->toBe(2000)
        ->and($debited)->toBe(3000)
        ->and($balance->available)->toBe(5000 + $credited - $debited);
});
