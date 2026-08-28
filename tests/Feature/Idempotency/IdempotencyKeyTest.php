<?php

use App\Models\IdempotencyKey;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function idempotencyPartner(string $rawKey = 'idem_partner_key'): Partner
{
    return Partner::query()->create([
        'name' => 'Idempotency Partner',
        'api_key_hash' => Partner::hashApiKey($rawKey),
        'api_key_prefix' => substr($rawKey, 0, 8),
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $body
 */
function fakePixChargeSequence(array $body, int $firstStatus, int $secondStatus): void
{
    $factory = new Factory;
    $factory->fake([
        config('acmepay.fake_pix_base_url').'/v1/charges' => Http::sequence()
            ->push($body, $firstStatus)
            ->push($body, $secondStatus),
    ]);
    Http::swap($factory);
}

/**
 * @param  array<string, mixed>  $body
 */
function fakePixChargeResponse(array $body, int $status): void
{
    $factory = new Factory;
    $factory->fake([
        config('acmepay.fake_pix_base_url').'/v1/charges' => Http::response($body, $status),
    ]);
    Http::swap($factory);
}

function fakePixChargeDown(): void
{
    $factory = new Factory;
    $factory->fake(function () {
        throw new ConnectionException('Connection refused');
    });
    Http::swap($factory);
}

it('creates a payment without an Idempotency-Key', function () {
    idempotencyPartner();

    $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
    ], [
        'Authorization' => 'Bearer idem_partner_key',
    ])->assertCreated()
        ->assertJsonPath('status', 'PENDING')
        ->assertJsonPath('amount', 1500);

    expect(Payment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('replays the same payment when the key and body match', function () {
    idempotencyPartner();

    $headers = [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'pay-1',
    ];
    $payload = [
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-idem-1',
    ];

    $first = $this->postJson('/v1/payments', $payload, $headers)
        ->assertCreated();

    $second = $this->postJson('/v1/payments', $payload, $headers)
        ->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json('status'))->toBe('PENDING')
        ->and(Payment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->sole()->resource_id)->toBe($first->json('id'));
});

it('rejects the same key with a different body as 1043', function () {
    idempotencyPartner();

    $headers = [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'pay-conflict',
    ];

    $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
    ], $headers)->assertCreated();

    $this->postJson('/v1/payments', [
        'amount' => 2000,
        'currency' => 'BRL',
    ], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 1043)
        ->assertJsonPath('error.name', 'idempotency_conflict');

    expect(Payment::query()->count())->toBe(1);
});

it('replays the same payout when the key and body match', function () {
    Queue::fake();
    $partner = idempotencyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $headers = [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'payout-1',
    ];
    $payload = [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => [
            'type' => 'pix_key',
            'value' => 'synthetic@acme.test',
        ],
        'external_id' => 'payout-idem-1',
    ];

    $first = $this->postJson('/v1/payouts', $payload, $headers)
        ->assertAccepted();

    $second = $this->postJson('/v1/payouts', $payload, $headers)
        ->assertAccepted();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json('status'))->toBe('QUEUED')
        ->and(Payout::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(PartnerBalance::query()->sole()->pending)->toBe(2500)
        ->and(PartnerBalance::query()->sole()->available)->toBe(2500);
});

it('rejects a reused payout key with a different body as 1043', function () {
    Queue::fake();
    $partner = idempotencyPartner();

    PartnerBalance::query()->create([
        'partner_id' => $partner->id,
        'currency' => 'BRL',
        'available' => 5000,
        'pending' => 0,
    ]);

    $headers = [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'payout-conflict',
    ];

    $this->postJson('/v1/payouts', [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'a@acme.test'],
    ], $headers)->assertAccepted();

    $this->postJson('/v1/payouts', [
        'amount' => 1000,
        'currency' => 'BRL',
        'destination' => ['type' => 'pix_key', 'value' => 'a@acme.test'],
    ], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 1043);

    expect(Payout::query()->count())->toBe(1);
});

it('resumes the same payment UUID after a throw past createCharge', function () {
    $charge = [
        'id' => 'chg_retry',
        'status' => 'PENDING',
        'qr_code' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.retry',
        'copy_paste' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.retry',
        'provider_tx_id' => 'pix_tx_retry',
    ];

    fakePixChargeSequence($charge, 201, 200);

    $failedOnce = false;
    Payment::saving(function () use (&$failedOnce): void {
        if (! $failedOnce) {
            $failedOnce = true;
            throw new RuntimeException('forced save failure after createCharge');
        }
    });

    try {
        idempotencyPartner();

        $headers = [
            'Authorization' => 'Bearer idem_partner_key',
            'Idempotency-Key' => 'pay-retry-charge',
        ];
        $payload = [
            'amount' => 1500,
            'currency' => 'BRL',
            'external_id' => 'order-retry-charge',
        ];

        $this->postJson('/v1/payments', $payload, $headers)
            ->assertStatus(500);

        expect(Payment::query()->count())->toBe(0)
            ->and(IdempotencyKey::query()->count())->toBe(1);

        $resourceId = IdempotencyKey::query()->sole()->resource_id;
        expect($resourceId)->not->toBeNull();

        $second = $this->postJson('/v1/payments', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING');

        expect($second->json('id'))->toBe($resourceId)
            ->and(Payment::query()->count())->toBe(1)
            ->and(IdempotencyKey::query()->count())->toBe(1);

        $this->assertDatabaseHas('payments', [
            'id' => $resourceId,
            'provider_charge_id' => 'chg_retry',
            'provider_tx_id' => null,
        ]);

        $recorded = Http::recorded();
        expect($recorded)->toHaveCount(2);

        /** @var Request $firstOutbound */
        $firstOutbound = $recorded[0][0];
        /** @var Request $secondOutbound */
        $secondOutbound = $recorded[1][0];

        expect($firstOutbound->data()['payment_id'])->toBe($resourceId)
            ->and($secondOutbound->data()['payment_id'])->toBe($resourceId);
    } finally {
        Payment::getEventDispatcher()->forget('eloquent.saving: '.Payment::class);
    }
});

it('keeps the payment idempotency key on 502 and retries with the same payment_id', function () {
    fakePixChargeDown();

    idempotencyPartner();

    $headers = [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'pay-retry-502',
    ];
    $payload = [
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-retry-502',
    ];

    $this->postJson('/v1/payments', $payload, $headers)
        ->assertStatus(502)
        ->assertJsonPath('error.name', 'bad_gateway');

    expect(Payment::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(1);

    $resourceId = IdempotencyKey::query()->sole()->resource_id;
    expect($resourceId)->not->toBeNull();

    fakePixChargeResponse([
        'id' => 'chg_after_502',
        'status' => 'PENDING',
        'qr_code' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.after502',
        'copy_paste' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.after502',
        'provider_tx_id' => 'pix_tx_after_502',
    ], 200);

    $second = $this->postJson('/v1/payments', $payload, $headers)
        ->assertCreated();

    expect($second->json('id'))->toBe($resourceId)
        ->and(Payment::query()->count())->toBe(1);

    $this->assertDatabaseHas('payments', [
        'id' => $resourceId,
        'provider_charge_id' => 'chg_after_502',
        'provider_tx_id' => null,
    ]);

    $recorded = Http::recorded();
    expect($recorded)->not->toBeEmpty();
    /** @var Request $outbound */
    $outbound = $recorded[0][0];
    expect($outbound->data()['payment_id'])->toBe($resourceId);
});

it('deletes the payout idempotency key when create throws', function () {
    Queue::fake();
    idempotencyPartner();

    $this->postJson('/v1/payouts', [
        'amount' => 2500,
        'currency' => 'BRL',
        'destination' => [
            'type' => 'pix_key',
            'value' => 'synthetic@acme.test',
        ],
    ], [
        'Authorization' => 'Bearer idem_partner_key',
        'Idempotency-Key' => 'payout-throw',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 1027)
        ->assertJsonPath('error.name', 'insufficient_balance');

    expect(Payout::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});
