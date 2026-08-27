<?php

namespace Database\Seeders;

use App\Enums\PaymentStatusEnum;
use App\Models\Partner;
use App\Models\Payment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $rawKey = (string) config('acmepay.demo_partner_api_key');
        $partner = Partner::query()
            ->where('api_key_hash', Partner::hashApiKey($rawKey))
            ->first();

        if ($partner === null) {
            return;
        }

        $samples = [
            ['amount' => 1500, 'status' => PaymentStatusEnum::Paid, 'external_id' => 'order-101', 'description' => 'Checkout order 101'],
            ['amount' => 3200, 'status' => PaymentStatusEnum::Pending, 'external_id' => 'order-102', 'description' => 'PIX charge #102'],
            ['amount' => 8900, 'status' => PaymentStatusEnum::Paid, 'external_id' => 'order-103', 'description' => 'Subscription renewal'],
            ['amount' => 500, 'status' => PaymentStatusEnum::Expired, 'external_id' => 'order-104', 'description' => 'Expired QR test'],
            ['amount' => 12500, 'status' => PaymentStatusEnum::Pending, 'external_id' => 'order-105', 'description' => 'Bulk invoice'],
            ['amount' => 9999, 'status' => PaymentStatusEnum::Paid, 'external_id' => 'order-106', 'description' => 'Edge-case cents 99.99'],
            ['amount' => 750, 'status' => PaymentStatusEnum::Failed, 'external_id' => 'order-107', 'description' => 'Provider rejection'],
            ['amount' => 4200, 'status' => PaymentStatusEnum::Cancelled, 'external_id' => 'order-108', 'description' => 'Cancelled before pay'],
        ];

        foreach ($samples as $sample) {
            $id = (string) Str::uuid();
            $payload = '00020126acmepay'.Str::replace('-', '', substr($id, 0, 16));
            $isPaid = $sample['status'] === PaymentStatusEnum::Paid;
            $isExpired = $sample['status'] === PaymentStatusEnum::Expired;

            Payment::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'external_id' => $sample['external_id'],
                ],
                [
                    'status' => $sample['status'],
                    'amount' => $sample['amount'],
                    'currency' => 'BRL',
                    'description' => $sample['description'],
                    'qr_code' => $payload,
                    'copy_paste' => $payload,
                    'provider' => 'fake_pix',
                    'expires_at' => $isExpired ? now()->subHour() : now()->addHour(),
                    'paid_at' => $isPaid ? now()->subMinutes(15) : null,
                ],
            );
        }
    }
}
