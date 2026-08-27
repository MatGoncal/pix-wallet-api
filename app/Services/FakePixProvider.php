<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class FakePixProvider
{
    /**
     * @return array{qr_code: string, copy_paste: string, provider: string}
     */
    public function createCharge(int $amountMinor, string $currency, string $paymentId): array
    {
        $url = rtrim((string) config('acmepay.fake_pix_base_url'), '/').'/v1/charges';
        $apiKey = (string) config('acmepay.fake_pix_api_key');

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                ])
                ->post($url, [
                    'amount' => $amountMinor,
                    'currency' => $currency,
                    'payment_id' => $paymentId,
                    'callback_url' => (string) config('acmepay.fake_pix_callback_url'),
                ]);
        } catch (ConnectionException) {
            $this->failGateway();
        }

        if ($response->status() !== Response::HTTP_CREATED) {
            $this->failGateway();
        }

        $qrCode = $response->json('qr_code');
        $copyPaste = $response->json('copy_paste');

        if (! is_string($qrCode) || $qrCode === '' || ! is_string($copyPaste) || $copyPaste === '') {
            $this->failGateway();
        }

        return [
            'qr_code' => $qrCode,
            'copy_paste' => $copyPaste,
            'provider' => 'fake_pix',
        ];
    }

    private function failGateway(): never
    {
        abort(response()->json([
            'error' => [
                'code' => 502,
                'name' => 'bad_gateway',
                'message' => 'PIX provider unavailable.',
                'details' => (object) [],
            ],
        ], Response::HTTP_BAD_GATEWAY));
    }
}
