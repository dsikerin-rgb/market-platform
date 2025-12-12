<?php

use App\Http\Controllers\Auth\MarketRegistrationController;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/**
 * Переключатель рынка для super-admin (используется в topbar-user-info.blade.php).
 * Сохраняет выбранный market_id в сессии.
 */
Route::post('/admin/switch-market', function (Request $request) {
    $user = Filament::auth()->user();

    abort_unless($user && $user->isSuperAdmin(), 403);

    $validated = $request->validate([
        'market_id' => ['nullable', 'integer', 'exists:markets,id'],
    ]);

    $marketId = $validated['market_id'] ?? null;

    if (blank($marketId)) {
        $request->session()->forget('filament.admin.selected_market_id');
    } else {
        $request->session()->put('filament.admin.selected_market_id', (int) $marketId);
    }

    return back();
})
    ->middleware(['web', 'panel:admin', FilamentAuthenticate::class])
    ->name('filament.admin.switch-market');

Route::middleware('guest')->group(function () {
    Route::get('/register/market', [MarketRegistrationController::class, 'create'])
        ->name('market.register');

    Route::post('/register/market', [MarketRegistrationController::class, 'store'])
        ->name('market.register.store');
});
