<?php

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        $rawKey = (string) config('acmepay.demo_partner_api_key');

        Partner::query()->updateOrCreate(
            ['api_key_hash' => Partner::hashApiKey($rawKey)],
            [
                'name' => 'Demo Partner',
                'api_key_prefix' => substr($rawKey, 0, 8),
                'is_active' => true,
            ],
        );
    }
}
