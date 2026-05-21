<?php
# routes/web.php

declare(strict_types=1);

use App\Filament\Resources\TenantResource;
use App\Http\Controllers\Auth\MarketRegistrationController;
use App\Http\Controllers\Admin\TenantCabinetImpersonationController;
use App\Http\Controllers\Cabinet\AccrualsController;
use App\Http\Controllers\Cabinet\CabinetAuthController;
use App\Http\Controllers\Cabinet\CabinetImpersonationController;
use App\Http\Controllers\Cabinet\CustomerChatController;
use App\Http\Controllers\Cabinet\DashboardController;
use App\Http\Controllers\Cabinet\DocumentsController;
use App\Http\Controllers\Cabinet\PaymentsController;
use App\Http\Controllers\Cabinet\ProductsController;
use App\Http\Controllers\Cabinet\PublicShowcaseController;
use App\Http\Controllers\Cabinet\RequestsController;
use App\Http\Controllers\Cabinet\ShowcaseController;
use App\Http\Controllers\Cabinet\SpacesController;
use App\Http\Controllers\Cabinet\TelegramConnectController;
use App\Http\Controllers\Marketplace\AnnouncementController as MarketplaceAnnouncementController;
use App\Http\Controllers\Marketplace\BuyerAuthController as MarketplaceBuyerAuthController;
use App\Http\Controllers\Marketplace\BuyerCabinetController as MarketplaceBuyerCabinetController;
use App\Http\Controllers\Marketplace\BuyerChatController as MarketplaceBuyerChatController;
use App\Http\Controllers\Marketplace\BuyerFavoriteController as MarketplaceBuyerFavoriteController;
use App\Http\Controllers\Marketplace\CatalogController as MarketplaceCatalogController;
use App\Http\Controllers\Marketplace\HomeController as MarketplaceHomeController;
use App\Http\Controllers\Marketplace\MapController as MarketplaceMapController;
use App\Http\Controllers\Marketplace\ProductController as MarketplaceProductController;
use App\Http\Controllers\Marketplace\StoreController as MarketplaceStoreController;
use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\StaffConversation;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Debt\DebtAggregator;
use App\Services\Ai\AiReviewService;
use App\Support\StaffConversationService;
use App\Support\MarketplaceMediaStorage;
use App\Services\Debt\DebtStatusResolver;
use App\Services\MarketMap\SpaceReviewActionService;
use App\Services\Marketplace\MarketplaceContextService;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Validation\ValidationException;

Route::view('/', 'welcome')->name('home');

Route::get('/media/{path}', function (string $path) {
    return MarketplaceMediaStorage::serve($path);
})
    ->where('path', '.*')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
    ])
    ->name('marketplace.media.proxy');

Route::get('/login', function () {
    return redirect()->route('cabinet.login');
})->name('login');

Route::prefix('cabinet')->middleware('cabinet.no_cache')->group(function () {
    Route::get('/login', [CabinetAuthController::class, 'showLogin'])->name('cabinet.login');
    Route::post('/login', [CabinetAuthController::class, 'login'])->name('cabinet.login.submit');
    Route::middleware('auth')
        ->get('/impersonate/{token}', [CabinetImpersonationController::class, 'consume'])
        ->name('cabinet.impersonate.consume');

    Route::middleware(['auth', 'cabinet.access'])->group(function () {
        Route::post('/logout', [CabinetAuthController::class, 'logout'])->name('cabinet.logout');
        Route::post('/impersonation/exit', [CabinetImpersonationController::class, 'exit'])
            ->name('cabinet.impersonation.exit');

        Route::get('/', DashboardController::class)->name('cabinet.dashboard');
        Route::get('/accruals', [AccrualsController::class, 'index'])->name('cabinet.accruals');
        Route::get('/payments', PaymentsController::class)->name('cabinet.payments');

        Route::get('/requests', [RequestsController::class, 'index'])->name('cabinet.requests');
        Route::get('/requests/create', [RequestsController::class, 'create'])->name('cabinet.requests.create');
        Route::post('/requests', [RequestsController::class, 'store'])->name('cabinet.requests.store');
        Route::get('/requests/{ticketId}', [RequestsController::class, 'show'])->name('cabinet.requests.show');
        Route::post('/requests/{ticketId}/comment', [RequestsController::class, 'comment'])->name('cabinet.requests.comment');

        Route::get('/documents', [DocumentsController::class, 'index'])->name('cabinet.documents');
        Route::get('/documents/{documentId}/download', [DocumentsController::class, 'download'])->name('cabinet.documents.download');

        Route::get('/spaces', SpacesController::class)->name('cabinet.spaces');
        Route::post('/spaces/staff', [SpacesController::class, 'storeStaff'])->name('cabinet.spaces.staff.store');
        Route::get('/customer-chat', CustomerChatController::class)->name('cabinet.customer-chat');
        Route::post('/customer-chat/{chatId}/send', [CustomerChatController::class, 'send'])->name('cabinet.customer-chat.send');
        Route::post('/telegram/connect', TelegramConnectController::class)->name('cabinet.telegram.connect');

        Route::get('/showcase', [ShowcaseController::class, 'edit'])->name('cabinet.showcase.edit');
        Route::post('/showcase', [ShowcaseController::class, 'update'])->name('cabinet.showcase.update');
        Route::get('/products', [ProductsController::class, 'index'])->name('cabinet.products.index');
        Route::get('/products/create', [ProductsController::class, 'create'])->name('cabinet.products.create');
        Route::post('/products', [ProductsController::class, 'store'])->name('cabinet.products.store');
        Route::get('/products/{product}/edit', [ProductsController::class, 'edit'])->name('cabinet.products.edit');
        Route::get('/csrf-token', [ProductsController::class, 'csrfToken'])->name('cabinet.csrf-token');
        Route::post('/products/{product}/images/delete', [ProductsController::class, 'destroyImage'])->name('cabinet.products.images.destroy');
        Route::post('/products/{product}', [ProductsController::class, 'update'])->name('cabinet.products.update');
        Route::post('/products/{product}/delete', [ProductsController::class, 'destroy'])->name('cabinet.products.destroy');
    });
});

Route::get('/v/{tenantSlug}', PublicShowcaseController::class)->name('cabinet.showcase.public');

Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])->group(function () {
    Route::match(['GET', 'POST'], '/admin/tenants/{tenant}/cabinet-impersonate', [TenantCabinetImpersonationController::class, 'issue'])
        ->name('filament.admin.tenants.cabinet-impersonate');

    Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/admin/tenants/{tenant}/contracts/{contract}/delete', function (Request $request, int $tenant, int $contract) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $contractModel = TenantContract::query()
            ->whereKey($contract)
            ->where('tenant_id', $tenant)
            ->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $sameMarket = (int) ($user->market_id ?? 0) === (int) $contractModel->market_id;
        abort_unless($isSuperAdmin || ($isMarketAdmin && $sameMarket), 403);

        $number = trim((string) ($contractModel->number ?? ''));
        $label = $number !== '' ? $number : ('#' . (int) $contractModel->id);

        try {
            $contractModel->delete();
        } catch (\Throwable) {
            return back()->withErrors([
                'contract_delete' => 'Не удалось удалить договор ' . $label . '. Возможно, на него уже ссылаются начисления.',
            ]);
        }

        return back()->with('status', 'Договор ' . $label . ' удалён.');
    })->name('filament.admin.tenants.contracts.delete');

    Route::post('/admin/requests/start', function (Request $request) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ]);

        $tenant = Tenant::query()->whereKey((int) $validated['tenant_id'])->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $sameMarket = (int) ($user->market_id ?? 0) === (int) $tenant->market_id;
        abort_unless($isSuperAdmin || $sameMarket, 403);

        $description = trim((string) $validated['description']);
        $subject = trim((string) ($validated['subject'] ?? ''));

        if ($subject === '') {
            $normalizedDescription = preg_replace('/\s+/u', ' ', $description) ?? '';
            $subject = trim((string) $normalizedDescription);
        }

        if ($subject === '') {
            $subject = 'Новый диалог';
        }

        $ticket = Ticket::query()->create([
            'market_id' => (int) $tenant->market_id,
            'tenant_id' => (int) $tenant->id,
            'subject' => mb_substr($subject, 0, 255),
            'description' => $description,
            'category' => 'other',
            'priority' => 'normal',
            'status' => 'new',
        ]);

        return redirect()
            ->to(url('/admin/requests?' . http_build_query([
                'tenant_id' => (int) $tenant->id,
                'ticket_id' => (int) $ticket->id,
            ])))
            ->with('status', 'Диалог с арендатором создан.');
    })->name('filament.admin.requests.start');

    Route::post('/admin/requests/staff/start', function (Request $request, StaffConversationService $service) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);
        abort_unless(
            Schema::hasTable('staff_conversations') && Schema::hasTable('staff_conversation_messages'),
            503,
            'Staff conversations schema is missing. Run migrations.',
        );

        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string'],
        ]);

        $recipient = User::query()
            ->whereKey((int) $validated['recipient_user_id'])
            ->whereNull('tenant_id')
            ->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $sameMarket = (int) ($user->market_id ?? 0) === (int) ($recipient->market_id ?? 0);
        abort_unless($isSuperAdmin || $sameMarket, 403);
        abort_if((int) $recipient->id === (int) $user->id, 422, 'Нельзя начать диалог с самим собой.');

        $conversation = $service->startConversation(
            $user,
            $recipient,
            '',
            trim((string) $validated['body']),
        );

        return redirect()
            ->to(url('/admin/requests?' . http_build_query([
                'channel' => 'staff',
                'conversation_id' => (int) $conversation->id,
            ])))
            ->with('status', 'Диалог с сотрудником создан.');
    })->name('filament.admin.requests.staff.start');

    Route::post('/admin/requests/{ticket}/comment', function (Request $request, int $ticket) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $ticketModel = Ticket::query()->whereKey($ticket)->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $sameMarket = (int) ($user->market_id ?? 0) === (int) $ticketModel->market_id;
        abort_unless($isSuperAdmin || $sameMarket, 403);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'tenant_id' => ['nullable', 'integer'],
            'status_redirect' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $statusBefore = (string) $ticketModel->status;

        TicketComment::query()->create([
            'ticket_id' => (int) $ticketModel->id,
            'user_id' => (int) $user->id,
            'body' => trim((string) $validated['body']),
        ]);

        if ($statusBefore === 'new') {
            $ticketModel->status = 'in_progress';
            $ticketModel->save();
        } else {
            $ticketModel->touch();
        }

        $params = ['ticket_id' => (int) $ticketModel->id];

        $tenantId = is_numeric($validated['tenant_id'] ?? null) ? (int) $validated['tenant_id'] : 0;
        if ($tenantId > 0) {
            $params['tenant_id'] = $tenantId;
        }

        $redirectStatus = $statusBefore === 'new'
            ? 'in_progress'
            : trim((string) ($validated['status_redirect'] ?? ''));
        if ($redirectStatus !== '' && $redirectStatus !== 'all') {
            $params['status'] = $redirectStatus;
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $params['q'] = $search;
        }

        return redirect()
            ->to(url('/admin/requests?' . http_build_query($params)))
            ->with('status', 'Сообщение добавлено.');
    })->name('filament.admin.requests.comment');

    Route::post('/admin/requests/staff/{conversation}/comment', function (Request $request, int $conversation, StaffConversationService $service) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);
        abort_unless(
            Schema::hasTable('staff_conversations') && Schema::hasTable('staff_conversation_messages'),
            503,
            'Staff conversations schema is missing. Run migrations.',
        );

        $conversationModel = StaffConversation::query()->whereKey($conversation)->firstOrFail();
        abort_unless($service->canAccessConversation($user, $conversationModel), 403);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $service->addMessage($conversationModel, $user, trim((string) $validated['body']));

        $params = [
            'channel' => 'staff',
            'conversation_id' => (int) $conversationModel->id,
        ];

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $params['q'] = $search;
        }

        return redirect()
            ->to(url('/admin/requests?' . http_build_query($params)))
            ->with('status', 'Сообщение добавлено.');
    })->name('filament.admin.requests.staff.comment');

    Route::post('/admin/requests/{ticket}/assign', function (Request $request, int $ticket) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $ticketModel = Ticket::query()->whereKey($ticket)->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $sameMarket = (int) ($user->market_id ?? 0) === (int) $ticketModel->market_id;
        abort_unless($isSuperAdmin || ($isMarketAdmin && $sameMarket), 403);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer'],
            'tenant_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'status_value' => ['nullable', 'string'],
            'status_redirect' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $assigneeId = is_numeric($validated['assigned_to'] ?? null)
            ? (int) $validated['assigned_to']
            : null;

        if ($assigneeId !== null && $assigneeId > 0) {
            $assigneeExists = User::query()
                ->whereKey($assigneeId)
                ->where('market_id', (int) $ticketModel->market_id)
                ->whereNull('tenant_id')
                ->exists();

            if (! $assigneeExists) {
                abort(422, 'Выбранный сотрудник недоступен для назначения.');
            }
        } else {
            $assigneeId = null;
        }

        $allowedStatuses = [
            'new',
            'in_progress',
            'on_hold',
            'resolved',
            'closed',
            'cancelled',
        ];

        $statusValue = trim((string) ($validated['status_value'] ?? ''));
        if ($statusValue !== '') {
            abort_unless(in_array($statusValue, $allowedStatuses, true), 422, 'Недопустимый статус обращения.');
            $ticketModel->status = $statusValue;
        }

        $ticketModel->assigned_to = $assigneeId;
        $ticketModel->save();

        $params = ['ticket_id' => (int) $ticketModel->id];

        $tenantId = is_numeric($validated['tenant_id'] ?? null) ? (int) $validated['tenant_id'] : 0;
        if ($tenantId > 0) {
            $params['tenant_id'] = $tenantId;
        }

        $status = trim((string) ($validated['status_redirect'] ?? $validated['status'] ?? ''));
        if ($status !== '') {
            $params['status'] = $status;
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $params['q'] = $search;
        }

        return redirect()
            ->to(url('/admin/requests?' . http_build_query($params)))
            ->with('status', 'Изменения сохранены.');
    })->name('filament.admin.requests.assign');

    Route::post('/admin/requests/{ticket}/status', function (Request $request, int $ticket) {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $ticketModel = Ticket::query()->whereKey($ticket)->firstOrFail();

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $sameMarket = (int) ($user->market_id ?? 0) === (int) $ticketModel->market_id;
        abort_unless($isSuperAdmin || ($isMarketAdmin && $sameMarket), 403);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'tenant_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string'],
        ]);

        $allowedStatuses = [
            'new',
            'in_progress',
            'on_hold',
            'resolved',
            'closed',
            'cancelled',
        ];

        $newStatus = trim((string) $validated['status']);
        abort_unless(in_array($newStatus, $allowedStatuses, true), 422, 'Недопустимый статус обращения.');

        $ticketModel->status = $newStatus;
        $ticketModel->save();

        $params = ['ticket_id' => (int) $ticketModel->id];

        $tenantId = is_numeric($validated['tenant_id'] ?? null) ? (int) $validated['tenant_id'] : 0;
        if ($tenantId > 0) {
            $params['tenant_id'] = $tenantId;
        }

        $redirectStatus = match ($newStatus) {
            'resolved', 'closed', 'cancelled' => 'closed',
            default => $newStatus,
        };
        if ($redirectStatus !== 'all') {
            $params['status'] = $redirectStatus;
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $params['q'] = $search;
        }

        return redirect()
            ->to(url('/admin/requests?' . http_build_query($params)))
            ->with('status', 'Статус обращения обновлён.');
    })->name('filament.admin.requests.status');

    /**
     * Переключатель рынка для super-admin (используется в topbar-user-info.blade.php).
     * Сохраняет выбранный market_id в сессии.
     */
    Route::post('/admin/switch-market', function (Request $request) {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

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
    })->name('filament.admin.switch-market');

    /**
     * Скачивание бэкапов PostgreSQL (ops-diagnostics).
     */
    Route::get('/ops-diagnostics/download/{file}', function (string $file) {
        abort_if(! auth()->user()?->isSuperAdmin(), 403, 'Доступ запрещён.');

        $path = storage_path('app/backups/' . $file);
        abort_if(! \Illuminate\Support\Facades\File::exists($path), 404, 'Файл не найден.');

        return response()->download($path, $file);
    })->name('filament.admin.ops-diagnostics.download');

    Route::get('/ops-diagnostics/backups-log', function () {
        abort_if(! auth()->user()?->isSuperAdmin(), 403, 'Доступ запрещён.');

        $files = collect(\Illuminate\Support\Facades\File::files(storage_path('logs')))
            ->filter(static fn ($file): bool => str_starts_with($file->getFilename(), 'backups') && str_ends_with($file->getFilename(), '.log'))
            ->sortByDesc(static fn ($file): int => $file->getMTime());

        $latest = $files->first();

        abort_if(! $latest, 404, 'Файл backup-log не найден.');

        return response()->file($latest->getPathname());
    })->name('filament.admin.ops-diagnostics.backup-log');

    /**
     * Единая логика выбора рынка + проверка доступа (просмотр карты).
     */
    $resolveMarketForMap = function (): Market {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $selectedMarketId = session("filament.{$panelId}.selected_market_id")
            ?? session('filament.admin.selected_market_id');

        if ($isSuperAdmin) {
            $market = filled($selectedMarketId)
                ? Market::query()->whereKey((int) $selectedMarketId)->first()
                : Market::query()->orderBy('id')->first();

            if (! $market) {
                $market = Market::query()->orderBy('id')->first();
            }
        } else {
            $marketId = (int) ($user->market_id ?? 0);
            $market = $marketId > 0 ? Market::query()->whereKey($marketId)->first() : null;

            $hasRoleAccess = method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(['market-admin', 'market-maintenance']);

            $hasPermissionAccess = method_exists($user, 'can') && (
                $user->can('markets.view')
                || $user->can('markets.update')
                || $user->can('markets.viewAny')
            );

            abort_unless($market && ($hasRoleAccess || $hasPermissionAccess), 403);
        }

        abort_unless($market, 404);

        return $market;
    };

    $bindingRiskWarnings = static function (
        bool $hasTenant,
        bool $hasActiveContract,
        bool $hasAccruals,
        ?string $debtStatus,
        ?string $debtStatusLabel = null
    ): array {
        $warnings = [];

        if ($hasTenant) {
            $warnings[] = 'У места уже есть арендатор.';
        }

        if ($hasActiveContract) {
            $warnings[] = 'У места есть активный договор.';
        }

        if ($hasAccruals) {
            $warnings[] = 'По месту есть начисления.';
        }

        if (in_array($debtStatus, ['pending', 'orange', 'red'], true)) {
            $warnings[] = 'По арендатору есть статус задолженности: ' . trim((string) ($debtStatusLabel ?: $debtStatus));
        }

        return $warnings;
    };

    $buildBindingRiskForSpace = static function (Market $market, MarketSpace $space) use ($bindingRiskWarnings): array {
        $tenant = $space->tenant instanceof Tenant ? $space->tenant : null;
        $debtStatus = $tenant ? trim((string) ($tenant->debt_status ?? '')) : '';
        $debtStatusLabel = null;

        if ($debtStatus !== '') {
            try {
                $debtStatusLabel = app(DebtStatusResolver::class)->labelForStatus($debtStatus, (int) $market->id);
            } catch (\Throwable) {
                $debtStatusLabel = Tenant::DEBT_STATUS_LABELS[$debtStatus] ?? $debtStatus;
            }
        }

        $hasTenant = (bool) $space->isEffectivelyOccupied();
        $today = now()->toDateString();

        $hasActiveContract = Schema::hasTable('tenant_contracts')
            && DB::table('tenant_contracts')
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', (int) $space->id)
                ->where('is_active', true)
                ->where(function ($q) use ($today): void {
                    $q->whereNull('ends_at')
                        ->orWhereDate('ends_at', '>=', $today);
                })
                ->exists();

        $hasAccruals = Schema::hasTable('tenant_accruals')
            && DB::table('tenant_accruals')
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', (int) $space->id)
                ->exists();

        $warnings = $bindingRiskWarnings(
            $hasTenant,
            $hasActiveContract,
            $hasAccruals,
            $debtStatus !== '' ? $debtStatus : null,
            $debtStatusLabel,
        );

        return [
            'has_tenant' => $hasTenant,
            'has_active_contract' => $hasActiveContract,
            'has_accruals' => $hasAccruals,
            'debt_status' => $debtStatus !== '' ? $debtStatus : null,
            'debt_status_label' => $debtStatusLabel,
            'requires_confirmation' => ! empty($warnings),
            'warnings' => $warnings,
        ];
    };

    $resolveSpaceDisplayLabel = static function (?MarketSpace $space): ?string {
        if (! $space) {
            return null;
        }

        $label = trim((string) ($space->number ?? ''));
        if ($label === '') {
            $label = trim((string) ($space->code ?? ''));
        }
        if ($label === '') {
            $label = trim((string) ($space->display_name ?? ''));
        }
        if ($label === '') {
            $label = (string) ((int) $space->id);
        }

        return $label !== '' ? $label : null;
    };

    $buildSpaceEffectiveOccupancy = static function (?MarketSpace $space) use ($resolveSpaceDisplayLabel): array {
        if (! $space) {
            return [
                'space_effective_tenant_id' => null,
                'space_effective_tenant_name' => null,
                'space_effective_is_occupied' => false,
                'space_occupancy_source' => 'none',
                'space_occupancy_source_space_id' => null,
                'space_occupancy_source_space_number' => null,
            ];
        }

        $sourceSpace = $space->effectiveOccupancySourceSpace();
        $tenant = $space->effectiveTenant();

        $sourceSpaceLabel = $resolveSpaceDisplayLabel($sourceSpace);

        return [
            'space_effective_tenant_id' => $tenant?->id ? (int) $tenant->id : null,
            'space_effective_tenant_name' => $space->effectiveTenantName(),
            'space_effective_is_occupied' => $space->isEffectivelyOccupied(),
            'space_occupancy_source' => $space->effectiveOccupancySource(),
            'space_occupancy_source_space_id' => $sourceSpace?->id ? (int) $sourceSpace->id : null,
            'space_occupancy_source_space_number' => $sourceSpaceLabel !== '' ? $sourceSpaceLabel : null,
        ];
    };

    $buildSpaceEffectiveFinancialStatus = static function (?MarketSpace $space) use ($buildSpaceEffectiveOccupancy, $resolveSpaceDisplayLabel): array {
        $empty = [
            'space_effective_debt_status' => null,
            'space_effective_debt_status_label' => null,
            'space_effective_debt_status_mode' => 'auto',
            'space_effective_debt_status_updated_at' => null,
            'space_effective_debt_status_source' => null,
            'space_effective_debt_overdue_days' => null,
            'space_effective_debt_status_scope' => 'none',
            'space_financial_source' => 'none',
            'space_financial_source_space_id' => null,
            'space_financial_source_space_number' => null,
        ];

        if (! $space) {
            return $empty;
        }

        $occupancy = $buildSpaceEffectiveOccupancy($space);
        $occupancySource = (string) ($occupancy['space_occupancy_source'] ?? 'none');

        $sourceSpace = $space;
        $financialSource = 'direct';

        if ($occupancySource === 'parent') {
            $parentSpace = $space->effectiveOccupancySourceSpace();
            if ($parentSpace instanceof MarketSpace) {
                $sourceSpace = $parentSpace;
                $financialSource = 'parent';
            }
        }

        $sourceSpaceLabel = $resolveSpaceDisplayLabel($sourceSpace);
        $sourceTenant = $sourceSpace->tenant instanceof Tenant ? $sourceSpace->tenant : null;

        if (! $sourceTenant) {
            return array_merge($empty, [
                'space_financial_source' => $financialSource === 'parent' ? 'parent' : 'none',
                'space_financial_source_space_id' => $financialSource === 'parent' ? (int) $sourceSpace->id : null,
                'space_financial_source_space_number' => $financialSource === 'parent' ? $sourceSpaceLabel : null,
            ]);
        }

        $resolvedDebt = app(DebtStatusResolver::class)->resolveForMarketSpace((int) $sourceSpace->id, (int) $sourceTenant->market_id);
        $debtScope = (string) ($resolvedDebt['extra']['scope'] ?? 'none');

        return [
            'space_effective_debt_status' => $resolvedDebt['status'] ?? null,
            'space_effective_debt_status_label' => $resolvedDebt['label'] ?? null,
            'space_effective_debt_status_mode' => $resolvedDebt['mode'] ?? 'auto',
            'space_effective_debt_status_updated_at' => $resolvedDebt['updated_at'] ?? null,
            'space_effective_debt_status_source' => $resolvedDebt['source'] ?? null,
            'space_effective_debt_overdue_days' => $resolvedDebt['extra']['overdue_days'] ?? null,
            'space_effective_debt_status_scope' => $debtScope,
            'space_financial_source' => $financialSource,
            'space_financial_source_space_id' => (int) $sourceSpace->id,
            'space_financial_source_space_number' => $sourceSpaceLabel,
        ];
    };

    $marketSpaceHasUsableShape = static function (int $marketId, int $spaceId, ?int $ignoreShapeId = null): bool {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return false;
        }

        $query = MarketSpaceMapShape::query()
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId)
            ->where('is_active', true)
            ->where(static function ($sub): void {
                $sub->where(static function ($bbox): void {
                    $bbox->whereNotNull('bbox_x1')
                        ->whereNotNull('bbox_y1')
                        ->whereNotNull('bbox_x2')
                        ->whereNotNull('bbox_y2')
                        ->whereColumn('bbox_x1', '<', 'bbox_x2')
                        ->whereColumn('bbox_y1', '<', 'bbox_y2');
                })->orWhereJsonLength('polygon', '>=', 3);
            });

        if ($ignoreShapeId !== null && $ignoreShapeId > 0) {
            $query->whereKeyNot($ignoreShapeId);
        }

        return $query->exists();
    };

    /**
     * Редактирование разметки: market-admin + super-admin (+ markets.update как запасной вариант).
     */
    $ensureCanEditShapes = static function (): void {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['market-admin']);
        $canByPermission = method_exists($user, 'can') && $user->can('markets.update');

        abort_unless($isSuperAdmin || $isMarketAdmin || $canByPermission, 403);
    };

    /**
     * Та же логика, но без abort — для UI (показать/скрыть кнопку "Разметка").
     */
    $canEditShapes = static function (): bool {
        $user = Filament::auth()->user();
        if (! $user) {
            return false;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['market-admin']);
        $canByPermission = method_exists($user, 'can') && $user->can('markets.update');

        return $isSuperAdmin || $isMarketAdmin || $canByPermission;
    };

    $hasMapReviewColumns = static function (): bool {
        return Schema::hasTable('market_spaces')
            && Schema::hasColumn('market_spaces', 'map_review_status')
            && Schema::hasColumn('market_spaces', 'map_reviewed_at')
            && Schema::hasColumn('market_spaces', 'map_reviewed_by');
    };

    $mapReviewStatusLabel = static function (?string $status): ?string {
        return match ($status) {
            'matched' => 'Совпало',
            'changed' => 'Есть безопасное изменение',
            'changed_tenant' => 'Сменился арендатор',
            'conflict' => 'Конфликт',
            'not_found' => 'Не найдено на карте',
            default => null,
        };
    };

    $buildMapReviewProgress = static function (Market $market) use ($hasMapReviewColumns, $mapReviewStatusLabel): array {
        $baseQuery = MarketSpace::query()->where('market_id', (int) $market->id);

        if (Schema::hasColumn('market_spaces', 'is_active')) {
            $baseQuery->where('is_active', true);
        }

        $total = (int) (clone $baseQuery)->count();

        if (! $hasMapReviewColumns()) {
            return [
                'total' => $total,
                'reviewed' => 0,
                'remaining' => $total,
                'percent' => 0,
                'counts' => [],
                'labels' => [],
            ];
        }

        $counts = (clone $baseQuery)
            ->whereNotNull('map_review_status')
            ->select('map_review_status', DB::raw('count(*) as aggregate'))
            ->groupBy('map_review_status')
            ->pluck('aggregate', 'map_review_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $reviewed = array_sum($counts);
        $remaining = max($total - $reviewed, 0);

        return [
            'total' => $total,
            'reviewed' => $reviewed,
            'remaining' => $remaining,
            'percent' => $total > 0 ? (int) round(($reviewed / $total) * 100) : 0,
            'counts' => $counts,
            'labels' => collect(array_keys($counts))
                ->mapWithKeys(fn (string $status): array => [$status => $mapReviewStatusLabel($status) ?? $status])
                ->all(),
        ];
    };

    /**
     * Нормализуем polygon к формату [{x,y}, ...] и считаем bbox.
     *
     * @return array{0: array<int, array{x: float, y: float}>, 1: array{bbox_x1: float, bbox_y1: float, bbox_x2: float, bbox_y2: float}}
     */
    $normalizePolygonAndBbox = static function (array $rawPolygon): array {
        $poly = [];

        foreach ($rawPolygon as $p) {
            if (! is_array($p)) {
                continue;
            }

            $x = $p['x'] ?? $p[0] ?? null;
            $y = $p['y'] ?? $p[1] ?? null;

            if (! is_numeric($x) || ! is_numeric($y)) {
                continue;
            }

            $poly[] = [
                'x' => (float) $x,
                'y' => (float) $y,
            ];
        }

        if (count($poly) < 3) {
            throw ValidationException::withMessages([
                'polygon' => 'polygon должен содержать минимум 3 корректные точки',
            ]);
        }

        $xs = array_map(static fn ($p) => $p['x'], $poly);
        $ys = array_map(static fn ($p) => $p['y'], $poly);

        $bbox = [
            'bbox_x1' => round(min($xs), 2),
            'bbox_y1' => round(min($ys), 2),
            'bbox_x2' => round(max($xs), 2),
            'bbox_y2' => round(max($ys), 2),
        ];

        return [$poly, $bbox];
    };

    /**
     * CREATE shape.
     *
     * Важно: эти endpoints НЕ должны создавать/обновлять MarketSpace.
     * Они работают только с MarketSpaceMapShape и привязкой market_space_id к существующему месту.
     */
    Route::post('/admin/market-map/shapes', function (Request $request) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
        $normalizePolygonAndBbox
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $validated = $request->validate([
            'market_space_id' => ['nullable', 'integer', 'exists:market_spaces,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],

            'polygon' => ['required', 'array', 'min:3'],

            'stroke_color' => ['nullable', 'string', 'max:32'],
            'fill_color' => ['nullable', 'string', 'max:32'],
            'fill_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stroke_width' => ['nullable', 'numeric', 'min:0', 'max:50'],

            'meta' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        $marketSpaceId = $validated['market_space_id'] ?? null;

        if ($marketSpaceId !== null) {
            $belongs = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $marketSpaceId)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'market_space_id' => 'market_space_id не принадлежит текущему рынку',
                ]);
            }
        }

        [$polygon, $bbox] = $normalizePolygonAndBbox($validated['polygon']);

        if ($marketSpaceId !== null) {
            $existing = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('market_space_id', (int) $marketSpaceId)
                ->first();

            if ($existing) {
                $existing->polygon = $polygon;
                $existing->bbox_x1 = $bbox['bbox_x1'];
                $existing->bbox_y1 = $bbox['bbox_y1'];
                $existing->bbox_x2 = $bbox['bbox_x2'];
                $existing->bbox_y2 = $bbox['bbox_y2'];

                $existing->stroke_color = $validated['stroke_color'] ?? ($existing->stroke_color ?: '#00A3FF');
                $existing->fill_color = $validated['fill_color'] ?? ($existing->fill_color ?: '#00A3FF');
                $existing->fill_opacity = array_key_exists('fill_opacity', $validated)
                    ? (float) $validated['fill_opacity']
                    : (float) ($existing->fill_opacity ?? 0.12);
                $existing->stroke_width = array_key_exists('stroke_width', $validated)
                    ? (float) $validated['stroke_width']
                    : (float) ($existing->stroke_width ?? 1.5);

                $existing->meta = $validated['meta'] ?? $existing->meta;
                $existing->sort_order = array_key_exists('sort_order', $validated)
                    ? (int) $validated['sort_order']
                    : (int) ($existing->sort_order ?? 0);

                $existing->is_active = array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : true;

                try {
                    $existing->save();
                } catch (\Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Не удалось обновить существующий shape (upsert): ' . $e->getMessage(),
                    ], 500);
                }

                return response()->json([
                    'ok' => true,
                    'mode' => 'updated',
                    'item' => $existing->fresh()->toArray(),
                ]);
            }
        }

        try {
            $shape = MarketSpaceMapShape::query()->create([
                'market_id' => (int) $market->id,
                'market_space_id' => $marketSpaceId !== null ? (int) $marketSpaceId : null,
                'page' => $page,
                'version' => $version,

                'polygon' => $polygon,
                ...$bbox,

                'stroke_color' => $validated['stroke_color'] ?? '#00A3FF',
                'fill_color' => $validated['fill_color'] ?? '#00A3FF',
                'fill_opacity' => array_key_exists('fill_opacity', $validated) ? (float) $validated['fill_opacity'] : 0.12,
                'stroke_width' => array_key_exists('stroke_width', $validated) ? (float) $validated['stroke_width'] : 1.5,

                'meta' => $validated['meta'] ?? null,
                'sort_order' => array_key_exists('sort_order', $validated) ? (int) $validated['sort_order'] : 0,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось создать shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'created',
            'item' => $shape->fresh()->toArray(),
        ]);
    })->name('filament.admin.market-map.shapes.store');

    /**
     * UPDATE shape.
     */
    Route::patch('/admin/market-map/shapes/{shape}', function (Request $request, $shape) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
        $normalizePolygonAndBbox
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $shapeId = (int) $shape;
        abort_unless($shapeId > 0, 404);

        $shapeModel = MarketSpaceMapShape::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($shapeId)
            ->firstOrFail();

        $validated = $request->validate([
            'market_space_id' => ['nullable', 'integer', 'exists:market_spaces,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],

            'polygon' => ['nullable', 'array', 'min:3'],

            'stroke_color' => ['nullable', 'string', 'max:32'],
            'fill_color' => ['nullable', 'string', 'max:32'],
            'fill_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stroke_width' => ['nullable', 'numeric', 'min:0', 'max:50'],

            'meta' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $nextPage = array_key_exists('page', $validated)
            ? (int) ($validated['page'] ?? 1)
            : (int) ($shapeModel->page ?? 1);

        $nextVersion = array_key_exists('version', $validated)
            ? (int) ($validated['version'] ?? 1)
            : (int) ($shapeModel->version ?? 1);

        $nextMarketSpaceId = array_key_exists('market_space_id', $validated)
            ? $validated['market_space_id']
            : $shapeModel->market_space_id;

        if (array_key_exists('market_space_id', $validated)) {
            $marketSpaceId = $validated['market_space_id'];

            if ($marketSpaceId !== null) {
                $belongs = MarketSpace::query()
                    ->where('market_id', (int) $market->id)
                    ->whereKey((int) $marketSpaceId)
                    ->exists();

                if (! $belongs) {
                    throw ValidationException::withMessages([
                        'market_space_id' => 'market_space_id не принадлежит текущему рынку',
                    ]);
                }
            }
        }

        if ($nextMarketSpaceId !== null) {
            $conflict = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $nextPage)
                ->where('version', $nextVersion)
                ->where('market_space_id', (int) $nextMarketSpaceId)
                ->where('id', '!=', (int) $shapeModel->id)
                ->first();

            if ($conflict) {
                try {
                    $conflict->market_space_id = null;
                    $conflict->is_active = false;
                    $conflict->save();
                } catch (\Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Не удалось освободить конфликтующую привязку: ' . $e->getMessage(),
                    ], 500);
                }
            }
        }

        if (array_key_exists('market_space_id', $validated)) {
            $shapeModel->market_space_id = $validated['market_space_id'] !== null
                ? (int) $validated['market_space_id']
                : null;
        }

        if (array_key_exists('page', $validated)) {
            $shapeModel->page = $nextPage;
        }

        if (array_key_exists('version', $validated)) {
            $shapeModel->version = $nextVersion;
        }

        if (array_key_exists('polygon', $validated) && $validated['polygon'] !== null) {
            [$polygon, $bbox] = $normalizePolygonAndBbox($validated['polygon']);
            $shapeModel->polygon = $polygon;
            $shapeModel->bbox_x1 = $bbox['bbox_x1'];
            $shapeModel->bbox_y1 = $bbox['bbox_y1'];
            $shapeModel->bbox_x2 = $bbox['bbox_x2'];
            $shapeModel->bbox_y2 = $bbox['bbox_y2'];
        }

        foreach (['stroke_color', 'fill_color', 'meta'] as $k) {
            if (array_key_exists($k, $validated)) {
                $shapeModel->{$k} = $validated[$k];
            }
        }

        if (array_key_exists('fill_opacity', $validated)) {
            $shapeModel->fill_opacity = $validated['fill_opacity'] !== null ? (float) $validated['fill_opacity'] : null;
        }

        if (array_key_exists('stroke_width', $validated)) {
            $shapeModel->stroke_width = $validated['stroke_width'] !== null ? (float) $validated['stroke_width'] : null;
        }

        if (array_key_exists('sort_order', $validated)) {
            $shapeModel->sort_order = (int) ($validated['sort_order'] ?? 0);
        }

        if (array_key_exists('is_active', $validated)) {
            $shapeModel->is_active = (bool) $validated['is_active'];
        }

        try {
            $shapeModel->save();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось обновить shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'item' => $shapeModel->fresh()->toArray(),
        ]);
    })->name('filament.admin.market-map.shapes.update');

    /**
     * DELETE shape (soft через is_active=0).
     */
    Route::delete('/admin/market-map/shapes/{shape}', function ($shape) use (
        $resolveMarketForMap,
        $ensureCanEditShapes
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => false,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
            ], 422);
        }

        $shapeId = (int) $shape;
        abort_unless($shapeId > 0, 404);

        $shapeModel = MarketSpaceMapShape::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($shapeId)
            ->firstOrFail();

        try {
            $shapeModel->market_space_id = null;
            $shapeModel->is_active = false;
            $shapeModel->save();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось удалить shape: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    })->name('filament.admin.market-map.shapes.destroy');

    /**
     * Слой разметки: список полигонов (PDF-координаты) для отрисовки поверх canvas.
     */
    Route::get('/admin/market-map/shapes', function (Request $request) use ($resolveMarketForMap, $mapReviewStatusLabel, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => true,
                'items' => [],
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
                'meta' => compact('page', 'version'),
            ]);
        }

        try {
            $rows = MarketSpaceMapShape::query()
                ->with(['marketSpace' => static function ($query) {
                    $query->select('id', 'tenant_id', 'display_name', 'number', 'code', 'status', 'is_active', 'map_review_status', 'map_reviewed_at', 'space_group_role', 'space_group_parent_id', 'space_group_token')
                        ->with([
                            'tenant:id,name,short_name,slug',
                            'spaceGroupParent' => static function ($parentQuery): void {
                                $parentQuery->select('id', 'tenant_id', 'number', 'code', 'display_name', 'space_group_role', 'space_group_parent_id', 'space_group_token')
                                    ->with(['tenant:id,name,short_name,slug']);
                            },
                        ]);
                }])
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('is_active', true)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->limit(5000)
                ->get([
                    'id',
                    'market_space_id',
                    'page',
                    'version',
                    'polygon',
                    'stroke_color',
                    'fill_color',
                    'fill_opacity',
                    'stroke_width',
                    'sort_order',
                    'is_active',
                    'meta',
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'items' => [],
                'message' => 'Ошибка чтения слоёв карты: ' . $e->getMessage(),
                'meta' => compact('page', 'version'),
            ], 500);
        }

        $spaceIds = $rows->pluck('market_space_id')
            ->filter()
            ->unique()
            ->values();

        $spacesById = $spaceIds->isNotEmpty()
            ? MarketSpace::query()
                ->with([
                    'tenant:id,market_id,name,short_name,external_id,one_c_uid,debt_status,debt_status_updated_at,updated_at',
                    'spaceGroupParent.tenant',
                ])
                ->where('market_id', (int) $market->id)
                ->whereIn('id', $spaceIds)
                ->get(['id', 'tenant_id', 'number', 'code', 'display_name', 'rent_rate_value', 'rent_rate_unit', 'map_review_status', 'map_reviewed_at', 'space_group_role', 'space_group_parent_id', 'space_group_token'])
                ->keyBy('id')
            : collect();

        $currentRentRatesBySpaceId = collect();
        $latestRentRatesBySpaceId = collect();

        if ($spaceIds->isNotEmpty()) {
            $periodResolver = app(\App\Services\Operations\MarketPeriodResolver::class);
            $currentPeriodDate = $periodResolver->resolveMarketPeriod($market, null)->toDateString();

            $currentRentRatesBySpaceId = DB::table('tenant_accruals')
                ->where('market_id', (int) $market->id)
                ->whereIn('market_space_id', $spaceIds)
                ->where('period', $currentPeriodDate)
                ->whereNotNull('rent_rate')
                ->groupBy('market_space_id')
                ->selectRaw('market_space_id, MAX(rent_rate) as rent_rate')
                ->get()
                ->mapWithKeys(static fn ($row) => [(int) $row->market_space_id => (float) $row->rent_rate]);

            $latestRentRatesBySpaceId = DB::table('tenant_accruals')
                ->where('market_id', (int) $market->id)
                ->whereIn('market_space_id', $spaceIds)
                ->whereNotNull('rent_rate')
                ->orderBy('market_space_id')
                ->orderByDesc('period')
                ->orderByDesc('id')
                ->get(['market_space_id', 'rent_rate'])
                ->groupBy('market_space_id')
                ->map(static function ($rowsForSpace) {
                    $first = $rowsForSpace->first();

                    return $first && $first->rent_rate !== null
                        ? (float) $first->rent_rate
                        : null;
                });
        }

        $items = $rows->map(static function (MarketSpaceMapShape $s) use (
            $spacesById,
            $currentRentRatesBySpaceId,
            $latestRentRatesBySpaceId,
            $mapReviewStatusLabel,
            $buildSpaceEffectiveOccupancy,
            $buildSpaceEffectiveFinancialStatus
        ): array {
            $space = $s->market_space_id ? $spacesById->get((int) $s->market_space_id) : null;
            $tenant = $space?->tenant;
            $spaceId = $space?->id ? (int) $space->id : null;
            $effectiveOccupancy = $buildSpaceEffectiveOccupancy($space);
            $effectiveFinancial = $buildSpaceEffectiveFinancialStatus($space);

            // Для каждого shape используем статус по конкретному месту
            $resolver = app(DebtStatusResolver::class);
            $resolvedDebt = $space && $tenant
                ? $resolver->resolveForMarketSpace($space->id, $tenant->market_id)
                : ($tenant
                    ? $resolver->resolve($tenant)
                    : [
                        'mode' => 'auto',
                        'status' => 'gray',
                        'label' => 'Нет данных',
                        'updated_at' => null,
                        'source' => null,
                        'severity' => 0,
                        'extra' => ['scope' => 'none'],
                    ]);

            $rentRateValue = $space?->rent_rate_value !== null ? (float) $space->rent_rate_value : null;
            $rentRateUnit = filled($space?->rent_rate_unit) ? (string) $space->rent_rate_unit : null;

            if ($spaceId && $rentRateValue === null) {
                $currentRate = $currentRentRatesBySpaceId->get($spaceId);
                if ($currentRate !== null) {
                    $rentRateValue = (float) $currentRate;
                } else {
                    $latestRate = $latestRentRatesBySpaceId->get($spaceId);
                    if ($latestRate !== null) {
                        $rentRateValue = (float) $latestRate;
                    }
                }
            }

            // Определяем scope из extra или по умолчанию
            $debtScope = $resolvedDebt['extra']['scope'] ?? 'none';

            return [
                'id' => (int) $s->id,
                'market_space_id' => $s->market_space_id ? (int) $s->market_space_id : null,
                'page' => (int) ($s->page ?? 1),
                'version' => (int) ($s->version ?? 1),
                'polygon' => is_array($s->polygon) ? $s->polygon : [],
                'bbox_x1' => $s->bbox_x1 !== null ? (float) $s->bbox_x1 : null,
                'bbox_y1' => $s->bbox_y1 !== null ? (float) $s->bbox_y1 : null,
                'bbox_x2' => $s->bbox_x2 !== null ? (float) $s->bbox_x2 : null,
                'bbox_y2' => $s->bbox_y2 !== null ? (float) $s->bbox_y2 : null,

                'stroke_color' => (string) ($s->stroke_color ?: '#00A3FF'),
                'fill_color' => (string) ($s->fill_color ?: '#00A3FF'),
                'fill_opacity' => $s->fill_opacity !== null ? (float) $s->fill_opacity : 0.12,
                'stroke_width' => $s->stroke_width !== null ? (float) $s->stroke_width : 1.5,

                'sort_order' => (int) ($s->sort_order ?? 0),
                'is_active' => (bool) ($s->is_active ?? true),
                'meta' => is_array($s->meta) ? $s->meta : [],

                'debt_status' => $resolvedDebt['status'],
                'debt_status_label' => $resolvedDebt['label'],
                'debt_status_mode' => $resolvedDebt['mode'],
                'debt_status_updated_at' => $resolvedDebt['updated_at'],
                'debt_status_source' => $resolvedDebt['source'] ?? null,
                'debt_overdue_days' => $resolvedDebt['extra']['overdue_days'] ?? null,
                'debt_status_scope' => $debtScope,
                ...$effectiveFinancial,

                'space_number' => $space?->number ? (string) $space->number : null,
                'space_code' => $space?->code ? (string) $space->code : null,
                'space_display_name' => $space?->display_name ? (string) $space->display_name : null,
                'space_group_role' => $space?->space_group_role ? (string) $space->space_group_role : null,
                'space_group_parent_id' => $space?->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                'space_group_token' => $space?->space_group_token ? (string) $space->space_group_token : null,
                'space_tenant_name' => $tenant?->short_name ?: ($tenant?->name ?: null),
                'space_tenant_id' => $space?->tenant_id ? (int) $space->tenant_id : null,
                'space_is_active' => $space?->is_active ?? false,
                'space_is_occupied' => $space?->tenant_id !== null,
                ...$effectiveOccupancy,
                'space_review_status' => $space?->map_review_status ? (string) $space->map_review_status : null,
                'space_review_status_label' => $mapReviewStatusLabel($space?->map_review_status),
                'space_reviewed_at' => optional($space?->map_reviewed_at)?->toIso8601String(),
                'space_rent_rate_value' => $rentRateValue,
                'space_rent_rate_unit' => $rentRateUnit,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'meta' => [
                'market_id' => (int) $market->id,
                'page' => $page,
                'version' => $version,
            ],
        ]);
    })->name('filament.admin.market-map.shapes');

    /**
     * Быстрая проверка места для поля "Место ID" (поддерживает ?id= и ?number=/ ?code=).
     * - ?id=123
     * - ?number=П3/2  (по точному совпадению number либо code)
     */
    Route::get('/admin/market-map/space', function (Request $request) use ($resolveMarketForMap, $mapReviewStatusLabel, $buildBindingRiskForSpace, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1', 'required_without:number'],
            'number' => ['nullable', 'string', 'max:120', 'required_without:id'],
        ]);

        $space = null;

        if (! empty($validated['id'])) {
            $id = (int) $validated['id'];

            $space = MarketSpace::query()
                ->with([
                    'tenant',
                    'spaceGroupParent.tenant',
                ])
                ->where('market_id', (int) $market->id)
                ->whereKey($id)
                ->first();
        } else {
            $number = trim((string) ($validated['number'] ?? ''));
            $number = str_replace(["\n", "\r", "\t"], ' ', $number);
            $number = trim($number);

            $space = MarketSpace::query()
                ->with([
                    'tenant',
                    'spaceGroupParent.tenant',
                ])
                ->where('market_id', (int) $market->id)
                ->where(function ($q) use ($number) {
                    $q->where('number', '=', $number)
                        ->orWhere('code', '=', $number);
                })
                ->orderBy('id')
                ->first();
        }

        if (! $space) {
            return response()->json([
                'ok' => true,
                'found' => false,
                'item' => null,
            ]);
        }

        $tenant = $space->tenant;
        $tenantName = null;

        if ($tenant) {
            $tenantName = (string) ($tenant->display_name ?? '');
            if ($tenantName === '') {
                $tenantName = (string) ($tenant->short_name ?? '');
            }
            if ($tenantName === '') {
                $tenantName = (string) ($tenant->name ?? '');
            }
        }

        $bindingRisk = $buildBindingRiskForSpace($market, $space);
        $spaceGroupRole = (string) ($space->space_group_role ?? '');

        return response()->json([
                'ok' => true,
                'found' => true,
                'item' => [
                    'id' => (int) $space->id,
                    'number' => (string) ($space->number ?? ''),
                'code' => (string) ($space->code ?? ''),
                'display_name' => (string) ($space->display_name ?? ''),
                'area_sqm' => (string) ($space->area_sqm ?? ''),
                'status' => (string) ($space->status ?? ''),
                    'space_group_role' => $spaceGroupRole,
                    'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                    'is_space_group_parent' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT,
                    'result_type' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT ? 'group' : 'space',
                    'review_status' => (string) ($space->map_review_status ?? ''),
                    'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
                    'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
                    'tenant' => $tenant ? [
                        'id' => (int) ($tenant->id ?? 0),
                        'name' => (string) ($tenantName ?? ''),
                    ] : null,
                    ...$buildSpaceEffectiveOccupancy($space),
                    ...$buildSpaceEffectiveFinancialStatus($space),
                    'binding_risk' => $bindingRisk,
                ],
            ]);
    })->name('filament.admin.market-map.space');

    /**
     * Поиск мест для автокомплита (номер/код/арендатор/ID).
     * Поддерживает ?number= и ?q=.
     * Поддерживает ?without_shapes=1 для получения непройденных мест без фигур.
     */
    Route::get('/admin/market-map/spaces', function (Request $request) use ($resolveMarketForMap, $mapReviewStatusLabel, $hasMapReviewColumns, $bindingRiskWarnings, $buildBindingRiskForSpace, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'number' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'without_shapes' => ['nullable', 'boolean'],
            'group_parents_only' => ['nullable', 'boolean'],
        ]);

        $withoutShapes = (bool) ($validated['without_shapes'] ?? false);

        // Режим: список мест без usable bbox для ревизии
        if ($withoutShapes) {
            $limit = (int) ($validated['limit'] ?? 200);

            // Проверяем наличие таблиц и колонок
            if (! Schema::hasTable('market_space_map_shapes')) {
                return response()->json([
                    'ok' => false,
                    'items' => [],
                    'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
                ], 422);
            }

            if (! $hasMapReviewColumns()) {
                return response()->json([
                    'ok' => false,
                    'items' => [],
                    'message' => 'Колонки map_review_status отсутствуют в market_spaces.',
                ], 422);
            }

            // Запрос: активные места без map_review_status И без usable bbox
            // Usable bbox = active shape с (bbox_x1/bbox_y1/bbox_x2/bbox_y2 NOT NULL) ИЛИ (polygon ≥3 точек)
            $query = MarketSpace::query()
                ->with([
                    'tenant',
                    'spaceGroupParent.tenant',
                ])
                ->where('market_id', (int) $market->id)
                ->whereNull('map_review_status');

            // Фильтр по is_active если колонка существует
            if (Schema::hasColumn('market_spaces', 'is_active')) {
                $query->where('is_active', true);
            }

            // Parent-группы не считаются "местами без фигур":
            // у группы может не быть собственной фигуры, она отображается через дочерние места.
            $query->where(function ($qq) {
                $qq->whereNull('space_group_role')
                    ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_PARENT);
            });

            // Исключаем места у которых ЕСТЬ usable shape для review navigation
            // (согласовано с buildReviewNavItemsFromShapes() на фронте)
            //
            // Usable shape = is_active = true И (bbox ИЛИ polygon fallback):
            //   - bbox_x1/bbox_y1/bbox_x2/bbox_y2 NOT NULL И bbox_x1 < bbox_x2 И bbox_y1 < bbox_y2
            //     → готовый usable bbox
            //   - ИЛИ JSON_LENGTH(polygon) >= 3  →  bbox вычисляется из polygon
            //
            // whereDoesntHave инвертирует: вернёт места БЕЗ usable shape
            $query->whereDoesntHave('mapShapes', function ($q) {
                $q->where('is_active', true)
                  ->where(function ($sub) {
                      // Вариант 1: есть bbox с корректными размерами (x1 < x2 и y1 < y2)
                      $sub->whereNotNull('bbox_x1')
                          ->whereNotNull('bbox_y1')
                          ->whereNotNull('bbox_x2')
                          ->whereNotNull('bbox_y2')
                          ->whereColumn('bbox_x1', '<', 'bbox_x2')
                          ->whereColumn('bbox_y1', '<', 'bbox_y2');
                      
                      // Вариант 2: polygon с ≥3 точками (fallback для вычисляемого bbox)
                      // orWhere внутри $sub гарантирует что это условие
                      // применяется вместе с is_active = true (внешний where)
                      $sub->orWhereJsonLength('polygon', '>=', 3);
                  });
            });

            // Поиск по строке q (номер / код / display_name / арендатор)
            $rawQ = trim((string) ($validated['q'] ?? ''));
            if ($rawQ !== '') {
                $qEsc = str_replace(['%', '_'], ['\%', '\\_'], $rawQ);
                $qLike = '%' . $qEsc . '%';

                $query->where(function ($qq) use ($qLike) {
                    $qq->where('number', 'like', $qLike)
                       ->orWhere('code', 'like', $qLike)
                       ->orWhere('display_name', 'like', $qLike)
                       ->orWhereHas('tenant', function ($tq) use ($qLike) {
                           $tq->where('name', 'like', $qLike)
                              ->orWhere('display_name', 'like', $qLike)
                              ->orWhere('short_name', 'like', $qLike);
                       });
                });
            }

            $rows = $query
                ->orderBy('number')
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'number', 'code', 'display_name', 'tenant_id', 'space_group_role', 'space_group_parent_id']);

            $spaceIds = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->values();
            $today = now()->toDateString();

            $activeContractSpaceIds = collect();
            if (Schema::hasTable('tenant_contracts') && $spaceIds->isNotEmpty()) {
                $activeContractSpaceIds = DB::table('tenant_contracts')
                    ->where('market_id', (int) $market->id)
                    ->whereIn('market_space_id', $spaceIds)
                    ->where('is_active', true)
                    ->where(function ($q) use ($today): void {
                        $q->whereNull('ends_at')
                            ->orWhereDate('ends_at', '>=', $today);
                    })
                    ->pluck('market_space_id')
                    ->map(static fn ($id): int => (int) $id);
            }

            $accrualSpaceIds = collect();
            if (Schema::hasTable('tenant_accruals') && $spaceIds->isNotEmpty()) {
                $accrualSpaceIds = DB::table('tenant_accruals')
                    ->where('market_id', (int) $market->id)
                    ->whereIn('market_space_id', $spaceIds)
                    ->distinct()
                    ->pluck('market_space_id')
                    ->map(static fn ($id): int => (int) $id);
            }

            $activeContractSpaceIdSet = $activeContractSpaceIds->flip();
            $accrualSpaceIdSet = $accrualSpaceIds->flip();

            $items = $rows->map(static function (MarketSpace $space) use ($mapReviewStatusLabel, $activeContractSpaceIdSet, $accrualSpaceIdSet, $bindingRiskWarnings, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus): array {
                $tenant = $space->tenant;
                $effectiveOccupancy = $buildSpaceEffectiveOccupancy($space);
                $effectiveFinancial = $buildSpaceEffectiveFinancialStatus($space);

                $tenantName = null;
                if ($tenant) {
                    $tenantName = (string) ($tenant->display_name ?? '');
                    if ($tenantName === '') {
                        $tenantName = (string) ($tenant->short_name ?? '');
                    }
                    if ($tenantName === '') {
                        $tenantName = (string) ($tenant->name ?? '');
                    }
                }

                $debtStatus = $tenant ? trim((string) ($tenant->debt_status ?? '')) : '';
                $hasTenant = (bool) ($effectiveOccupancy['space_effective_is_occupied'] ?? false);
                $hasActiveContract = $activeContractSpaceIdSet->has((int) $space->id);
                $hasAccruals = $accrualSpaceIdSet->has((int) $space->id);
                $debtStatusLabel = $debtStatus !== ''
                    ? (Tenant::DEBT_STATUS_LABELS[$debtStatus] ?? $debtStatus)
                    : null;
                $bindingWarnings = $bindingRiskWarnings(
                    $hasTenant,
                    $hasActiveContract,
                    $hasAccruals,
                    $debtStatus !== '' ? $debtStatus : null,
                    $debtStatusLabel,
                );

                $spaceGroupRole = (string) ($space->space_group_role ?? '');

                return [
                    'id' => (int) $space->id,
                    'number' => (string) ($space->number ?? ''),
                    'code' => (string) ($space->code ?? ''),
                    'display_name' => (string) ($space->display_name ?? ''),
                    'space_group_role' => $spaceGroupRole,
                    'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                    'is_space_group_parent' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT,
                    'result_type' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT ? 'group' : 'space',
                    'review_status' => '',
                    'review_status_label' => '',
                    'tenant' => $tenant ? [
                        'id' => (int) ($tenant->id ?? 0),
                        'name' => (string) ($tenantName ?? ''),
                    ] : null,
                    ...$effectiveOccupancy,
                    ...$effectiveFinancial,
                    'without_shapes' => true,
                    'binding_risk' => [
                        'has_tenant' => $hasTenant,
                        'has_active_contract' => $hasActiveContract,
                        'has_accruals' => $hasAccruals,
                        'debt_status' => $debtStatus !== '' ? $debtStatus : null,
                        'debt_status_label' => $debtStatusLabel,
                        'requires_confirmation' => ! empty($bindingWarnings),
                        'warnings' => $bindingWarnings,
                    ],
                ];
            })->values();

            return response()->json(['ok' => true, 'items' => $items, 'meta' => ['without_shapes' => true, 'count' => count($items)]]);
        }

        // Обычный режим поиска
        $raw = trim((string) ($validated['q'] ?? $validated['number'] ?? ''));
        $raw = str_replace(["\n", "\r", "\t"], ' ', $raw);
        $q = trim(str_replace(['№', '#'], '', $raw));

        $groupParentsOnly = (bool) ($validated['group_parents_only'] ?? false);
        if ($groupParentsOnly) {
            $limit = (int) ($validated['limit'] ?? 50);

            $query = MarketSpace::query()
                ->with(['tenant', 'spaceGroupParent.tenant'])
                ->where('market_id', (int) $market->id)
                ->where('is_active', true)
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT);

            if ($q !== '') {
                $isNumeric = ctype_digit($q);
                $qEsc = str_replace(['%', '_'], ['\%', '\\_'], $q);
                $qLike = '%' . $qEsc . '%';

                $query->where(function ($qq) use ($isNumeric, $q, $qLike) {
                    if ($isNumeric) {
                        $qq->orWhere('id', '=', (int) $q);
                    }
                    $qq->orWhere('number', 'like', $qLike)
                        ->orWhere('code', 'like', $qLike)
                        ->orWhere('display_name', 'like', $qLike)
                        ->orWhereHas('tenant', function ($tq) use ($qLike) {
                            $tq->where('name', 'like', $qLike)
                                ->orWhere('display_name', 'like', $qLike);
                        });
                });
            }

            $rows = $query->orderBy('number')->orderBy('id')->limit($limit)->get();

            $items = $rows->map(static function (MarketSpace $space) use ($mapReviewStatusLabel, $buildBindingRiskForSpace, $market, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus): array {
                $tenant = $space->tenant;
                $effectiveOccupancy = $buildSpaceEffectiveOccupancy($space);
                $effectiveFinancial = $buildSpaceEffectiveFinancialStatus($space);

                $tenantName = null;
                if ($tenant) {
                    $tenantName = (string) ($tenant->display_name ?? '');
                    if ($tenantName === '') $tenantName = (string) ($tenant->short_name ?? '');
                    if ($tenantName === '') $tenantName = (string) ($tenant->name ?? '');
                }

                $bindingRisk = $buildBindingRiskForSpace($market, $space);
                $spaceGroupRole = (string) ($space->space_group_role ?? '');

                return [
                    'id' => (int) $space->id,
                    'number' => (string) ($space->number ?? ''),
                    'code' => (string) ($space->code ?? ''),
                    'display_name' => (string) ($space->display_name ?? ''),
                    'area_sqm' => (string) ($space->area_sqm ?? ''),
                    'status' => (string) ($space->status ?? ''),
                    'space_group_role' => $spaceGroupRole,
                    'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                    'is_space_group_parent' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT,
                    'result_type' => 'group',
                    'review_status' => (string) ($space->map_review_status ?? ''),
                    'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
                    'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
                    'tenant' => $tenant ? ['id' => (int) ($tenant->id ?? 0), 'name' => (string) ($tenantName ?? '')] : null,
                    ...$effectiveOccupancy,
                    ...$effectiveFinancial,
                    'binding_risk' => $bindingRisk,
                ];
            })->values();

            return response()->json(['ok' => true, 'items' => $items]);
        }

        // Обычный режим поиска
        $limit = (int) ($validated['limit'] ?? 15);

        if ($q === '') {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $isNumeric = ctype_digit($q);
        $qEsc = str_replace(['%', '_'], ['\%', '\\_'], $q);
        $qLike = '%' . $qEsc . '%';

        $rows = MarketSpace::query()
            ->with([
                'tenant',
                'spaceGroupParent.tenant',
            ])
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->where(function ($qq) use ($isNumeric, $q, $qLike) {
                if ($isNumeric) {
                    $qq->orWhere('id', '=', (int) $q);
                }

                $qq->orWhere('number', 'like', $qLike)
                    ->orWhere('code', 'like', $qLike)
                    ->orWhereHas('tenant', function ($tq) use ($qLike) {
                        $tq->where('name', 'like', $qLike)
                            ->orWhere('display_name', 'like', $qLike);
                    });
            })
            ->orderByRaw('CASE WHEN number = ? THEN 0 ELSE 1 END', [$q])
            ->orderBy('number')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'number', 'code', 'display_name', 'area_sqm', 'status', 'tenant_id', 'space_group_role', 'space_group_parent_id']);

        $items = $rows->map(static function (MarketSpace $space) use ($mapReviewStatusLabel, $buildBindingRiskForSpace, $market, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus): array {
            $tenant = $space->tenant;
            $effectiveOccupancy = $buildSpaceEffectiveOccupancy($space);
            $effectiveFinancial = $buildSpaceEffectiveFinancialStatus($space);

            $tenantName = null;
            if ($tenant) {
                $tenantName = (string) ($tenant->display_name ?? '');
                if ($tenantName === '') {
                    $tenantName = (string) ($tenant->short_name ?? '');
                }
                if ($tenantName === '') {
                    $tenantName = (string) ($tenant->name ?? '');
                }
            }

            $bindingRisk = $buildBindingRiskForSpace($market, $space);

            $spaceGroupRole = (string) ($space->space_group_role ?? '');

            return [
                'id' => (int) $space->id,
                'number' => (string) ($space->number ?? ''),
                'code' => (string) ($space->code ?? ''),
                'display_name' => (string) ($space->display_name ?? ''),
                'area_sqm' => (string) ($space->area_sqm ?? ''),
                'status' => (string) ($space->status ?? ''),
                'space_group_role' => $spaceGroupRole,
                'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                'is_space_group_parent' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT,
                'result_type' => $spaceGroupRole === MarketSpace::SPACE_GROUP_ROLE_PARENT ? 'group' : 'space',
                'review_status' => (string) ($space->map_review_status ?? ''),
                'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
                'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
                'tenant' => $tenant ? [
                    'id' => (int) ($tenant->id ?? 0),
                    'name' => (string) ($tenantName ?? ''),
                ] : null,
                ...$effectiveOccupancy,
                ...$effectiveFinancial,
                'binding_risk' => $bindingRisk,
            ];
        })->values();

        return response()->json(['ok' => true, 'items' => $items]);
    })->name('filament.admin.market-map.spaces');

    /**
     * HIT-test: клик по карте -> поиск места по bbox + polygon.
     */
    Route::get('/admin/market-map/hit', function (Request $request) use ($resolveMarketForMap, $mapReviewStatusLabel, $buildSpaceEffectiveOccupancy, $buildSpaceEffectiveFinancialStatus) {
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $x = (float) $validated['x'];
        $y = (float) $validated['y'];
        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        if (! Schema::hasTable('market_space_map_shapes')) {
            return response()->json([
                'ok' => true,
                'hit' => null,
                'message' => 'Таблица market_space_map_shapes ещё не создана (выполни миграции).',
                'meta' => compact('x', 'y', 'page', 'version'),
            ]);
        }

        foreach (['bbox_x1', 'bbox_y1', 'bbox_x2', 'bbox_y2'] as $bboxCol) {
            if (! Schema::hasColumn('market_space_map_shapes', $bboxCol)) {
                return response()->json([
                    'ok' => false,
                    'hit' => null,
                    'message' => 'В таблице market_space_map_shapes нет колонки ' . $bboxCol . ' (нужны миграции/обновление структуры).',
                    'meta' => compact('x', 'y', 'page', 'version'),
                ], 500);
            }
        }

        $pointInPolygon = static function (float $px, float $py, array $polygon): bool {
            $n = count($polygon);
            if ($n < 3) {
                return false;
            }

            $inside = false;

            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $pi = $polygon[$i] ?? null;
                $pj = $polygon[$j] ?? null;

                if (! is_array($pi) || ! is_array($pj)) {
                    continue;
                }

                $xi = isset($pi['x']) ? (float) $pi['x'] : (isset($pi[0]) ? (float) $pi[0] : null);
                $yi = isset($pi['y']) ? (float) $pi['y'] : (isset($pi[1]) ? (float) $pi[1] : null);

                $xj = isset($pj['x']) ? (float) $pj['x'] : (isset($pj[0]) ? (float) $pj[0] : null);
                $yj = isset($pj['y']) ? (float) $pj['y'] : (isset($pj[1]) ? (float) $pj[1] : null);

                if ($xi === null || $yi === null || $xj === null || $yj === null) {
                    continue;
                }

                $den = ($yj - $yi) ?: 1e-12;

                $intersect = (($yi > $py) !== ($yj > $py))
                    && ($px < ($xj - $xi) * ($py - $yi) / $den + $xi);

                if ($intersect) {
                    $inside = ! $inside;
                }
            }

            return $inside;
        };

        try {
            $candidates = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('page', $page)
                ->where('version', $version)
                ->where('is_active', true)
                ->where('bbox_x1', '<=', $x)
                ->where('bbox_x2', '>=', $x)
                ->where('bbox_y1', '<=', $y)
                ->where('bbox_y2', '>=', $y)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->limit(30)
                ->get(['id', 'market_space_id', 'polygon']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'hit' => null,
                'message' => 'Ошибка чтения слоёв карты: ' . $e->getMessage(),
                'meta' => compact('x', 'y', 'page', 'version'),
            ], 500);
        }

        $hitShape = null;

        foreach ($candidates as $shape) {
            $polygon = is_array($shape->polygon) ? $shape->polygon : [];
            if (count($polygon) < 3) {
                continue;
            }

            if ($pointInPolygon($x, $y, $polygon)) {
                $hitShape = $shape;
                break;
            }
        }

        if (! $hitShape) {
            return response()->json([
                'ok' => true,
                'hit' => null,
                'message' => 'Ничего не найдено по клику.',
                'meta' => compact('x', 'y', 'page', 'version'),
            ]);
        }

        $space = null;

        if (! empty($hitShape->market_space_id)) {
            $space = MarketSpace::query()
                ->with(['tenant', 'location', 'spaceGroupParent.tenant'])
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $hitShape->market_space_id)
                ->first();
        }

        $tenant = $space?->tenant;

        $isTechnicalTenantName = static function (?string $value): bool {
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                return false;
            }

            if (str_contains($value, '@')) {
                return true;
            }

            return preg_match('/^[A-Za-z0-9._-]{6,}$/', $value) === 1;
        };

        $tenantName = null;
        if ($tenant) {
            $shortName = trim((string) ($tenant->short_name ?? ''));
            $displayName = trim((string) ($tenant->display_name ?? ''));
            $name = trim((string) ($tenant->name ?? ''));
            $contactPerson = trim((string) ($tenant->contact_person ?? ''));
            $oneCTenantName = trim((string) data_get($tenant->one_c_data ?? [], 'tenant_name', ''));

            $tenantName = $shortName !== '' ? $shortName : $displayName;
            if ($tenantName === '' && $contactPerson !== '' && ! $isTechnicalTenantName($contactPerson)) {
                $tenantName = $contactPerson;
            }
            if ($tenantName === '' && $oneCTenantName !== '' && ! $isTechnicalTenantName($oneCTenantName)) {
                $tenantName = $oneCTenantName;
            }
            if ($tenantName === '' && $name !== '' && ! $isTechnicalTenantName($name)) {
                $tenantName = $name;
            }
            if ($tenantName === '') {
                $tenantName = $shortName !== '' ? $shortName : ($displayName !== '' ? $displayName : $name);
            }
        }

        $effectiveOccupancy = $buildSpaceEffectiveOccupancy($space);
        $effectiveFinancial = $buildSpaceEffectiveFinancialStatus($space);

        $resolver = app(DebtStatusResolver::class);
        $resolvedDebt = $space && $tenant
            ? $resolver->resolveForMarketSpace($space->id, $tenant->market_id)
            : [
                'mode' => 'auto',
                'status' => 'gray',
                'label' => 'Нет данных',
                'updated_at' => null,
                'source' => null,
                'severity' => 0,
            ];

        $locationName = null;
        $rentRateValue = $space?->rent_rate_value !== null ? (float) $space->rent_rate_value : null;
        $rentRateUnit = filled($space?->rent_rate_unit) ? (string) $space->rent_rate_unit : null;
        $currentAccrualPeriod = null;
        $currentAccrualTotal = null;
        $currentAccrualMode = null;

        if ($space) {
            $locationName = filled($space->location?->name) ? (string) $space->location->name : null;

            if (Schema::hasTable('tenant_accruals')) {
                $periodResolver = app(\App\Services\Operations\MarketPeriodResolver::class);
                $currentPeriod = $periodResolver->resolveMarketPeriod($market, null);
                $currentAccrualPeriod = $currentPeriod->format('Y-m');

                $accrualQuery = DB::table('tenant_accruals')
                    ->where('market_id', (int) $market->id)
                    ->where('market_space_id', (int) $space->id)
                    ->where('period', $currentPeriod->toDateString());

                if ($tenant?->id) {
                    $accrualQuery->where('tenant_id', (int) $tenant->id);
                }

                $accrualRow = $accrualQuery
                    ->selectRaw('SUM(total_with_vat) as total_with_vat, MAX(rent_rate) as rent_rate')
                    ->first();

                if ($rentRateValue === null && $accrualRow?->rent_rate !== null) {
                    $rentRateValue = (float) $accrualRow->rent_rate;
                }

                if ($accrualRow?->total_with_vat !== null) {
                    $currentAccrualTotal = (float) $accrualRow->total_with_vat;
                    $currentAccrualMode = 'current';
                }

                if ($currentAccrualTotal === null) {
                    $latestAccrualQuery = DB::table('tenant_accruals')
                        ->where('market_id', (int) $market->id)
                        ->where('market_space_id', (int) $space->id);

                    if ($tenant?->id) {
                        $latestAccrualQuery->where('tenant_id', (int) $tenant->id);
                    }

                    $latestAccrual = $latestAccrualQuery
                        ->orderByDesc('period')
                        ->orderByDesc('id')
                        ->first(['period', 'total_with_vat', 'rent_rate']);

                    if ($rentRateValue === null && $latestAccrual?->rent_rate !== null) {
                        $rentRateValue = (float) $latestAccrual->rent_rate;
                    }

                    if ($latestAccrual?->total_with_vat !== null) {
                        $currentAccrualTotal = (float) $latestAccrual->total_with_vat;
                        $currentAccrualMode = 'latest';
                        $currentAccrualPeriod = $latestAccrual->period
                            ? \Illuminate\Support\Carbon::parse((string) $latestAccrual->period)->format('Y-m')
                            : $currentAccrualPeriod;
                    }
                }
            }
        }

        $groupParentPayload = null;

        if ($space && (string) ($space->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD && $space->spaceGroupParent) {
            $groupParent = $space->spaceGroupParent;
            $groupParentTenant = $groupParent->tenant;
            $groupParentTenantName = '';

            if ($groupParentTenant) {
                $shortName = trim((string) ($groupParentTenant->short_name ?? ''));
                $displayName = trim((string) ($groupParentTenant->display_name ?? ''));
                $name = trim((string) ($groupParentTenant->name ?? ''));
                $contactPerson = trim((string) ($groupParentTenant->contact_person ?? ''));
                $oneCTenantName = trim((string) data_get($groupParentTenant->one_c_data ?? [], 'tenant_name', ''));

                $groupParentTenantName = $shortName !== '' ? $shortName : $displayName;
                if ($groupParentTenantName === '' && $contactPerson !== '' && ! $isTechnicalTenantName($contactPerson)) {
                    $groupParentTenantName = $contactPerson;
                }
                if ($groupParentTenantName === '' && $oneCTenantName !== '' && ! $isTechnicalTenantName($oneCTenantName)) {
                    $groupParentTenantName = $oneCTenantName;
                }
                if ($groupParentTenantName === '' && $name !== '' && ! $isTechnicalTenantName($name)) {
                    $groupParentTenantName = $name;
                }
                if ($groupParentTenantName === '') {
                    $groupParentTenantName = $shortName !== '' ? $shortName : ($displayName !== '' ? $displayName : $name);
                }
            }

            $groupParentPayload = [
                'id' => (int) $groupParent->id,
                'number' => (string) ($groupParent->number ?? ''),
                'display_name' => (string) ($groupParent->display_name ?? ''),
                'tenant_id' => $groupParent->tenant_id ? (int) $groupParent->tenant_id : null,
                'tenant_name' => $groupParentTenantName !== '' ? $groupParentTenantName : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'hit' => [
                'shape_id' => (int) $hitShape->id,
                'market_space_id' => $space?->id ? (int) $space->id : null,

                'space' => $space ? [
                    'id' => (int) $space->id,
                    'tenant_id' => $space->tenant_id ? (int) $space->tenant_id : null,
                    'number' => (string) ($space->number ?? ''),
                    'code' => (string) ($space->code ?? ''),
                    'display_name' => (string) ($space->display_name ?? ''),
                    'activity_type' => (string) ($space->activity_type ?? ''),
                    'area_sqm' => (string) ($space->area_sqm ?? ''),
                    'status' => (string) ($space->status ?? ''),
                    'review_status' => (string) ($space->map_review_status ?? ''),
                    'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
                    'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
                    'space_group_role' => (string) ($space->space_group_role ?? ''),
                    'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                    'group_parent' => $groupParentPayload,
                    'location_name' => $locationName,
                    'rent_rate_value' => $rentRateValue,
                    'rent_rate_unit' => $rentRateUnit,
                    'current_accrual_period' => $currentAccrualPeriod,
                    'current_accrual_total' => $currentAccrualTotal,
                    'current_accrual_mode' => $currentAccrualMode,
                ] : null,
                ...$effectiveOccupancy,

                'tenant' => $tenant ? [
                    'id' => (int) ($tenant->id ?? 0),
                    'name' => (string) ($tenantName ?? ''),
                    'debt_status' => $resolvedDebt['status'],
                    'debt_status_label' => $resolvedDebt['label'],
                    'debt_status_mode' => $resolvedDebt['mode'],
                ] : null,
                'tenant_id' => $tenant?->id ? (int) $tenant->id : null,
                'space_tenant_id' => $space?->tenant_id ? (int) $space->tenant_id : null,

                'debt_status' => $resolvedDebt['status'],
                'debt_status_label' => $resolvedDebt['label'],
                'debt_status_mode' => $resolvedDebt['mode'],
                'debt_status_updated_at' => $resolvedDebt['updated_at'],
                'debt_status_source' => $resolvedDebt['source'] ?? null,
                'debt_overdue_days' => $resolvedDebt['extra']['overdue_days'] ?? null,
                'debt_status_scope' => $resolvedDebt['extra']['scope'] ?? 'none',
                ...$effectiveFinancial,

                'debt' => null,
                'color' => null,
            ],
            'meta' => compact('x', 'y', 'page', 'version'),
        ]);
    })->name('filament.admin.market-map.hit');

    /**
     * Изменение группировки места (добавить/перенести/убрать из группы).
     */
    Route::post('/admin/market-map/spaces/{marketSpace}/group-membership', function (Request $request, MarketSpace $marketSpace) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        abort_unless((int) $marketSpace->market_id === (int) $market->id, 403, 'Место не принадлежит этому рынку.');

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:add_to_group,move_to_group,remove_from_group'],
            'target_parent_id' => ['nullable', 'integer', 'exists:market_spaces,id'],
            'target_slot' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $action = $validated['action'];
        $targetSlot = $validated['target_slot'] ?? null;
        $comment = $validated['comment'] ?? null;

        $spaceManager = app(\App\Services\MarketSpaces\SpaceGroupManager::class);

        // Сохраняем старые значения до изменения
        $oldRole = (string) ($marketSpace->space_group_role ?: MarketSpace::SPACE_GROUP_ROLE_NONE);
        $oldParentId = $marketSpace->space_group_parent_id ? (int) $marketSpace->space_group_parent_id : null;
        $oldSlot = $marketSpace->space_group_slot;

        if ($action === 'add_to_group') {
            $targetParentId = $validated['target_parent_id'] ?? null;

            if (!$targetParentId) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Укажите target_parent_id.',
                    'errors' => ['target_parent_id' => ['Укажите целевую группу.']],
                ], 422);
            }

            $targetParent = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $targetParentId)
                ->first();

            if (!$targetParent) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Целевая группа не найдена.',
                    'errors' => ['target_parent_id' => ['Целевая группа не найдена в этом рынке.']],
                ], 422);
            }

            if ($targetSlot === null || $targetSlot === '') {
                throw ValidationException::withMessages([
                    'target_slot' => 'Укажите номер внутри группы.',
                ]);
            }

            try {
                $result = $spaceManager->addToGroup($marketSpace->fresh(), $targetParent, $targetSlot);
            } catch (ValidationException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        } elseif ($action === 'move_to_group') {
            $targetParentId = $validated['target_parent_id'] ?? null;

            if (!$targetParentId) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Укажите target_parent_id.',
                    'errors' => ['target_parent_id' => ['Укажите целевую группу.']],
                ], 422);
            }

            $targetParent = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $targetParentId)
                ->first();

            if (!$targetParent) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Целевая группа не найдена.',
                    'errors' => ['target_parent_id' => ['Целевая группа не найдена в этом рынке.']],
                ], 422);
            }

            $slot = $targetSlot !== null && $targetSlot !== ''
                ? $targetSlot
                : $marketSpace->space_group_slot;

            if ($slot === null || $slot === '') {
                throw ValidationException::withMessages([
                    'target_slot' => 'Укажите номер внутри группы.',
                ]);
            }

            try {
                $result = $spaceManager->regroupChild($marketSpace->fresh(), $targetParent, $slot);
            } catch (ValidationException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        } elseif ($action === 'remove_from_group') {
            try {
                $result = $spaceManager->removeFromGroup($marketSpace->fresh());
            } catch (ValidationException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        } else {
            return response()->json([
                'ok' => false,
                'message' => 'Неизвестное действие.',
                'errors' => ['action' => ['Недопустимое значение.']],
            ], 422);
        }

        $space = MarketSpace::query()
            ->where('market_id', (int) $market->id)
            ->whereKey((int) $result['child_id'])
            ->first();

        // Запись в журнал операций (Operation)
        $userId = Filament::auth()->id();
        $now = now();

        $auditPayload = [
            'action' => $action,
            'market_space_id' => (int) $result['child_id'],
            'old_space_group_role' => $oldRole,
            'old_space_group_parent_id' => $oldParentId,
            'old_space_group_slot' => $oldSlot,
            'new_space_group_role' => (string) ($space?->space_group_role ?? ''),
            'new_space_group_parent_id' => $result['new_parent_id'],
            'new_space_group_slot' => $space?->space_group_slot ?? null,
            'target_parent_id' => $validated['target_parent_id'] ?? null,
            'target_slot' => $targetSlot,
            'source' => 'market_map_group_membership',
        ];

        if ($comment !== null && $comment !== '') {
            $auditPayload['user_comment'] = $comment;
        }

        Operation::query()->create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $result['child_id'],
            'type' => OperationType::GROUP_MEMBERSHIP,
            'effective_at' => $now,
            'status' => 'completed',
            'payload' => $auditPayload,
            'comment' => $auditPayload['user_comment'] ?? null,
            'created_by' => $userId,
        ]);

        return response()->json([
            'ok' => true,
            'action' => $action,
            'market_space_id' => (int) $result['child_id'],
            'old_parent_id' => $result['old_parent_id'],
            'new_parent_id' => $result['new_parent_id'],
            'space_group_role' => (string) ($space?->space_group_role ?? ''),
            'space_group_slot' => $space?->space_group_slot ?? null,
            'renamed_parents' => $result['renamed_parents'] ?? [],
            'comment' => $comment,
        ]);
    })->name('filament.admin.market-map.spaces.group-membership');

    /**
     * Экспорт реестра по операциям и начислениям (CSV).
     */
    Route::get('/admin/operations/export', function (Request $request) use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $periodInput = $request->query('period');
        $resolver = app(\App\Services\Operations\MarketPeriodResolver::class);
        $period = $resolver->resolveMarketPeriod($market, is_string($periodInput) ? $periodInput : null);
        $periodDate = $period->toDateString();

        $rows = DB::table('tenant_accruals as ta')
            ->leftJoin('market_spaces as ms', 'ms.id', '=', 'ta.market_space_id')
            ->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('ta.market_id', (int) $market->id)
            ->where('ta.period', $periodDate)
            ->select([
                'ta.market_space_id',
                'ta.tenant_id',
                'ta.days',
                'ta.area_sqm as accrual_area',
                'ta.rent_rate',
                'ta.rent_amount',
                'ta.management_fee',
                'ta.utilities_amount',
                'ta.electricity_amount',
                'ta.total_with_vat',
                'ta.source_place_code',
                'ta.source_place_name',
                'ms.code as space_code',
                'ms.number as space_number',
                'ms.display_name as space_display_name',
                'ms.area_sqm as space_area',
                't.short_name as tenant_short_name',
                't.name as tenant_name',
            ])
            ->orderBy('ta.market_space_id')
            ->get();

        $stateService = app(\App\Services\Operations\OperationsStateService::class);
        $electricityTotals = $stateService->getElectricityTotalsForPeriod((int) $market->id, $period);
        $adjustmentTotals = $stateService->getAdjustmentTotalsForPeriod((int) $market->id, $period);

        $grouped = [];

        foreach ($rows as $row) {
            $spaceId = (int) ($row->market_space_id ?? 0);
            $tenantId = (int) ($row->tenant_id ?? 0);
            $key = $spaceId . ':' . $tenantId;

            if (! isset($grouped[$key])) {
                $spaceLabel = trim((string) ($row->space_display_name ?? ''));
                if ($spaceLabel === '') {
                    $spaceLabel = trim((string) ($row->space_number ?? ''));
                }
                if ($spaceLabel === '') {
                    $spaceLabel = trim((string) ($row->space_code ?? ''));
                }

                $tenantLabel = trim((string) ($row->tenant_short_name ?? ''));
                if ($tenantLabel === '') {
                    $tenantLabel = trim((string) ($row->tenant_name ?? ''));
                }

                $grouped[$key] = [
                    'space_id' => $spaceId,
                    'tenant_id' => $tenantId,
                    'space_label' => $spaceLabel !== '' ? $spaceLabel : (string) ($row->source_place_code ?? ''),
                    'tenant_label' => $tenantLabel !== '' ? $tenantLabel : '—',
                    'days' => 0,
                    'area_sqm' => 0.0,
                    'rent_rate_fact' => null,
                    'rent_amount' => 0.0,
                    'management_fee' => 0.0,
                    'utilities_amount' => 0.0,
                    'electricity_amount' => 0.0,
                    'adjustments' => 0.0,
                    'total' => 0.0,
                ];
            }

            $grouped[$key]['days'] += (int) ($row->days ?? 0);
            $grouped[$key]['area_sqm'] += (float) ($row->accrual_area ?? $row->space_area ?? 0);
            $grouped[$key]['rent_amount'] += (float) ($row->rent_amount ?? 0);
            $grouped[$key]['management_fee'] += (float) ($row->management_fee ?? 0);
            $grouped[$key]['utilities_amount'] += (float) ($row->utilities_amount ?? 0);
            $grouped[$key]['electricity_amount'] += (float) ($row->electricity_amount ?? 0);
            $grouped[$key]['total'] += (float) ($row->total_with_vat ?? 0);

            if ($grouped[$key]['rent_rate_fact'] === null && $row->rent_rate !== null) {
                $grouped[$key]['rent_rate_fact'] = (float) $row->rent_rate;
            }
        }

        foreach ($grouped as $key => $data) {
            $spaceId = (int) $data['space_id'];

            if ($spaceId > 0) {
                $state = $stateService->getSpaceStateForPeriod((int) $market->id, $period, $spaceId);
                if ($state['rent_rate'] !== null) {
                    $grouped[$key]['rent_rate_fact'] = $state['rent_rate'];
                }

                $grouped[$key]['electricity_amount'] += (float) ($electricityTotals[$spaceId] ?? 0);
                $grouped[$key]['adjustments'] += (float) ($adjustmentTotals[$spaceId] ?? 0);
            }
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="registry-' . $periodDate . '.csv"',
        ];

        $callback = static function () use ($grouped): void {
            $out = fopen('php://output', 'w');
            if (! $out) {
                return;
            }

            fputs($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Место',
                'Арендатор',
                'Дней',
                'Площадь',
                'Ставка (факт)',
                'Аренда',
                'Управление',
                'Коммунальные',
                'Электроэнергия',
                'Корректировки',
                'Итого',
            ], ';');

            foreach ($grouped as $row) {
                fputcsv($out, [
                    $row['space_label'],
                    $row['tenant_label'],
                    $row['days'],
                    number_format((float) $row['area_sqm'], 2, ',', ' '),
                    $row['rent_rate_fact'] !== null ? number_format((float) $row['rent_rate_fact'], 2, ',', ' ') : '',
                    number_format((float) $row['rent_amount'], 2, ',', ' '),
                    number_format((float) $row['management_fee'], 2, ',', ' '),
                    number_format((float) $row['utilities_amount'], 2, ',', ' '),
                    number_format((float) $row['electricity_amount'], 2, ',', ' '),
                    number_format((float) $row['adjustments'], 2, ',', ' '),
                    number_format((float) $row['total'], 2, ',', ' '),
                ], ';');
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    })->name('filament.admin.operations.export');

    Route::post('/admin/market-map/review-contract-tenant-switch', function (Request $request) use (
    $resolveMarketForMap,
    $ensureCanEditShapes,
    $hasMapReviewColumns,
    $buildMapReviewProgress,
    $mapReviewStatusLabel
) {
    $ensureCanEditShapes();
    $market = $resolveMarketForMap();

    if (! $hasMapReviewColumns()) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review columns are missing on market_spaces.',
        ], 422);
    }

    $validated = $request->validate([
        'market_space_id' => ['required', 'integer', 'min:1'],
        'target_tenant_id' => ['required', 'integer', 'min:1'],
        'contract_id' => ['nullable', 'integer', 'min:1'],
        'effective_date' => ['required', 'date_format:Y-m-d'],
        'reason' => ['nullable', 'string', 'max:2000'],
        'close_previous_contract' => ['nullable', 'boolean'],
    ]);

    $space = MarketSpace::query()
        ->where('market_id', (int) $market->id)
        ->whereKey((int) $validated['market_space_id'])
        ->first();

    if (! $space) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review space was not found in the current market.',
        ], 404);
    }

    $result = app(SpaceReviewActionService::class)->reviewContractTenantSwitch(
        $market,
        $space,
        $validated,
        Filament::auth()->id(),
    );

    if (($result['ok'] ?? false) !== true) {
        return response()->json([
            'ok' => false,
            'message' => (string) ($result['message'] ?? 'Review action failed.'),
            'errors' => $result['errors'] ?? null,
        ], (int) ($result['status_code'] ?? 422));
    }

    $space->refresh();

    return response()->json([
        'ok' => true,
        'mode' => (string) ($result['mode'] ?? 'tenant_switch'),
        'operation' => $result['operation'] ?? null,
        'item' => [
            'market_space_id' => (int) $space->id,
            'review_status' => (string) ($space->map_review_status ?? ''),
            'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
            'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
        ],
        'progress' => $buildMapReviewProgress($market),
    ]);
})->name('filament.admin.market-map.review-contract-tenant-switch');

    Route::post('/admin/market-map/review-tenant-switch', function (Request $request) use (
    $resolveMarketForMap,
    $ensureCanEditShapes,
    $hasMapReviewColumns,
    $buildMapReviewProgress,
    $mapReviewStatusLabel
) {
    $ensureCanEditShapes();
    $market = $resolveMarketForMap();

    if (! $hasMapReviewColumns()) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review columns are missing on market_spaces.',
        ], 422);
    }

    $validated = $request->validate([
        'market_space_id' => ['required', 'integer', 'min:1'],
        'target_tenant_id' => ['required', 'integer', 'min:1'],
        'effective_date' => ['required', 'date_format:Y-m-d'],
        'reason' => ['nullable', 'string', 'max:2000'],
        'close_previous_contract' => ['nullable', 'boolean'],
    ]);

    $space = MarketSpace::query()
        ->where('market_id', (int) $market->id)
        ->whereKey((int) $validated['market_space_id'])
        ->first();

    if (! $space) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review space was not found in the current market.',
        ], 404);
    }

    $result = app(SpaceReviewActionService::class)->reviewTenantSwitch(
        $market,
        $space,
        $validated,
        Filament::auth()->id(),
    );

    if (($result['ok'] ?? false) !== true) {
        return response()->json([
            'ok' => false,
            'message' => (string) ($result['message'] ?? 'Review action failed.'),
            'errors' => $result['errors'] ?? null,
        ], (int) ($result['status_code'] ?? 422));
    }

    $space->refresh();

    return response()->json([
        'ok' => true,
        'mode' => (string) ($result['mode'] ?? 'tenant_switch_manual'),
        'operation' => $result['operation'] ?? null,
        'item' => [
            'market_space_id' => (int) $space->id,
            'review_status' => (string) ($space->map_review_status ?? ''),
            'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
            'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
        ],
        'progress' => $buildMapReviewProgress($market),
    ]);
})->name('filament.admin.market-map.review-tenant-switch');

    Route::post('/admin/market-map/review-resolve-financial-tenant', function (Request $request) use (
        $resolveMarketForMap,
        $ensureCanEditShapes,
        $buildMapReviewProgress
    ) {
        $ensureCanEditShapes();
        $market = $resolveMarketForMap();

        $validated = $request->validate([
            'market_space_id' => ['required', 'integer', 'min:1'],
            'accrual_id' => ['required', 'integer', 'min:1'],
            'preferred_tenant_id' => ['nullable', 'integer', 'min:1'],
            'tenant_external_id' => ['nullable', 'string', 'max:255'],
            'tenant_name' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:32'],
            'kpp' => ['nullable', 'string', 'max:32'],
        ]);

        $space = MarketSpace::query()
            ->where('market_id', (int) $market->id)
            ->whereKey((int) $validated['market_space_id'])
            ->first();

        if (! $space) {
            return response()->json([
                'ok' => false,
                'message' => 'Map review space was not found in the current market.',
            ], 404);
        }

        $accrual = \App\Models\TenantAccrual::query()
            ->where('market_id', (int) $market->id)
            ->whereKey((int) $validated['accrual_id'])
            ->where('market_space_id', (int) $space->id)
            ->first();

        if (! $accrual) {
            return response()->json([
                'ok' => false,
                'message' => 'Financial signal accrual was not found in the current market.',
            ], 404);
        }

        $payload = [];
        if (is_array($accrual->payload)) {
            $payload = $accrual->payload;
        } elseif (is_string($accrual->payload) && $accrual->payload !== '') {
            $decoded = json_decode($accrual->payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $existingAccrualTenantId = (int) ($accrual->tenant_id ?? 0);
        $preferredTenantId = (int) ($validated['preferred_tenant_id'] ?? 0);

        if ($preferredTenantId > 0 && $preferredTenantId !== $existingAccrualTenantId) {
            $resolution = app(\App\Services\Tenants\OneCTenantResolver::class)->resolve(
                (int) $market->id,
                trim((string) ($validated['tenant_external_id'] ?? '')) ?: trim((string) ($payload['tenant_external_id'] ?? '')),
                [
                    'tenant_name' => trim((string) ($validated['tenant_name'] ?? '')) ?: trim((string) ($payload['tenant_name'] ?? '')),
                    'inn' => trim((string) ($validated['inn'] ?? '')) ?: trim((string) ($payload['inn'] ?? '')),
                    'kpp' => trim((string) ($validated['kpp'] ?? '')) ?: trim((string) ($payload['kpp'] ?? '')),
                ],
                'map_review_financial_signal',
                now(),
                [
                    'activate_resolved_tenant' => true,
                    'preferred_tenant_id' => $preferredTenantId,
                ],
            );

            /** @var \App\Models\Tenant|null $preferredTenant */
            $preferredTenant = $resolution['tenant'] ?? null;

            if (! $preferredTenant) {
                return response()->json([
                    'ok' => false,
                    'mode' => 'tenant_resolve_failed',
                    'message' => 'Не удалось сопоставить финансовый сигнал с выбранным арендатором.',
                    'progress' => $buildMapReviewProgress($market),
                ], 422);
            }

            $updatedAccruals = 0;

            if ($existingAccrualTenantId > 0 && $existingAccrualTenantId !== (int) $preferredTenant->id) {
                $updatedAccruals = \App\Models\TenantAccrual::query()
                    ->where('market_id', (int) $market->id)
                    ->where('market_space_id', (int) $space->id)
                    ->where('tenant_id', $existingAccrualTenantId)
                    ->update([
                        'tenant_id' => (int) $preferredTenant->id,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'ok' => true,
                'mode' => 'tenant_resolved_existing',
                'tenant' => [
                    'id' => (int) $preferredTenant->id,
                    'name' => (string) $preferredTenant->name,
                ],
                'accruals_updated' => $updatedAccruals,
                'progress' => $buildMapReviewProgress($market),
            ]);
        }

        if ($existingAccrualTenantId > 0 && \Illuminate\Support\Facades\Schema::hasColumn('tenants', 'is_active')) {
            $existingAccrualTenant = \App\Models\Tenant::query()
                ->where('market_id', (int) $market->id)
                ->whereKey($existingAccrualTenantId)
                ->first();

            if ($existingAccrualTenant && ! (bool) $existingAccrualTenant->is_active) {
                $existingAccrualTenant->is_active = true;
                $existingAccrualTenant->save();

                return response()->json([
                    'ok' => true,
                    'mode' => 'tenant_activated_existing',
                    'tenant' => [
                        'id' => (int) $existingAccrualTenant->id,
                        'name' => (string) $existingAccrualTenant->name,
                    ],
                    'accruals_updated' => 0,
                    'progress' => $buildMapReviewProgress($market),
                ]);
            }
        }

        $resolverPayload = [
            'tenant_name' => trim((string) ($validated['tenant_name'] ?? '')) ?: trim((string) ($payload['tenant_name'] ?? '')),
            'inn' => trim((string) ($validated['inn'] ?? '')) ?: trim((string) ($payload['inn'] ?? '')),
            'kpp' => trim((string) ($validated['kpp'] ?? '')) ?: trim((string) ($payload['kpp'] ?? '')),
        ];
        $tenantExternalId = trim((string) ($validated['tenant_external_id'] ?? '')) ?: trim((string) ($payload['tenant_external_id'] ?? ''));

        $resolution = app(\App\Services\Tenants\OneCTenantResolver::class)->resolve(
            (int) $market->id,
            $tenantExternalId,
            $resolverPayload,
            'map_review_financial_signal',
            now(),
            ['activate_resolved_tenant' => true],
        );

        /** @var \App\Models\Tenant|null $resolvedTenant */
        $resolvedTenant = $resolution['tenant'] ?? null;

        if (! $resolvedTenant) {
            return response()->json([
                'ok' => false,
                'mode' => 'tenant_resolve_failed',
                'message' => 'Не удалось безопасно создать или сопоставить арендатора. Нужен external_id из 1С или существующий ИНН.',
                'progress' => $buildMapReviewProgress($market),
            ], 422);
        }

        $updatedAccruals = 0;
        $originalTenantId = (int) ($accrual->tenant_id ?? 0);

        if ($originalTenantId > 0 && $originalTenantId !== (int) $resolvedTenant->id) {
            $updatedAccruals = \App\Models\TenantAccrual::query()
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', (int) $space->id)
                ->where('tenant_id', $originalTenantId)
                ->update([
                    'tenant_id' => (int) $resolvedTenant->id,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => ($resolution['mode'] ?? 'failed') === 'created' ? 'tenant_created' : 'tenant_resolved_existing',
            'tenant' => [
                'id' => (int) $resolvedTenant->id,
                'name' => (string) $resolvedTenant->name,
            ],
            'accruals_updated' => $updatedAccruals,
            'progress' => $buildMapReviewProgress($market),
        ]);
    })->name('filament.admin.market-map.review-resolve-financial-tenant');

    Route::post('/admin/market-map/review-decision', function (Request $request) use (
    $resolveMarketForMap,
    $ensureCanEditShapes,
    $hasMapReviewColumns,
    $buildMapReviewProgress,
    $mapReviewStatusLabel,
    $marketSpaceHasUsableShape
) {
    $ensureCanEditShapes();
    $market = $resolveMarketForMap();

    if (! $hasMapReviewColumns()) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review columns are missing on market_spaces.',
        ], 422);
    }

    $validated = $request->validate([
        'decision' => ['required', 'string', 'max:64'],
        'market_space_id' => ['required', 'integer', 'min:1'],
        'shape_id' => ['nullable', 'integer', 'min:1'],
        'reason' => ['nullable', 'string', 'max:2000'],
        'observed_tenant_name' => ['nullable', 'string', 'max:255'],
        'number' => ['nullable', 'string', 'max:255'],
        'display_name' => ['nullable', 'string', 'max:255'],
        'candidate_market_space_id' => ['nullable', 'integer', 'min:1'],
        'effective_date' => ['nullable', 'date_format:Y-m-d'],
    ]);

    $space = MarketSpace::query()
        ->where('market_id', (int) $market->id)
        ->whereKey((int) $validated['market_space_id'])
        ->first();

    if (! $space) {
        return response()->json([
            'ok' => false,
            'message' => 'Map review space was not found in the current market.',
        ], 404);
    }

    $result = app(SpaceReviewActionService::class)->reviewDecision(
        $market,
        $space,
        $validated,
        Filament::auth()->id(),
        $marketSpaceHasUsableShape,
    );

    if (($result['ok'] ?? false) !== true) {
        return response()->json([
            'ok' => false,
            'message' => (string) ($result['message'] ?? 'Review action failed.'),
            'errors' => $result['errors'] ?? null,
        ], (int) ($result['status_code'] ?? 422));
    }

    $space->refresh();

    return response()->json([
        'ok' => true,
        'mode' => (string) ($result['mode'] ?? 'operation'),
        'operation' => $result['operation'] ?? null,
        'message' => $result['message'] ?? null,
        'item' => [
            'market_space_id' => (int) $space->id,
            'review_status' => (string) ($space->map_review_status ?? ''),
            'review_status_label' => $mapReviewStatusLabel($space->map_review_status),
            'reviewed_at' => optional($space->map_reviewed_at)?->toIso8601String(),
        ],
        'resolution' => $result['resolution'] ?? null,
        'progress' => $buildMapReviewProgress($market),
    ]);
})->name('filament.admin.market-map.review-decision');

    Route::get('/admin/map-review-results/ai-review', function (Request $request) use (
        $resolveMarketForMap,
        $canEditShapes
    ) {
        abort_unless($canEditShapes(), 403);

        $market = $resolveMarketForMap();
        $validated = $request->validate([
            'space_id' => ['required', 'integer', 'min:1'],
        ]);

        $spaceId = (int) $validated['space_id'];
        $spaceExists = MarketSpace::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($spaceId)
            ->exists();

        if (! $spaceExists) {
            return response()->json([
                'ok' => false,
                'message' => 'Map review space was not found in the current market.',
            ], 404);
        }

        $reviewService = app(AiReviewService::class);

        if (! $reviewService->isAvailable()) {
            return response()->json([
                'ok' => true,
                'review' => null,
                'error_type' => 'disabled',
            ]);
        }

        try {
            $fetchResult = $reviewService->getReviewForSpace($spaceId, (int) $market->id);

            return response()->json([
                'ok' => true,
                'review' => $fetchResult['review'] ?? null,
                'error_type' => $fetchResult['error_type'] ?? null,
            ]);
        } catch (\Throwable $e) {
            logger()->warning('AI review on-demand endpoint fallback', [
                'space_id' => $spaceId,
                'market_id' => (int) $market->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => true,
                'review' => null,
                'error_type' => 'connectivity',
            ]);
        }
    })->name('filament.admin.map-review-results.ai-review');

    Route::post('/admin/map-review-results/ai-review/regenerate', function (Request $request) use (
        $resolveMarketForMap,
        $canEditShapes
    ) {
        abort_unless($canEditShapes(), 403);

        $market = $resolveMarketForMap();
        $validated = $request->validate([
            'space_id' => ['required', 'integer', 'min:1'],
        ]);

        $spaceId = (int) $validated['space_id'];
        $spaceExists = MarketSpace::query()
            ->where('market_id', (int) $market->id)
            ->whereKey($spaceId)
            ->exists();

        if (! $spaceExists) {
            return response()->json([
                'ok' => false,
                'message' => 'Map review space was not found in the current market.',
            ], 404);
        }

        $reviewService = app(AiReviewService::class);
        $reviewService->clearCache($spaceId, (int) $market->id);

        if (! $reviewService->isAvailable()) {
            return response()->json([
                'ok' => true,
                'review' => null,
                'error_type' => 'disabled',
            ]);
        }

        try {
            $fetchResult = $reviewService->getReviewForSpace($spaceId, (int) $market->id);

            return response()->json([
                'ok' => true,
                'review' => $fetchResult['review'] ?? null,
                'error_type' => $fetchResult['error_type'] ?? null,
            ]);
        } catch (\Throwable $e) {
            logger()->warning('AI review regenerate endpoint fallback', [
                'space_id' => $spaceId,
                'market_id' => (int) $market->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => true,
                'review' => null,
                'error_type' => 'connectivity',
            ]);
        }
    })->name('filament.admin.map-review-results.ai-review.regenerate');

    /**
     * Viewer карты рынка (рендер через Blade).
     */
    Route::get('/admin/market-map', function (Request $request) use (
        $resolveMarketForMap,
        $canEditShapes,
        $buildMapReviewProgress
    ) {
        $market = $resolveMarketForMap();

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');

        $hasMap = is_string($mapPath)
            && $mapPath !== ''
            && str_starts_with($mapPath, 'market-maps/')
            && Storage::disk('local')->exists($mapPath);

        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:map,review'],
            'market_space_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'version' => ['nullable', 'integer', 'min:1'],
            'bbox_x1' => ['nullable', 'numeric'],
            'bbox_y1' => ['nullable', 'numeric'],
            'bbox_x2' => ['nullable', 'numeric'],
            'bbox_y2' => ['nullable', 'numeric'],
        ]);

        $marketSpaceId = isset($validated['market_space_id']) ? (int) $validated['market_space_id'] : null;
        $mode = (string) ($validated['mode'] ?? 'map');
        $page = (int) ($validated['page'] ?? 1);
        $version = (int) ($validated['version'] ?? 1);

        $pageRequested = $request->has('page');
        $versionRequested = $request->has('version');
        $bboxRequested = $request->has('bbox_x1')
            && $request->has('bbox_y1')
            && $request->has('bbox_x2')
            && $request->has('bbox_y2');
        $rawReturnUrl = (string) $request->query('return_url', '');
        $returnUrl = null;

        if ($rawReturnUrl !== '') {
            $appBaseUrl = rtrim(url('/'), '/');

            if (
                (str_starts_with($rawReturnUrl, '/') && ! str_starts_with($rawReturnUrl, '//'))
                || str_starts_with($rawReturnUrl, $appBaseUrl)
            ) {
                $returnUrl = $rawReturnUrl;
            }
        }

        $bboxFromRequest = null;

        if ($bboxRequested) {
            $bboxFromRequest = [
                'x1' => (float) ($validated['bbox_x1'] ?? 0),
                'y1' => (float) ($validated['bbox_y1'] ?? 0),
                'x2' => (float) ($validated['bbox_x2'] ?? 0),
                'y2' => (float) ($validated['bbox_y2'] ?? 0),
            ];
        }

        $returnUrl = url('/admin');
        $rawReturnUrl = $request->query('return_url');
        if (is_string($rawReturnUrl)) {
            $rawReturnUrl = trim($rawReturnUrl);
            $baseUrl = rtrim(url('/'), '/');

            if ($rawReturnUrl !== '') {
                if (str_starts_with($rawReturnUrl, '/')) {
                    $returnUrl = $rawReturnUrl;
                } elseif ($rawReturnUrl === $baseUrl || str_starts_with($rawReturnUrl, $baseUrl . '/')) {
                    $returnUrl = $rawReturnUrl;
                }
            }
        }

        $focusShape = null;
        $marketSpaceNotLinked = false;

        if ($marketSpaceId && Schema::hasTable('market_space_map_shapes')) {
            $shapeQuery = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', $marketSpaceId)
                ->where('is_active', true);

            if ($pageRequested) {
                $shapeQuery->where('page', $page);
            }

            if ($versionRequested) {
                $shapeQuery->where('version', $version);
            }

            $shape = $shapeQuery
                ->orderByDesc('id')
                ->first([
                    'id',
                    'market_space_id',
                    'page',
                    'version',
                    'bbox_x1',
                    'bbox_y1',
                    'bbox_x2',
                    'bbox_y2',
                ]);

            if (! $shape && (! $pageRequested || ! $versionRequested)) {
                $shape = MarketSpaceMapShape::query()
                    ->where('market_id', (int) $market->id)
                    ->where('market_space_id', $marketSpaceId)
                    ->where('is_active', true)
                    ->orderByDesc('id')
                    ->first([
                        'id',
                        'market_space_id',
                        'page',
                        'version',
                        'bbox_x1',
                        'bbox_y1',
                        'bbox_x2',
                        'bbox_y2',
                    ]);
            }

            if ($shape) {
                if (! $pageRequested) {
                    $page = (int) ($shape->page ?? 1);
                }

                if (! $versionRequested) {
                    $version = (int) ($shape->version ?? 1);
                }

                $focusShape = [
                    'id' => (int) $shape->id,
                    'market_space_id' => $shape->market_space_id ? (int) $shape->market_space_id : null,
                    'page' => (int) ($shape->page ?? 1),
                    'version' => (int) ($shape->version ?? 1),
                    'bbox' => [
                        'x1' => $bboxFromRequest['x1'] ?? ($shape->bbox_x1 !== null ? (float) $shape->bbox_x1 : null),
                        'y1' => $bboxFromRequest['y1'] ?? ($shape->bbox_y1 !== null ? (float) $shape->bbox_y1 : null),
                        'x2' => $bboxFromRequest['x2'] ?? ($shape->bbox_x2 !== null ? (float) $shape->bbox_x2 : null),
                        'y2' => $bboxFromRequest['y2'] ?? ($shape->bbox_y2 !== null ? (float) $shape->bbox_y2 : null),
                    ],
                ];
            } else {
                $focusedSpace = MarketSpace::query()
                    ->where('market_id', (int) $market->id)
                    ->whereKey($marketSpaceId)
                    ->first(['id', 'market_id', 'space_group_role']);

                $groupFocusShape = null;

                if ($focusedSpace && (string) ($focusedSpace->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
                    $groupShapeQuery = MarketSpaceMapShape::query()
                        ->join('market_spaces', 'market_spaces.id', '=', 'market_space_map_shapes.market_space_id')
                        ->where('market_space_map_shapes.market_id', (int) $market->id)
                        ->where('market_spaces.market_id', (int) $market->id)
                        ->where('market_spaces.space_group_parent_id', $marketSpaceId)
                        ->where('market_spaces.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                        ->where('market_space_map_shapes.is_active', true);

                    if ($pageRequested) {
                        $groupShapeQuery->where('market_space_map_shapes.page', $page);
                    }

                    if ($versionRequested) {
                        $groupShapeQuery->where('market_space_map_shapes.version', $version);
                    }

                    $groupFocusShape = $groupShapeQuery
                        ->orderByDesc('market_space_map_shapes.id')
                        ->first([
                            'market_space_map_shapes.id',
                            'market_space_map_shapes.market_space_id',
                            'market_space_map_shapes.page',
                            'market_space_map_shapes.version',
                            'market_space_map_shapes.bbox_x1',
                            'market_space_map_shapes.bbox_y1',
                            'market_space_map_shapes.bbox_x2',
                            'market_space_map_shapes.bbox_y2',
                        ]);

                    if (! $groupFocusShape && (! $pageRequested || ! $versionRequested)) {
                        $groupFocusShape = MarketSpaceMapShape::query()
                            ->join('market_spaces', 'market_spaces.id', '=', 'market_space_map_shapes.market_space_id')
                            ->where('market_space_map_shapes.market_id', (int) $market->id)
                            ->where('market_spaces.market_id', (int) $market->id)
                            ->where('market_spaces.space_group_parent_id', $marketSpaceId)
                            ->where('market_spaces.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                            ->where('market_space_map_shapes.is_active', true)
                            ->orderByDesc('market_space_map_shapes.id')
                            ->first([
                                'market_space_map_shapes.id',
                                'market_space_map_shapes.market_space_id',
                                'market_space_map_shapes.page',
                                'market_space_map_shapes.version',
                                'market_space_map_shapes.bbox_x1',
                                'market_space_map_shapes.bbox_y1',
                                'market_space_map_shapes.bbox_x2',
                                'market_space_map_shapes.bbox_y2',
                            ]);
                    }
                }

                if ($groupFocusShape) {
                    if (! $pageRequested) {
                        $page = (int) ($groupFocusShape->page ?? 1);
                    }

                    if (! $versionRequested) {
                        $version = (int) ($groupFocusShape->version ?? 1);
                    }

                    $groupBbox = MarketSpaceMapShape::query()
                        ->join('market_spaces', 'market_spaces.id', '=', 'market_space_map_shapes.market_space_id')
                        ->where('market_space_map_shapes.market_id', (int) $market->id)
                        ->where('market_spaces.market_id', (int) $market->id)
                        ->where('market_spaces.space_group_parent_id', $marketSpaceId)
                        ->where('market_spaces.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                        ->where('market_space_map_shapes.is_active', true)
                        ->where('market_space_map_shapes.page', $page)
                        ->where('market_space_map_shapes.version', $version)
                        ->whereNotNull('market_space_map_shapes.bbox_x1')
                        ->whereNotNull('market_space_map_shapes.bbox_y1')
                        ->whereNotNull('market_space_map_shapes.bbox_x2')
                        ->whereNotNull('market_space_map_shapes.bbox_y2')
                        ->selectRaw('
                            MIN(market_space_map_shapes.bbox_x1) as bbox_x1,
                            MIN(market_space_map_shapes.bbox_y1) as bbox_y1,
                            MAX(market_space_map_shapes.bbox_x2) as bbox_x2,
                            MAX(market_space_map_shapes.bbox_y2) as bbox_y2
                        ')
                        ->first();

                    $focusShape = [
                        'id' => (int) $groupFocusShape->id,
                        'market_space_id' => $marketSpaceId,
                        'is_group' => true,
                        'group_parent_id' => $marketSpaceId,
                        'page' => $page,
                        'version' => $version,
                        'bbox' => [
                            'x1' => $bboxFromRequest['x1'] ?? ($groupBbox?->bbox_x1 !== null ? (float) $groupBbox->bbox_x1 : ($groupFocusShape->bbox_x1 !== null ? (float) $groupFocusShape->bbox_x1 : null)),
                            'y1' => $bboxFromRequest['y1'] ?? ($groupBbox?->bbox_y1 !== null ? (float) $groupBbox->bbox_y1 : ($groupFocusShape->bbox_y1 !== null ? (float) $groupFocusShape->bbox_y1 : null)),
                            'x2' => $bboxFromRequest['x2'] ?? ($groupBbox?->bbox_x2 !== null ? (float) $groupBbox->bbox_x2 : ($groupFocusShape->bbox_x2 !== null ? (float) $groupFocusShape->bbox_x2 : null)),
                            'y2' => $bboxFromRequest['y2'] ?? ($groupBbox?->bbox_y2 !== null ? (float) $groupBbox->bbox_y2 : ($groupFocusShape->bbox_y2 !== null ? (float) $groupFocusShape->bbox_y2 : null)),
                        ],
                    ];
                } else {
                    $marketSpaceNotLinked = true;
                }
            }
        } elseif ($marketSpaceId) {
            $marketSpaceNotLinked = true;
        }

        $viewerUser = Filament::auth()->user();
        $canOpenPdf = (bool) ($viewerUser
            && method_exists($viewerUser, 'isSuperAdmin')
            && $viewerUser->isSuperAdmin());

        $payload = [
            'market' => $market,
            'marketId' => (int) ($market->id ?? 0),
            'marketName' => (string) ($market->name ?? 'Рынок'),
            'hasMap' => $hasMap,
            'canEdit' => (bool) $canEditShapes(),
            'mapMode' => $mode === 'review' ? 'review' : 'map',
            'canOpenPdf' => $canOpenPdf,
            'mapPage' => $page,
            'mapVersion' => $version,
            'marketSpaceId' => $marketSpaceId,
            'focusShape' => $focusShape,
            'marketSpaceNotLinked' => $marketSpaceNotLinked,
            'reviewProgress' => $buildMapReviewProgress($market),
            'debtYellowAfterDays' => (int) ($market->settings['debt_monitoring']['yellow_after_days']
                ?? $market->settings['debt_monitoring']['orange_after_days']
                ?? 1),
            'debtRedAfterDays' => (int) ($market->settings['debt_monitoring']['red_after_days'] ?? 30),
            'returnUrl' => $returnUrl,

            'settingsUrl' => url('/admin/market-settings'),

            'pdfUrl' => route('filament.admin.market-map.pdf'),
            'hitUrl' => route('filament.admin.market-map.hit'),
            'shapesUrl' => route('filament.admin.market-map.shapes'),
            'spaceUrl' => route('filament.admin.market-map.space'),
            'spacesUrl' => route('filament.admin.market-map.spaces'),
            'reviewDecisionUrl' => route('filament.admin.market-map.review-decision'),
            'returnUrl' => $returnUrl,
        ];

        if (! $hasMap) {
            return response()
                ->view('admin.market-map-empty', $payload)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($marketSpaceNotLinked) {
            return response()
                ->view('admin.market-map-unbound', $payload)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()
            ->view('admin.market-map', $payload)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    })->name('filament.admin.market-map');

    /**
     * Выдача приватного PDF карты для PDF.js (только авторизованным).
     */
    Route::get('/admin/market-map/pdf', function () use ($resolveMarketForMap) {
        $market = $resolveMarketForMap();

        $mapPath = data_get($market->settings ?? [], 'map_pdf_path');

        abort_unless(is_string($mapPath) && $mapPath !== '', 404);
        abort_unless(str_starts_with($mapPath, 'market-maps/'), 404);
        abort_unless(Storage::disk('local')->exists($mapPath), 404);

        $absolute = Storage::disk('local')->path($mapPath);

        return response()->file($absolute, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="market-map.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->name('filament.admin.market-map.pdf');
});

Route::middleware('guest')->group(function () {
    Route::get('/register/market', [MarketRegistrationController::class, 'create'])
        ->name('market.register');

    Route::post('/register/market', [MarketRegistrationController::class, 'store'])
        ->name('market.register.store');
});

Route::get('/marketplace', function (MarketplaceContextService $context) {
    $market = $context->resolveMarket();

    abort_unless($market, 404);
    $marketSlug = filled($market->slug) ? (string) $market->slug : (string) $market->id;

    return redirect()->route('marketplace.home', ['marketSlug' => $marketSlug]);
})->name('marketplace.entry');

Route::prefix('/m/{marketSlug}')->middleware('marketplace.ready')->group(function () {
    Route::get('/', MarketplaceHomeController::class)->name('marketplace.home');
    Route::get('/catalog', [MarketplaceCatalogController::class, 'index'])->name('marketplace.catalog');
    Route::get('/product/{productSlug}', [MarketplaceProductController::class, 'show'])->name('marketplace.product.show');
    Route::get('/store/{tenantSlug}', [MarketplaceStoreController::class, 'show'])->name('marketplace.store.show');
    Route::post('/store/{tenantSlug}/review', [MarketplaceStoreController::class, 'submitReview'])->name('marketplace.store.review');
    Route::get('/map', MarketplaceMapController::class)->name('marketplace.map');
    Route::get('/announcements', [MarketplaceAnnouncementController::class, 'index'])->name('marketplace.announcements');
    Route::get('/announcement/{announcementSlug}', [MarketplaceAnnouncementController::class, 'show'])->name('marketplace.announcement.show');

    Route::get('/login', [MarketplaceBuyerAuthController::class, 'showLogin'])->name('marketplace.login');
    Route::post('/login', [MarketplaceBuyerAuthController::class, 'login'])->name('marketplace.login.submit');
    Route::get('/register', [MarketplaceBuyerAuthController::class, 'showRegister'])->name('marketplace.register');
    Route::post('/register', [MarketplaceBuyerAuthController::class, 'register'])->name('marketplace.register.submit');

    Route::middleware(['auth', 'marketplace.buyer'])->group(function () {
        Route::post('/logout', [MarketplaceBuyerAuthController::class, 'logout'])->name('marketplace.logout');

        Route::post('/favorite/{productSlug}/toggle', [MarketplaceBuyerFavoriteController::class, 'toggle'])
            ->name('marketplace.favorite.toggle');

        Route::get('/buyer', [MarketplaceBuyerCabinetController::class, 'dashboard'])->name('marketplace.buyer.dashboard');
        Route::get('/buyer/favorites', [MarketplaceBuyerCabinetController::class, 'favorites'])->name('marketplace.buyer.favorites');

        Route::get('/buyer/chats', [MarketplaceBuyerChatController::class, 'index'])->name('marketplace.buyer.chats');
        Route::get('/buyer/chats/{chatId}', [MarketplaceBuyerChatController::class, 'show'])->name('marketplace.buyer.chat.show');
        Route::post('/buyer/chats/{chatId}/send', [MarketplaceBuyerChatController::class, 'send'])->name('marketplace.buyer.chat.send');
        Route::post('/store/{tenantSlug}/chat/start', [MarketplaceBuyerChatController::class, 'start'])->name('marketplace.buyer.chat.start');
    });
});
