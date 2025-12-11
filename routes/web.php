<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\MarketRegistrationController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'guest'])->group(function (): void {
    Route::get('/register/market', [MarketRegistrationController::class, 'create'])
        ->name('market.register');

    Route::post('/register/market', [MarketRegistrationController::class, 'store'])
        ->name('market.register.store');
});
