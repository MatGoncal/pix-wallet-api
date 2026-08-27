<?php

use App\Enums\LedgerDirectionEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PayoutStatusEnum;
use App\Exceptions\DomainException;
use App\Models\BalanceLedgerEntry;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\BalanceService;
use App\Services\FxService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

uses(RefreshDatabase::class);

function moneyPartner(string $rawKey = 'money_partner_key'): Partner
{
    return Partner::query()->create([
        'name' => 'Money Partner',
        'api_key_hash' => Partner::hashApiKey($rawKey),
        'api_key_prefix' => substr($rawKey, 0, 8),
        'is_active' => true,
    ]);
}

function authHeaders(string $key = 'money_partner_key'): array
{
    return ['Authorization' => 'Bearer '.$key];
}

function signBody(array $payload): array
{
    return signWebhook($payload);
}

it('credits partner balance once when payment is paid', function () {
    config(['queue.default' => 'sync']);
    $partner = moneyPartner();

    $payment = Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $payload = [
        'event_id' => 'evt_credit_1',
        'provider' => 'fake_pix',
        'type' => 'payment.paid',
        'payment_id' => $payment->id,
        'occurred_at' => now()->toIso8601String(),
        'data' => ['provider_tx_id' => 'tx1', 'amount' => 1500, 'currency' => 'BRL'],
    ];
    [$body, $signature] = signBody($payload);

    $this->call('POST', '/v1/webhooks/payment', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ACMEPAY_SIGNATURE' => $signature,
    ], $body)->assertOk();

    $balance = PartnerBalance::query()
        ->where('partner_id', $partner->id)
        ->where('currency', 'BRL')
        ->first();

    expect($balance)->not->toBeNull()
        ->and($balance->available)->toBe(1500)
        ->and($balance->pending)->toBe(0)
        ->and(BalanceLedgerEntry::query()->count())->toBe(1);

    $this->getJson('/v1/balances', authHeaders())
        ->assertOk()
        ->assertJsonPath('balances.0.currency', 'BRL')
        ->assertJsonPath('balances.0.available', 1500)
        ->assertJsonPath('balances.0.pending', 0);
});

it('creates an fx quote with rate lock and integer amounts', function () {
    moneyPartner();

    $response = $this->postJson('/v1/fx/quotes', [
        'source_currency' => 'BRL',
        'target_currency' => 'USD',
        'amount' => 10000,
    ], authHeaders());

    $response->assertCreated()
        ->assertJsonPath('source_amount', 10000)
        ->assertJsonPath('target_amount', 1850)
        ->assertJsonPath('rate', '0.18500000')
        ->assertJsonStructure(['quote_id', 'expires_at', 'created_at']);

    expect($response->json('source_amount'))->toBeInt()
        ->and($response->json('target_amount'))->toBeInt()
        ->and($response->json('rate'))->toBeString();
});

it('rejects expired fx quote consumption', function () {
    $partner = moneyPartner();

    $quote = app(FxService::class)->createQuote($partner, [
        'source_currency' => 'BRL',
        'target_currency' => 'USD',
        'amount' => 1000,
    ]);

    $quote->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => app(FxService::class)->assertUsable($quote->fresh()))
        ->toThrow(DomainException::class);
});

it('reserves available into pending on payout create without a ledger debit', function () {
    Queue::fake();
    $partner = moneyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $this->postJson('/v1/payouts', [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'synthetic@acme.test'],
        'external_id' => 'payout-hold',
    ], authHeaders())->assertStatus(202)->assertJsonPath('status', 'QUEUED');

    $balance = PartnerBalance::query()
        ->where('partner_id', $partner->id)
        ->where('currency', 'BRL')
        ->firstOrFail();

    expect($balance->available)->toBe(2500)
        ->and($balance->pending)->toBe(2500)
        ->and(BalanceLedgerEntry::query()->count())->toBe(0)
        ->and(Payout::query()->where('external_id', 'payout-hold')->value('status'))
        ->toBe(PayoutStatusEnum::Queued);
});

it('confirms a reserved payout by debiting pending and writing the ledger once', function () {
    Queue::fake();
    $partner = moneyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $this->postJson('/v1/payouts', [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'synthetic@acme.test'],
        'external_id' => 'payout-1',
    ], authHeaders())->assertStatus(202);

    $payout = Payout::query()->where('external_id', 'payout-1')->firstOrFail();
    app(PayoutService::class)->process($payout->id);

    $balance = PartnerBalance::query()
        ->where('partner_id', $partner->id)
        ->where('currency', 'BRL')
        ->firstOrFail();

    expect($payout->refresh()->status)->toBe(PayoutStatusEnum::Completed)
        ->and($balance->available)->toBe(2500)
        ->and($balance->pending)->toBe(0)
        ->and(BalanceLedgerEntry::query()->where('reference_type', 'payout')->count())->toBe(1);
});

it('rejects a payout create when available cannot cover the amount', function () {
    Queue::fake();
    moneyPartner();

    $this->postJson('/v1/payouts', [
        'amount' => 100,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'x@acme.test'],
        'external_id' => 'payout-fail',
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 1027);

    expect(Payout::query()->count())->toBe(0)
        ->and(BalanceLedgerEntry::query()->count())->toBe(0);
});

it('returns pending to available when the payout job hits a domain failure', function () {
    Queue::fake();
    $partner = moneyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $payout = app(PayoutService::class)->create($partner, [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'x@acme.test'],
    ]);

    expect(PartnerBalance::query()->sole()->pending)->toBe(2500);

    $mock = Mockery::mock(app(BalanceService::class))->makePartial();
    $mock->shouldReceive('confirmDebit')->once()->andThrow(new DomainException(
        1015,
        'settlement_failed',
        'Payout rejected by provider.',
        [],
    ));

    (new PayoutService($mock))->process($payout->id);

    $balance = PartnerBalance::query()->sole();

    expect($payout->refresh()->status)->toBe(PayoutStatusEnum::Failed)
        ->and($payout->failure_code)->toBe('1015')
        ->and($balance->available)->toBe(5000)
        ->and($balance->pending)->toBe(0)
        ->and(BalanceLedgerEntry::query()->count())->toBe(0);
});

it('does not move pending again when the payout job is replayed', function () {
    Queue::fake();
    $partner = moneyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $payout = app(PayoutService::class)->create($partner, [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'x@acme.test'],
        'external_id' => 'payout-replay',
    ]);

    app(PayoutService::class)->process($payout->id);

    $payout->forceFill([
        'status' => PayoutStatusEnum::Queued,
        'completed_at' => null,
    ])->save();

    app(PayoutService::class)->process($payout->id);

    $balance = PartnerBalance::query()->sole();

    expect($payout->refresh()->status)->toBe(PayoutStatusEnum::Completed)
        ->and($balance->available)->toBe(2500)
        ->and($balance->pending)->toBe(0)
        ->and(BalanceLedgerEntry::query()->where('direction', LedgerDirectionEnum::Debit)->count())->toBe(1);
});

it('defines splits that sum to payment amount', function () {
    $partner = moneyPartner();
    $payment = Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $this->postJson('/v1/payments/'.$payment->id.'/splits', [
        'splits' => [
            ['party' => 'platform', 'amount' => 150],
            ['party' => 'seller', 'amount' => 1200],
            ['party' => 'affiliate', 'amount' => 150],
        ],
    ], authHeaders())
        ->assertCreated()
        ->assertJsonPath('payment_id', $payment->id)
        ->assertJsonCount(3, 'splits');

    $this->postJson('/v1/payments/'.$payment->id.'/splits', [
        'splits' => [
            ['party' => 'platform', 'amount' => 100],
            ['party' => 'seller', 'amount' => 100],
        ],
    ], authHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 1015);
});
