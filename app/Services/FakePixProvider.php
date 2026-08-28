<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class FakePixProvider
{
    /**
     * @return array{id: string, qr_code: string, copy_paste: string, provider: string}
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
        } catch (ConnectionException $exception) {
            Log::warning('PIX provider unavailable: '.$this->connectionReason($exception));
            $this->failGateway();
        }

        if (! $this->isChargeSuccess($response)) {
            $this->logHttpFailure($response);
            $this->failGateway();
        }

        $id = $response->json('id');
        $qrCode = $response->json('qr_code');
        $copyPaste = $response->json('copy_paste');

        if (
            ! is_string($id) || $id === ''
            || ! is_string($qrCode) || $qrCode === ''
            || ! is_string($copyPaste) || $copyPaste === ''
        ) {
            $this->logHttpFailure($response);
            $this->failGateway();
        }

        return [
            'id' => $id,
            'qr_code' => $qrCode,
            'copy_paste' => $copyPaste,
            'provider' => 'fake_pix',
        ];
    }

    private function isChargeSuccess(ClientResponse $response): bool
    {
        return in_array($response->status(), [
            Response::HTTP_OK,
            Response::HTTP_CREATED,
        ], true);
    }

    private function connectionReason(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }

        return 'connection refused';
    }

    private function logHttpFailure(ClientResponse $response): void
    {
        $snippet = mb_substr($response->body(), 0, 200);

        Log::warning('PIX provider unavailable: HTTP '.$response->status().' '.$snippet);
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
