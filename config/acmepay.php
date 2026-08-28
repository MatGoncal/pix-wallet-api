<?php

return [
    'webhook_secret' => env('WEBHOOK_SECRET', 'dev-webhook-secret'),
    'webhook_tolerance_seconds' => (int) env('WEBHOOK_TOLERANCE_SECONDS', 300),
    'demo_partner_api_key' => env('DEMO_PARTNER_API_KEY', 'acmepay_demo_key_change_me'),
    'fx_rate_lock_seconds' => (int) env('FX_RATE_LOCK_SECONDS', 300),
    'fake_pix_base_url' => env('FAKE_PIX_BASE_URL', 'http://fake-pix:8080'),
    'fake_pix_api_key' => env('FAKE_PIX_API_KEY', 'fake-pix-demo'),
    'fake_pix_callback_url' => env('FAKE_PIX_CALLBACK_URL', 'http://laravel.test/v1/webhooks/payment'),
];
