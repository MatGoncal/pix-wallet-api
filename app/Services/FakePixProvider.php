<?php

namespace App\Services;

use Illuminate\Support\Str;

class FakePixProvider
{
    /**
     * @return array{qr_code: string, copy_paste: string, provider: string}
     */
    public function createCharge(int $amountMinor, string $currency, string $paymentId): array
    {
        $emv = sprintf(
            '00020126ACMEPAY.FAKE.PIX.%s.%s.%d.%s',
            strtoupper($currency),
            $amountMinor,
            time(),
            Str::lower(Str::substr($paymentId, 0, 8)),
        );

        return [
            'qr_code' => $emv,
            'copy_paste' => $emv,
            'provider' => 'fake_pix',
        ];
    }
}
