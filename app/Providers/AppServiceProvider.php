<?php
# app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Filament\Pages\MapReviewResults;
use App\Http\Controllers\Admin\TenantDuplicateIgnoreController;
use App\Http\Controllers\Admin\TenantDuplicateRestoreController;
use App\Http\Controllers\Admin\TenantMergePreflightController;
use App\Listeners\NotifySuperAdminsAboutUserLogin;
use App\Models\IntegrationExchange;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Task;
use App\Observers\IntegrationExchangeObserver;
use App\Observers\MarketSpaceGroupSharedUseObserver;
use App\Observers\MarketSpaceTenantBindingSharedUseObserver;
use App\Policies\TaskPolicy;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        IntegrationExchange::observe(IntegrationExchangeObserver::class);
        MarketSpace::observe(MarketSpaceGroupSharedUseObserver::class);
        MarketSpaceTenantBinding::observe(MarketSpaceTenantBindingSharedUseObserver::class);

        Event::listen(Login::class, NotifySuperAdminsAboutUserLogin::class);

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-merge/preflight', TenantMergePreflightController::class)
            ->name('filament.admin.tenant-merge.preflight');

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-merge/apply', \App\Http\Controllers\Admin\TenantMergeApplyController::class)
            ->name('filament.admin.tenant-merge.apply');

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-duplicates/ignore', TenantDuplicateIgnoreController::class)
            ->name('filament.admin.tenant-duplicates.ignore');

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/tenant-duplicates/restore', TenantDuplicateRestoreController::class)
            ->name('filament.admin.tenant-duplicates.restore');

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->get('/admin/map-review-results/duplicate-space-search', function (Request $request) {
                $user = Filament::auth()->user();
                abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

                $validated = $request->validate([
                    'q' => ['nullable', 'string', 'max:80'],
                    'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
                    'current_space_id' => ['nullable', 'integer', 'min:1'],
                ]);

                $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
                $marketId = session('dashboard_market_id')
                    ?? session("filament.{$panelId}.selected_market_id")
                    ?? session("filament_{$panelId}_market_id")
                    ?? session('filament.admin.selected_market_id')
                    ?? $user->market_id;

                if (! filled($marketId)) {
                    return response()->json(['ok' => true, 'items' => []]);
                }

                $queryText = trim(str_replace(['№', '#'], '', (string) ($validated['q'] ?? '')));
                $queryText = trim(str_replace(["\n", "\r", "\t"], ' ', $queryText));

                if ($queryText === '') {
                    return response()->json(['ok' => true, 'items' => []]);
                }

                $limit = (int) ($validated['limit'] ?? 10);
                $currentSpaceId = (int) ($validated['current_space_id'] ?? 0);
                $isNumeric = ctype_digit($queryText);
                $queryEscaped = str_replace(['%', '_'], ['\%', '\_'], $queryText);
                $queryLike = '%' . $queryEscaped . '%';

                $spaces = MarketSpace::query()
                    ->with(['tenant:id,name,display_name,short_name'])
                    ->where('market_id', (int) $marketId)
                    ->where('is_active', true)
                    ->when($currentSpaceId > 0, fn ($query) => $query->whereKeyNot($currentSpaceId))
                    ->where(function ($query) use ($isNumeric, $queryText, $queryLike): void {
                        if ($isNumeric) {
                            $query->orWhere('id', '=', (int) $queryText);
                        }

                        $query->orWhere('number', 'like', $queryLike)
                            ->orWhere('code', 'like', $queryLike)
                            ->orWhere('display_name', 'like', $queryLike)
                            ->orWhereHas('tenant', function ($tenantQuery) use ($queryLike): void {
                                $tenantQuery->where('name', 'like', $queryLike)
                                    ->orWhere('display_name', 'like', $queryLike)
                                    ->orWhere('short_name', 'like', $queryLike);
                            });
                    })
                    ->orderByRaw('CASE WHEN number = ? THEN 0 ELSE 1 END', [$queryText])
                    ->orderBy('number')
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(['id', 'number', 'code', 'display_name', 'status', 'tenant_id', 'space_group_role', 'space_group_parent_id']);

                $items = $spaces->map(static function (MarketSpace $space): array {
                    $tenant = $space->tenant;
                    $tenantName = '';

                    if ($tenant) {
                        $tenantName = trim((string) ($tenant->display_name ?? ''));
                        if ($tenantName === '') {
                            $tenantName = trim((string) ($tenant->short_name ?? ''));
                        }
                        if ($tenantName === '') {
                            $tenantName = trim((string) ($tenant->name ?? ''));
                        }
                    }

                    return [
                        'id' => (int) $space->id,
                        'number' => (string) ($space->number ?? ''),
                        'code' => (string) ($space->code ?? ''),
                        'display_name' => (string) ($space->display_name ?? ''),
                        'status' => (string) ($space->status ?? ''),
                        'space_group_role' => (string) ($space->space_group_role ?? ''),
                        'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                        'tenant' => $tenant ? [
                            'id' => (int) $tenant->id,
                            'name' => $tenantName,
                        ] : null,
                    ];
                })->values();

                return response()->json(['ok' => true, 'items' => $items]);
            })
            ->name('filament.admin.map-review-results.duplicate-space-search');

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): string => view('filament.partials.map-review-results-tab-controller')->render()
                . view('filament.partials.map-review-duplicate-space-picker')->render(),
            scopes: [MapReviewResults::class],
        );
    }
}
