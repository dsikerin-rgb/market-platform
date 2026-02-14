<?php
# routes/api.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\Api\OneC\ContractDebtController;

/*
|--------------------------------------------------------------------------
| External webhooks
|--------------------------------------------------------------------------
*/

Route::post('telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

/*
|--------------------------------------------------------------------------
| 1C integration (inbound)
|--------------------------------------------------------------------------
*/

Route::post('1c/contract-debts', [ContractDebtController::class, 'store'])
    ->name('api.1c.contract-debts.store');
