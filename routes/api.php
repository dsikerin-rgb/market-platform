<?php
# routes/api.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\Api\OneC\AccrualController;
use App\Http\Controllers\Api\OneC\ContractDebtController;
use App\Http\Controllers\Api\OneC\ContractController;
use App\Http\Controllers\Api\OneC\PaymentController;
use App\Http\Controllers\Api\OneC\SettlementController;

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

/**
 * Контракты/размещения из 1С (связка contract_external_id ↔ market_space_code).
 */
Route::post('1c/contracts', [ContractController::class, 'store'])
    ->name('api.1c.contracts.store');

Route::post('1c/accruals', [AccrualController::class, 'store'])
    ->name('api.1c.accruals.store');

Route::post('1c/payments', [PaymentController::class, 'store'])
    ->name('api.1c.payments.store');

Route::post('1c/settlements', [SettlementController::class, 'store'])
    ->name('api.1c.settlements.store');
