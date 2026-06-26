<?php
# app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Filament\Pages\MapReviewResults;
use App\Http\Controllers\Admin\TenantDuplicateIgnoreController;
use App\Http\Controllers\Admin\TenantDuplicateRestoreController;
use App\Http\Controllers\Admin\TenantMergePreflightController;
use App\Listeners\NotifySuperAdminsAboutUserLogin;
use App\Livewire\Admin\OnlineStaffRail;
use App\Livewire\Admin\StaffLiveFeed;
use App\Models\IntegrationExchange;
use App\Models\MarketplaceChat;
use App\Models\MarketplaceProduct;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Operation;
use App\Models\Task;
use App\Observers\IntegrationExchangeObserver;
use App\Observers\MarketSpaceGroupSharedUseObserver;
use App\Observers\MarketSpaceTenantBindingSharedUseObserver;
use App\Policies\TaskPolicy;
use App\Policies\MarketplaceChatPolicy;
use App\Policies\MarketplaceProductPolicy;
use App\Support\MarketContext;
use App\Support\Search\LooseSearch;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketContext::class);
    }

    public function boot(): void
    {
        Validator::replacer('confirmed', function (string $message, string $attribute): string {
            return $this->isPasswordField($attribute)
                ? 'Пароль и подтверждение не совпадают.'
                : 'Подтверждение поля не совпадает.';
        });

        Validator::replacer('same', function (string $message, string $attribute, string $rule, array $parameters): string {
            $other = (string) ($parameters[0] ?? '');

            return $this->isPasswordConfirmationPair($attribute, $other)
                ? 'Пароль и подтверждение не совпадают.'
                : 'Значения полей не совпадают.';
        });

        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(MarketplaceProduct::class, MarketplaceProductPolicy::class);
        Gate::policy(MarketplaceChat::class, MarketplaceChatPolicy::class);
        IntegrationExchange::observe(IntegrationExchangeObserver::class);
        MarketSpace::observe(MarketSpaceGroupSharedUseObserver::class);
        MarketSpaceTenantBinding::observe(MarketSpaceTenantBindingSharedUseObserver::class);

        Event::listen(Login::class, NotifySuperAdminsAboutUserLogin::class);

        app('livewire')->component('admin.online-staff-rail', OnlineStaffRail::class);
        app('livewire')->component('admin.staff-live-feed', StaffLiveFeed::class);


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
                $marketId = app(MarketContext::class)->selectedMarketIdFromSession($panelId)
                    ?? $user->market_id;

                if (! filled($marketId)) {
                    return response()->json(['ok' => true, 'items' => []]);
                }

                $queryText = trim((string) ($validated['q'] ?? ''));

                if ($queryText === '') {
                    return response()->json(['ok' => true, 'items' => []]);
                }

                $limit = (int) ($validated['limit'] ?? 10);
                $currentSpaceId = (int) ($validated['current_space_id'] ?? 0);
                $isNumeric = ctype_digit($queryText);
                $numberExactOrderSql = "CASE WHEN replace(trim(regexp_replace(regexp_replace(lower(coalesce(number, '')), '[[:punct:]]+', ' '), '[[:space:]]+', ' ')), ' ', '') = ? THEN 0 ELSE 1 END";
                $exactOrderToken = LooseSearch::compact($queryText);

                $spaces = MarketSpace::query()
                    ->with(['tenant:id,name,short_name'])
                    ->where('market_id', (int) $marketId)
                    ->where('is_active', true)
                    ->when($currentSpaceId > 0, fn ($query) => $query->whereKeyNot($currentSpaceId))
                    ->where(function ($query) use ($isNumeric, $queryText): void {
                        if ($isNumeric) {
                            $query->orWhere('id', '=', (int) $queryText);
                        }

                        LooseSearch::applySearch($query, $queryText, [
                            static function ($searchQuery, array $termPatterns): void {
                                LooseSearch::orWhereMatchesColumns($searchQuery, ['number', 'code', 'display_name'], $termPatterns);
                                $searchQuery->orWhereHas('tenant', function ($tenantQuery) use ($termPatterns): void {
                                    LooseSearch::orWhereMatchesColumns($tenantQuery, ['name', 'short_name'], $termPatterns);
                                });
                            },
                        ]);
                    })
                    ->orderByRaw($numberExactOrderSql, [$exactOrderToken])
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

        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->post('/admin/map-review-results/retire-space', function (Request $request) {
                $user = Filament::auth()->user();
                abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

                $validated = $request->validate([
                    'market_space_id' => ['required', 'integer', 'min:1'],
                    'effective_date' => ['required', 'date_format:Y-m-d'],
                    'reason' => ['required', 'string', 'max:2000'],
                ]);

                $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
                $marketId = app(MarketContext::class)->selectedMarketIdFromSession($panelId)
                    ?? $user->market_id;

                abort_unless(filled($marketId), 422, 'Market is not selected.');

                $marketSpaceId = (int) $validated['market_space_id'];
                $effectiveDate = (string) $validated['effective_date'];
                $reason = trim((string) $validated['reason']);
                $marketTz = (string) (DB::table('markets')->where('id', (int) $marketId)->value('timezone') ?: config('app.timezone', 'UTC'));
                $effectiveAt = CarbonImmutable::parse($effectiveDate, $marketTz)->startOfDay()->utc();
                $now = now();

                $result = DB::transaction(function () use ($marketId, $marketSpaceId, $effectiveAt, $effectiveDate, $reason, $user, $now): array {
                    $space = MarketSpace::query()
                        ->where('market_id', (int) $marketId)
                        ->whereKey($marketSpaceId)
                        ->lockForUpdate()
                        ->first(['id', 'market_id', 'number', 'display_name', 'notes']);

                    abort_unless($space, 404, 'Map review space was not found in the current market.');

                    $relationCounts = [];
                    foreach ([
                        'map_shapes' => ['market_space_map_shapes', 'market_space_id'],
                        'contracts' => ['tenant_contracts', 'market_space_id'],
                        'accruals' => ['tenant_accruals', 'market_space_id'],
                        'tenant_bindings' => ['market_space_tenant_bindings', 'market_space_id'],
                        'products' => ['marketplace_products', 'market_space_id'],
                    ] as $key => [$table, $column]) {
                        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                            $relationCounts[$key] = 0;
                            continue;
                        }

                        $query = DB::table($table)->where($column, $marketSpaceId);
                        if (Schema::hasColumn($table, 'market_id')) {
                            $query->where('market_id', (int) $marketId);
                        }
                        $relationCounts[$key] = (int) $query->count();
                    }

                    $shapeUpdate = ['market_space_id' => null, 'updated_at' => $now];
                    if (Schema::hasTable('market_space_map_shapes') && Schema::hasColumn('market_space_map_shapes', 'is_active')) {
                        $shapeUpdate['is_active'] = false;
                    }
                    $deactivatedMapShapes = Schema::hasTable('market_space_map_shapes')
                        ? DB::table('market_space_map_shapes')
                            ->where('market_id', (int) $marketId)
                            ->where('market_space_id', $marketSpaceId)
                            ->update($shapeUpdate)
                        : 0;

                    $closedSnapshotBindings = 0;
                    if (Schema::hasTable('market_space_tenant_bindings')) {
                        $bindingQuery = DB::table('market_space_tenant_bindings')
                            ->where('market_id', (int) $marketId)
                            ->where('market_space_id', $marketSpaceId);
                        if (Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')) {
                            $bindingQuery->whereNull('tenant_contract_id');
                        }
                        if (Schema::hasColumn('market_space_tenant_bindings', 'binding_type')) {
                            $bindingQuery->where('binding_type', 'space_snapshot');
                        }
                        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
                            $bindingQuery->whereNull('ended_at');
                        }

                        $bindingUpdate = ['updated_at' => $now];
                        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
                            $bindingUpdate['ended_at'] = $now;
                        }
                        if (Schema::hasColumn('market_space_tenant_bindings', 'resolution_reason')) {
                            $bindingUpdate['resolution_reason'] = 'space_retired_without_canonical';
                        }
                        $closedSnapshotBindings = $bindingQuery->update($bindingUpdate);
                    }

                    $existingNotes = trim((string) ($space->notes ?? ''));
                    $note = sprintf(
                        '[%s] Место архивировано без основного места с %s: физически больше не существует на рынке. Причина: %s',
                        now()->format('Y-m-d H:i'),
                        $effectiveDate,
                        $reason,
                    );
                    DB::table('market_spaces')
                        ->where('market_id', (int) $marketId)
                        ->where('id', $marketSpaceId)
                        ->update([
                            'is_active' => false,
                            'status' => 'maintenance',
                            'notes' => trim($existingNotes . ($existingNotes !== '' ? "\n" : '') . $note),
                            'map_review_status' => 'changed',
                            'map_reviewed_at' => $now,
                            'map_reviewed_by' => $user->id,
                            'updated_at' => $now,
                        ]);

                    $operation = Operation::query()->create([
                        'market_id' => (int) $marketId,
                        'entity_type' => 'market_space',
                        'entity_id' => $marketSpaceId,
                        'type' => OperationType::SPACE_REVIEW,
                        'effective_at' => $effectiveAt,
                        'status' => 'applied',
                        'payload' => [
                            'market_space_id' => $marketSpaceId,
                            'decision' => SpaceReviewDecision::RETIRE_SPACE,
                            'effective_date' => $effectiveDate,
                            'reason' => $reason,
                            'retirement' => [
                                'canonical_market_space_id' => null,
                                'deactivated_map_shapes' => $deactivatedMapShapes,
                                'closed_snapshot_bindings' => $closedSnapshotBindings,
                                'relation_counts' => $relationCounts,
                            ],
                        ],
                        'comment' => $reason,
                        'created_by' => $user->id,
                    ]);

                    return [
                        'operation_id' => (int) $operation->id,
                        'deactivated_map_shapes' => (int) $deactivatedMapShapes,
                        'closed_snapshot_bindings' => (int) $closedSnapshotBindings,
                    ];
                });

                return response()->json([
                    'ok' => true,
                    'mode' => 'retire_space',
                    'operation' => [
                        'id' => $result['operation_id'],
                        'decision' => SpaceReviewDecision::RETIRE_SPACE,
                        'status' => 'applied',
                    ],
                    'result' => $result,
                ]);
            })
            ->name('filament.admin.map-review-results.retire-space');

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): View => view('filament.partials.map-review-results-tab-controller'),
            scopes: [MapReviewResults::class],
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): View => view('filament.partials.map-review-historical-group-actions'),
            scopes: [MapReviewResults::class],
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): View => view('filament.partials.map-review-retire-canonical-picker'),
            scopes: [MapReviewResults::class],
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): View => view('filament.partials.map-review-retire-space-actions'),
            scopes: [MapReviewResults::class],
        );
    }

    private function isPasswordField(string $attribute): bool
    {
        $attribute = strtolower($attribute);

        return $attribute === 'password'
            || str_ends_with($attribute, '.password');
    }

    private function isPasswordConfirmationPair(string $attribute, string $other): bool
    {
        $attribute = strtolower($attribute);
        $other = strtolower($other);

        return ($attribute === 'password_confirmation' || str_ends_with($attribute, '.password_confirmation'))
            && ($other === 'password' || str_ends_with($other, '.password'));
    }
}
