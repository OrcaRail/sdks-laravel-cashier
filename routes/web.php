<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OrcaRail\Cashier\Http\Controllers\WebhookController;
use OrcaRail\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::post(config('orcarail-cashier.webhook.path', 'orcarail/webhook'), [WebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('orcarail.webhook');
