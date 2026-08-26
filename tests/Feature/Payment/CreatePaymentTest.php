<?php

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function demoPartner(string $rawKey = 'test_partner_api_key'): Partner
{
    return Partner::query()->create([
        'name' => 'Test Partner',
        'api_key_hash' => Partner::hashApiKey($rawKey),
        'api_key_prefix' => substr($rawKey, 0, 8),
        'is_active' => true,
    ]);
}

it('rejects payment creation without api key', function () {
    $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
    ])->assertUnauthorized();
});

it('creates a pending payment with qr payloads', function () {
    demoPartner();

    $response = $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-1',
        'description' => 'Test charge',
    ], [
        'Authorization' => 'Bearer test_partner_api_key',
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'PENDING')
        ->assertJsonPath('amount', 1500)
        ->assertJsonPath('currency', 'BRL')
        ->assertJsonStructure(['id', 'qr_code', 'copy_paste', 'expires_at', 'created_at']);

    expect($response->json('amount'))->toBeInt();

    $this->assertDatabaseHas('payments', [
        'external_id' => 'order-1',
        'amount' => 1500,
        'status' => PaymentStatusEnum::Pending->value,
    ]);
});

it('shows payment for owning partner only', function () {
    $owner = demoPartner('owner_key');
    demoPartner('other_key');

    $payment = Payment::query()->create([
        'partner_id' => $owner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 2000,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $this->getJson('/v1/payments/'.$payment->id, [
        'Authorization' => 'Bearer owner_key',
    ])->assertOk()->assertJsonPath('id', $payment->id);

    $this->getJson('/v1/payments/'.$payment->id, [
        'Authorization' => 'Bearer other_key',
    ])->assertNotFound();
});
