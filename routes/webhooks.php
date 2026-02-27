<?php

use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/woo/{connection}', [WooWebhookController::class, 'handle'])
    ->name('webhooks.woo.product');
