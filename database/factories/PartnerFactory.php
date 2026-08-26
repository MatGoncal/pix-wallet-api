<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->apiKeyAttributes('pk_'.Str::random(24)) + [
            'name' => fake()->company(),
            'is_active' => true,
        ];
    }

    /**
     * The raw key is never stored, so tests that need to authenticate must pin it here.
     */
    public function withApiKey(string $rawKey): static
    {
        return $this->state(fn () => $this->apiKeyAttributes($rawKey));
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @return array{api_key_hash: string, api_key_prefix: string}
     */
    private function apiKeyAttributes(string $rawKey): array
    {
        return [
            'api_key_hash' => Partner::hashApiKey($rawKey),
            'api_key_prefix' => substr($rawKey, 0, 8),
        ];
    }
}
