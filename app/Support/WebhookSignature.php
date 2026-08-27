<?php

namespace App\Support;

final class WebhookSignature
{
    public const OK = 'ok';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        $v1 = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return 't='.$timestamp.',v1='.$v1;
    }

    /**
     * @return self::OK|self::MISSING|self::INVALID|self::EXPIRED
     */
    public static function verify(
        ?string $header,
        string $rawBody,
        string $secret,
        int $now,
        int $tolerance,
    ): string {
        if ($header === null || $header === '') {
            return self::MISSING;
        }

        $parsed = self::parse($header);
        if ($parsed === null) {
            return self::INVALID;
        }

        $expected = hash_hmac('sha256', $parsed['t'].'.'.$rawBody, $secret);
        if (! hash_equals($expected, strtolower($parsed['v1']))) {
            return self::INVALID;
        }

        if (abs($now - $parsed['t']) > $tolerance) {
            return self::EXPIRED;
        }

        return self::OK;
    }

    /**
     * @return array{t: int, v1: string}|null
     */
    public static function parse(string $header): ?array
    {
        $parts = [];

        foreach (explode(',', $header) as $segment) {
            $eq = strpos($segment, '=');
            if ($eq === false) {
                continue;
            }

            $parts[trim(substr($segment, 0, $eq))] = trim(substr($segment, $eq + 1));
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return null;
        }

        if (! ctype_digit($parts['t']) || $parts['t'] === '0') {
            return null;
        }

        if (preg_match('/^[0-9a-f]+$/i', $parts['v1']) !== 1) {
            return null;
        }

        return [
            't' => (int) $parts['t'],
            'v1' => strtolower($parts['v1']),
        ];
    }
}
