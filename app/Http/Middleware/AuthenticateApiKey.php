<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $this->extractKey($request);

        if ($rawKey === null || $rawKey === '') {
            return response()->json([
                'error' => [
                    'code' => 401,
                    'name' => 'unauthorized',
                    'message' => 'Missing API key.',
                    'details' => (object) [],
                ],
            ], 401);
        }

        $partner = Partner::query()
            ->where('api_key_hash', Partner::hashApiKey($rawKey))
            ->where('is_active', true)
            ->first();

        if ($partner === null) {
            return response()->json([
                'error' => [
                    'code' => 401,
                    'name' => 'unauthorized',
                    'message' => 'Invalid API key.',
                    'details' => (object) [],
                ],
            ], 401);
        }

        $request->attributes->set('partner', $partner);

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Api-Key');

        return is_string($header) ? $header : null;
    }
}
