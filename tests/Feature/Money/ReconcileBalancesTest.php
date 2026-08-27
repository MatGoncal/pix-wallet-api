<?php

use App\Enums\LedgerDirectionEnum;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exits 0 when a pending hold still matches the ledger net', function () {
    $partner = Partner::factory()->create();

    PartnerBalance::factory()->forPartner($partner)->funded(3500)->create([
        'pending' => 1500,
        'available' => 3500,
    ]);

    BalanceLedgerEntry::query()->create([
        'id' => (string) Str::uuid(),
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'direction' => LedgerDirectionEnum::Credit,
        'amount' => 5000,
        'balance_after' => 5000,
        'reference_type' => 'payment',
        'reference_id' => (string) Str::uuid(),
        'description' => 'Settlement credit',
    ]);

    $this->artisan('acmepay:reconcile')->assertExitCode(0);
});

it('exits 1 and logs delta when a ledger credit has no matching wallet', function () {
    $partner = Partner::factory()->create();

    BalanceLedgerEntry::query()->create([
        'id' => (string) Str::uuid(),
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'direction' => LedgerDirectionEnum::Credit,
        'amount' => 1500,
        'balance_after' => 1500,
        'reference_type' => 'payment',
        'reference_id' => (string) Str::uuid(),
        'description' => 'Orphan credit',
    ]);

    Log::spy();

    $this->artisan('acmepay:reconcile')
        ->expectsOutputToContain('ledger_mismatch')
        ->assertExitCode(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'ledger_mismatch'
            && $context['delta'] === -1500
            && $context['ledger_sum'] === 1500
            && $context['wallet_sum'] === 0)
        ->once();
});

it('exits 1 when available is tampered and pending is zero', function () {
    $partner = Partner::factory()->create();

    PartnerBalance::factory()->forPartner($partner)->funded(9999)->create();

    BalanceLedgerEntry::query()->create([
        'id' => (string) Str::uuid(),
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'direction' => LedgerDirectionEnum::Credit,
        'amount' => 1500,
        'balance_after' => 1500,
        'reference_type' => 'payment',
        'reference_id' => (string) Str::uuid(),
        'description' => 'Settlement credit',
    ]);

    $this->artisan('acmepay:reconcile')
        ->expectsOutputToContain('ledger_mismatch')
        ->assertExitCode(1);
});
