<?php
# app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Filament\Pages\MapReviewResults;
use App\Http\Controllers\Admin\TenantMergePreflightController;
use App\Listeners\NotifySuperAdminsAboutUserLogin;
use App\Models\IntegrationExchange;
use App\Models\Task;
use App\Observers\IntegrationExchangeObserver;
use App\Policies\TaskPolicy;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        IntegrationExchange::observe(IntegrationExchangeObserver::class);

        Event::listen(Login::class, NotifySuperAdminsAboutUserLogin::class);

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-merge/preflight', [TenantMergePreflightController::class, 'preflight'])
            ->name('filament.admin.tenant-merge.preflight');

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-merge/apply', [TenantMergePreflightController::class, 'apply'])
            ->name('filament.admin.tenant-merge.apply');

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): View => view('filament.partials.map-review-results-tab-controller'),
            scopes: [MapReviewResults::class],
        );
    }
}
