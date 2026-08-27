<?php

use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\FxController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\SplitController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateApiKey::class)->group(function () {
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);
    Route::post('/payments/{id}/splits', [SplitController::class, 'store']);

    Route::get('/balances', [BalanceController::class, 'index']);
    Route::post('/fx/quotes', [FxController::class, 'store']);
    Route::post('/payouts', [PayoutController::class, 'store']);
});

Route::post('/webhooks/payment', [WebhookController::class, 'payment'])
    ->middleware(VerifyWebhookSignature::class);
