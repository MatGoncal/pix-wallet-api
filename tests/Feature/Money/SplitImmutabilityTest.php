<?php

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PaymentSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const SPLITS_API_KEY = 'splits_partner_key';

function settledPayment(PaymentStatusEnum $status): Payment
{
    $partner = Partner::factory()->withApiKey(SPLITS_API_KEY)->create();

    $payment = Payment::factory()
        ->forPartner($partner)
        ->ofAmount(1500)
        ->status($status)
        ->create();

    PaymentSplit::query()->create([
        'id' => (string) Str::uuid(),
        'payment_id' => $payment->id,
        'party' => 'seller',
        'amount' => 1500,
    ]);

    return $payment;
}

/**
 * @param  list<array{party: string, amount: int}>  $lines
 */
function postSplits(Payment $payment, array $lines): TestResponse
{
    return test()->postJson(
        '/v1/payments/'.$payment->id.'/splits',
        ['splits' => $lines],
        ['Authorization' => 'Bearer '.SPLITS_API_KEY],
    );
}

it('refuses to rewrite splits once the payment is closed', function (PaymentStatusEnum $status) {
    $payment = settledPayment($status);

    postSplits($payment, [
        ['party' => 'platform', 'amount' => 750],
        ['party' => 'seller', 'amount' => 750],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 1015)
        ->assertJsonPath('error.name', 'settlement_failed')
        ->assertJsonPath('error.details.status', $status->value);

    $lines = PaymentSplit::query()->where('payment_id', $payment->id)->get();

    expect($lines)->toHaveCount(1)
        ->and($lines->first()->party)->toBe('seller')
        ->and($lines->first()->amount)->toBe(1500);
})->with([
    PaymentStatusEnum::Paid,
    PaymentStatusEnum::Expired,
    PaymentStatusEnum::Failed,
    PaymentStatusEnum::Cancelled,
]);

it('still lets an open payment define its splits', function () {
    $partner = Partner::factory()->withApiKey(SPLITS_API_KEY)->create();
    $payment = Payment::factory()->forPartner($partner)->ofAmount(1500)->create();

    postSplits($payment, [
        ['party' => 'platform', 'amount' => 500],
        ['party' => 'seller', 'amount' => 1000],
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'splits');

    expect(PaymentSplit::query()->where('payment_id', $payment->id)->count())->toBe(2);
});
