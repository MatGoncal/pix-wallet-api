<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-AcmePay-Signature', '');
        $secret = (string) config('acmepay.webhook_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
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
