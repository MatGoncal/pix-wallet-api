<?php

namespace Database\Factories;

use App\Enums\PayoutStatusEnum;
use App\Models\Partner;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'status' => PayoutStatusEnum::Queued,
            'amount' => fake()->numberBetween(100, 100_000),
            'currency' => 'BRL',
            'destination_type' => 'pix_key',
            'destination_value' => fake()->unique()->safeEmail(),
            'external_id' => null,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => null,
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

    public function status(PayoutStatusEnum $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
