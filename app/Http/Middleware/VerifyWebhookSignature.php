<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Support\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-AcmePay-Signature');
        $secret = (string) config('acmepay.webhook_secret');
        $tolerance = (int) config('acmepay.webhook_tolerance_seconds');
        $result = WebhookSignature::verify(
            is_string($signature) ? $signature : null,
            $request->getContent(),
            $secret,
            now()->getTimestamp(),
            $tolerance,
        );

        if ($result === WebhookSignature::EXPIRED) {
            throw new DomainException(
                1044,
                'webhook_timestamp_expired',
                'Webhook timestamp is outside the allowed tolerance.',
                [],
                401,
            );
        }

        if ($result !== WebhookSignature::OK) {
            return response()->json([
                'error' => [
                    'code' => 401,
                    'name' => 'unauthorized',
                    'message' => 'Invalid webhook signature.',
                    'details' => (object) [],
                ],
            ], 401);
        }

        return $next($request);
    }
}
