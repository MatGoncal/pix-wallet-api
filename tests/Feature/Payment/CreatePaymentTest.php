<?php

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

/**
 * @param  array<string, mixed>  $body
 */
function fakePixProviderResponse(array $body, int $status): void
{
    $factory = new Factory;
    $factory->fake([
        config('acmepay.fake_pix_base_url').'/v1/charges' => Http::response($body, $status),
    ]);
    Http::swap($factory);
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
        'provider_charge_id' => 'chg_test',
        'provider_tx_id' => null,
    ]);

    expect($response->json())->not->toHaveKey('provider_charge_id');
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

it('posts integer amount payment_id and callback_url to fake pix provider', function () {
    fakePixProviderResponse([
        'id' => 'chg_http',
        'status' => 'PENDING',
        'qr_code' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.demo',
        'copy_paste' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.demo',
        'provider_tx_id' => 'pix_tx_http',
    ], 201);

    demoPartner();

    $response = $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-http',
    ], [
        'Authorization' => 'Bearer test_partner_api_key',
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'PENDING');

    expect($response->json('qr_code'))->toStartWith('00020126ACMEPAY.FAKE.PIX')
        ->and($response->json('copy_paste'))->toStartWith('00020126ACMEPAY.FAKE.PIX')
        ->and($response->json())->not->toHaveKey('provider_charge_id');

    $this->assertDatabaseHas('payments', [
        'external_id' => 'order-http',
        'provider_charge_id' => 'chg_http',
        'provider_tx_id' => null,
    ]);

    $paymentId = $response->json('id');

    $recorded = Http::recorded();
    expect($recorded)->not->toBeEmpty();
    /** @var Request $outbound */
    $outbound = $recorded[0][0];
    $data = $outbound->data();

    expect($outbound->url())->toBe(config('acmepay.fake_pix_base_url').'/v1/charges')
        ->and($outbound->method())->toBe('POST')
        ->and($data['amount'])->toBe(1500)
        ->and($data['amount'])->toBeInt()
        ->and($data['currency'])->toBe('BRL')
        ->and($data['callback_url'])->toBe(config('acmepay.fake_pix_callback_url'))
        ->and($data['payment_id'])->toBe($paymentId);
});

it('accepts a 200 replay from fake pix provider and stores charge id', function () {
    fakePixProviderResponse([
        'id' => 'chg_replay',
        'status' => 'PENDING',
        'qr_code' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.demo',
        'copy_paste' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.1.demo',
        'provider_tx_id' => 'pix_tx_replay',
    ], 200);

    demoPartner();

    $response = $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
        'external_id' => 'order-replay',
    ], [
        'Authorization' => 'Bearer test_partner_api_key',
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'PENDING');

    expect($response->json())->not->toHaveKey('provider_charge_id');

    $this->assertDatabaseHas('payments', [
        'external_id' => 'order-replay',
        'provider_charge_id' => 'chg_replay',
        'provider_tx_id' => null,
    ]);
});

it('rejects non-BRL currency with 422 and does not call the PIX provider', function () {
    demoPartner();

    $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'USD',
    ], [
        'Authorization' => 'Bearer test_partner_api_key',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);

    expect(Payment::query()->count())->toBe(0)
        ->and(Http::recorded())->toBeEmpty();
});

it('returns 502 when fake pix provider is unreachable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    demoPartner();

    $this->postJson('/v1/payments', [
        'amount' => 1500,
        'currency' => 'BRL',
    ], [
        'Authorization' => 'Bearer test_partner_api_key',
    ])->assertStatus(502)
        ->assertJsonPath('error.code', 502)
        ->assertJsonPath('error.name', 'bad_gateway');

    expect(Payment::query()->count())->toBe(0);
});
