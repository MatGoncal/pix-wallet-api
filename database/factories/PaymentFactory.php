<?php

namespace Database\Factories;

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = '00020126acmepay'.Str::random(16);

        return [
            'partner_id' => Partner::factory(),
            'status' => PaymentStatusEnum::Pending,
            'amount' => fake()->numberBetween(100, 500_000),
            'currency' => 'BRL',
            'external_id' => null,
            'description' => fake()->sentence(3),
            'qr_code' => $payload,
            'copy_paste' => $payload,
            'provider' => 'fake_pix',
            'provider_charge_id' => null,
            'provider_tx_id' => null,
            'expires_at' => now()->addHour(),
            'paid_at' => null,
        ];
    }

    public function forPartner(Partner $partner): static
    {
        return $this->state(fn () => ['partner_id' => $partner->id]);
    }

    public function ofAmount(int $amount, string $currency = 'BRL'): static
    {
        return $this->state(fn () => ['amount' => $amount, 'currency' => $currency]);
    }

    public function status(PaymentStatusEnum $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'paid_at' => $status === PaymentStatusEnum::Paid ? now() : null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatusEnum::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
