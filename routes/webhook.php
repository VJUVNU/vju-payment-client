<?php

use Illuminate\Support\Facades\Route;
use Vju\PaymentClient\Http\VerifyWebhookSignature;
use Vju\PaymentClient\Http\WebhookController;

Route::post(
    (string) config('payment-gateway.webhook.path', 'payment-gateway/webhook'),
    WebhookController::class,
)
    ->middleware([...(array) config('payment-gateway.webhook.middleware', []), VerifyWebhookSignature::class])
    ->name('payment-gateway.webhook');
