<?php

use App\Enums\LedgerDirectionEnum;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use App\Services\BalanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('credits a payment only once no matter how often it is replayed', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    $balances = app(BalanceService::class);

    $balances->creditPayment($payment);
    $balances->creditPayment($payment);
    $balances->creditPayment($payment);

    $balance = PartnerBalance::query()
        ->where('partner_id', $partner->id)
        ->where('currency', 'BRL')
        ->sole();

    expect($balance->available)->toBe(1500)
        ->and(BalanceLedgerEntry::query()->count())->toBe(1);
});

it('keeps the surrounding transaction usable after a duplicate credit', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(2000)->create();

    $balances = app(BalanceService::class);
    $balances->creditPayment($payment);

    // The webhook job credits from inside its own transaction; a duplicate must
    // roll back to a savepoint rather than poisoning the whole transaction.
    DB::transaction(function () use ($balances, $payment) {
        $balances->creditPayment($payment);

        $payment->forceFill(['provider_tx_id' => 'tx_after_duplicate'])->save();
    });

    expect($payment->refresh()->provider_tx_id)->toBe('tx_after_duplicate')
        ->and(BalanceLedgerEntry::query()->count())->toBe(1)
        ->and(PartnerBalance::query()->sole()->available)->toBe(2000);
});

it('lets a debit reuse the reference of an earlier credit', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    $balances = app(BalanceService::class);
    $balances->creditPayment($payment);

    // Direction is part of the key: a refund of the same payment is a distinct
    // entry, not a duplicate.
    $balances->debit(
        partnerId: $partner->id,
        currency: 'BRL',
        amount: 1500,
        referenceType: 'payment',
        referenceId: $payment->id,
        description: 'Chargeback',
    );

    expect(BalanceLedgerEntry::query()->count())->toBe(2)
        ->and(PartnerBalance::query()->sole()->available)->toBe(0);
});

it('rejects a duplicate ledger row written straight to the database', function () {
    $partner = Partner::factory()->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    app(BalanceService::class)->creditPayment($payment);

    $insertDuplicate = fn () => DB::table('balance_ledger')->insert([
        'id' => (string) Str::uuid(),
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'direction' => LedgerDirectionEnum::Credit->value,
        'amount' => 1500,
        'balance_after' => 3000,
        'reference_type' => 'payment',
        'reference_id' => $payment->id,
        'description' => 'Smuggled second credit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insertDuplicate)->toThrow(QueryException::class);
});
