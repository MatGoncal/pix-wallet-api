<?php

return [
    'webhook_secret' => env('WEBHOOK_SECRET', 'dev-webhook-secret'),
    'webhook_tolerance_seconds' => (int) env('WEBHOOK_TOLERANCE_SECONDS', 300),
    'demo_partner_api_key' => env('DEMO_PARTNER_API_KEY', 'acmepay_demo_key_change_me'),
    'fx_rate_lock_seconds' => (int) env('FX_RATE_LOCK_SECONDS', 300),
];
