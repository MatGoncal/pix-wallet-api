<?php

namespace Database\Factories;

use App\Models\Partner;
use App\Models\PartnerBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerBalance>
 */
class PartnerBalanceFactory extends Factory
{
    protected $model = PartnerBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'currency' => 'BRL',
            'available' => 0,
            'pending' => 0,
        ];
    }

    public function forPartner(Partner $partner): static
    {
        return $this->state(fn () => ['partner_id' => $partner->id]);
    }

    public function funded(int $available, string $currency = 'BRL'): static
    {
        return $this->state(fn () => [
            'available' => $available,
            'currency' => $currency,
        ]);
    }
}
