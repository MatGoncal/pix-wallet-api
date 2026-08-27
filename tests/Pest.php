<?php

use App\Support\WebhookSignature;
use Tests\ConcurrencyTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

pest()->extend(ConcurrencyTestCase::class)
    ->in('Concurrency');

function signWebhookBody(string $body, ?int $timestamp = null): string
{
    return WebhookSignature::sign(
        $body,
        (string) config('acmepay.webhook_secret'),
        $timestamp ?? now()->getTimestamp(),
    );
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{0: string, 1: string}
 */
function signWebhook(array $payload, ?int $timestamp = null): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return [$body, signWebhookBody($body, $timestamp)];
}
