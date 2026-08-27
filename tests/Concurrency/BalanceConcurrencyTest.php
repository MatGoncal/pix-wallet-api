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

    $destination = fn (string $id) => [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => $id.'@acme.test'],
        'external_id' => $id,
    ];

    $this->runConcurrently([
        fn () => app(PayoutService::class)->create($partner, $destination('race-a')),
        fn () => app(PayoutService::class)->create($partner, $destination('race-b')),
    ]);

    $balance = PartnerBalance::query()->sole();

    expect(Payout::query()->count())->toBe(1)
        ->and($balance->available + $balance->pending)->toBe(3000)
        ->and($balance->pending)->toBe(2500)
        ->and($balance->available)->toBe(500)
        ->and(BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->count())->toBe(0);
});

it('never overdraws when many payouts race for the same balance', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(3000)->create();

    $this->runConcurrently(
        collect(range(1, 8))->map(
            fn (int $index) => fn () => app(PayoutService::class)->create($partner, [
                'amount' => 1000,
                'currency' => 'BRL',
                'destination' => ['type' => 'pix_key', 'value' => 'race-'.$index.'@acme.test'],
                'external_id' => 'race-'.$index,
            ]),
        )->all(),
    );

    expect(Payout::query()->count())->toBe(3);

    Payout::query()->each(
        fn (Payout $payout) => app(PayoutService::class)->process($payout->id),
    );

    $balance = PartnerBalance::query()->sole();
    $debits = BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->get();

    expect($balance->available)->toBe(0)
        ->and($balance->pending)->toBe(0)
        ->and($balance->available)->toBeGreaterThanOrEqual(0)
        ->and(Payout::query()->where('status', PayoutStatusEnum::Completed)->count())->toBe(3)
        ->and($debits)->toHaveCount(3)
        ->and($debits->pluck('balance_after')->sort()->values()->all())->toBe([0, 1000, 2000]);
});

it('keeps the ledger consistent when credits and payouts interleave', function () {
    $partner = Partner::factory()->create();
    PartnerBalance::factory()->forPartner($partner)->funded(5000)->create();

    $payments = collect(range(1, 5))->map(
        fn () => Payment::factory()->forPartner($partner)->ofAmount(400)->create(),
    );

    $tasks = collect(range(1, 5))
        ->map(fn (int $index) => fn () => tap(
            app(PayoutService::class)->create($partner, [
                'amount' => 600,
                'currency' => 'BRL',
                'destination' => ['type' => 'pix_key', 'value' => 'mixed-'.$index.'@acme.test'],
                'external_id' => 'mixed-'.$index,
            ]),
            fn (Payout $payout) => app(PayoutService::class)->process($payout->id),
        ))
        ->merge($payments->map(
            fn (Payment $payment) => fn () => app(BalanceService::class)->creditPayment($payment),
        ))
        ->all();

    $this->runConcurrently($tasks);

    $balance = PartnerBalance::query()->sole();
    $entries = BalanceLedgerEntry::query()->get();

    $credited = (int) $entries->where('direction', LedgerDirectionEnum::Credit)->sum('amount');
    $debited = (int) $entries->where('direction', LedgerDirectionEnum::Debit)->sum('amount');

    expect($entries)->toHaveCount(10)
        ->and($credited)->toBe(2000)
        ->and($debited)->toBe(3000)
        ->and($balance->available + $balance->pending)->toBe(5000 + $credited - $debited);
});
