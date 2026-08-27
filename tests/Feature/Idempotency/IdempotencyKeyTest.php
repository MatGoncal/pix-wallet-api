<?php

use App\Models\IdempotencyKey;
use App\Models\Partner;
use App\Models\PartnerBalance;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->and(IdempotencyKey::query()->count())->toBe(1);
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
