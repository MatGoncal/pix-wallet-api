<?php

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function listDemoPartner(string $rawKey = 'test_partner_api_key'): Partner
{
    return Partner::query()->create([
        'name' => 'Test Partner',
        'api_key_hash' => Partner::hashApiKey($rawKey),
        'api_key_prefix' => substr($rawKey, 0, 8),
        'is_active' => true,
    ]);
}

it('lists payments for the authenticated partner with pagination', function () {
    $partner = listDemoPartner();
    $other = listDemoPartner('other_key');

    Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-a',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Paid,
        'amount' => 3200,
        'currency' => 'BRL',
        'external_id' => 'order-b',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
        'paid_at' => now(),
    ]);

    Payment::query()->create([
        'partner_id' => $other->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 900,
        'currency' => 'BRL',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->getJson('/v1/payments?status=PAID&per_page=1&page=1', [
        'Authorization' => 'Bearer test_partner_api_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'PAID')
        ->assertJsonPath('data.0.external_id', 'order-b');
});

it('filters payments by external_id substring', function () {
    $partner = listDemoPartner();

    Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 1000,
        'currency' => 'BRL',
        'external_id' => 'invoice-42',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    Payment::query()->create([
        'partner_id' => $partner->id,
        'status' => PaymentStatusEnum::Pending,
        'amount' => 2000,
        'currency' => 'BRL',
        'external_id' => 'other-ref',
        'qr_code' => 'QR',
        'copy_paste' => 'QR',
        'provider' => 'fake_pix',
        'expires_at' => now()->addHour(),
    ]);

    $this->getJson('/v1/payments?external_id=invoice', [
        'Authorization' => 'Bearer test_partner_api_key',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_id', 'invoice-42');
});
