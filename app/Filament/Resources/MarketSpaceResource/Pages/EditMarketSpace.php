<?php

// app/Filament/Resources/MarketSpaceResource/Pages/EditMarketSpace.php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Operation;
use App\Models\Tenant;
use App\Services\MarketSpaces\MarketSpaceStateGuard;
use App\Services\MarketSpaces\SpaceGroupManager;
use App\Services\MarketSpaces\TenantSwitchPlanner;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditMarketSpace extends BaseEditRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = null;

    protected ?string $pendingParentGroupMapShapeAction = null;

    protected function authorizeAccess(): void
    {
        if (! MarketSpaceResource::canView($this->record)) {
            abort(403);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->resolveSpaceHeading();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->resolveSpaceHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-market-spaces-edit-page',
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            MarketSpaceResource::getUrl('index') => (string) static::$resource::getPluralModelLabel(),
        ];
    }

    public function getHeader(): ?View
    {
        return view('filament.resources.market-spaces.partials.edit-hero', [
            'actions' => $this->getHeroActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'titleLine' => trim((string) ($this->record?->number ?? '')),
            'subtitleLine' => $this->resolveSubtitleLine(),
            'statusLabel' => $this->resolveStatusLabel(),
            'statusColor' => $this->resolveStatusColor(),
        ]);
    }

    public function getFooter(): ?View
    {
        $dangerActions = $this->getDangerZoneActions();

        if ($dangerActions === []) {
            return null;
        }

        return view('filament.resources.market-spaces.partials.edit-danger-zone', [
            'actions' => $dangerActions,
            'isCascadeDelete' => ! MarketSpaceResource::canDelete($this->record)
                && MarketSpaceResource::canDeleteWithMapShapeCascade($this->record),
        ]);
    }

    protected function resolveSubtitleLine(): string
    {
        if (($this->record?->space_group_role ?? null) === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return 'Группа мест';
        }

        return trim((string) ($this->record?->display_name ?? ''));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->abortIfReadOnly();

        $this->pendingParentGroupMapShapeAction = null;

        $parentGroupMapShapeAction = trim((string) ($data['parent_group_map_shape_action'] ?? ''));
        unset($data['parent_group_map_shape_action']);

        $this->prepareParentGroupMapShapeResolution($data, $parentGroupMapShapeAction);
        app(MarketSpaceStateGuard::class)->assertCanPersist($this->record, $data);

        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли никогда не меняют market_id.
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $this->record->market_id;

            return $data;
        }

        // Super-admin: если выбран рынок через переключатель — фиксируем market_id.
        $selectedMarketId = session('filament.admin.selected_market_id');
        if (filled($selectedMarketId)) {
            $data['market_id'] = (int) $selectedMarketId;
        } else {
            // Иначе не даём случайно "обнулить" поле.
            if (empty($data['market_id'])) {
                $data['market_id'] = $this->record->market_id;
            }
        }

        return $data;
    }

    private function prepareParentGroupMapShapeResolution(array $data, string $action): void
    {
        if (! $this->record instanceof MarketSpace) {
            return;
        }

        $nextRole = (string) ($data['space_group_role'] ?? $this->record->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE);

        if ($nextRole !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return;
        }

        if ($this->activeParentGroupMapShapeCount($this->record) <= 0) {
            return;
        }

        if (! in_array($action, ['deactivate', 'delete'], true)) {
            throw ValidationException::withMessages([
                'parent_group_map_shape_action' => 'Выберите, что сделать с активной фигурой карты при переводе места в parent-группу.',
            ]);
        }

        $this->pendingParentGroupMapShapeAction = $action;
    }

    private function activeParentGroupMapShapeQuery(MarketSpace $record)
    {
        $query = MarketSpaceMapShape::query()
            ->where('market_space_id', (int) $record->id);

        if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query;
    }

    private function activeParentGroupMapShapeCount(MarketSpace $record): int
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return 0;
        }

        return (int) $this->activeParentGroupMapShapeQuery($record)->count();
    }

    protected function afterSave(): void
    {
        if ($this->isReadOnly()) {
            return;
        }

        $this->applyPendingParentGroupMapShapeResolution();
    }

    private function applyPendingParentGroupMapShapeResolution(): void
    {
        if (! $this->record instanceof MarketSpace || $this->pendingParentGroupMapShapeAction === null) {
            return;
        }

        $this->record->refresh();

        if ((string) ($this->record->space_group_role ?? '') !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $this->pendingParentGroupMapShapeAction = null;

            return;
        }

        $action = $this->pendingParentGroupMapShapeAction;
        $this->pendingParentGroupMapShapeAction = null;

        $shapeCount = $this->activeParentGroupMapShapeCount($this->record);

        if ($shapeCount <= 0) {
            return;
        }

        $recordId = (int) $this->record->id;
        $marketId = (int) $this->record->market_id;
        $number = trim((string) ($this->record->number ?? ''));

        DB::transaction(function () use ($action, $recordId, $marketId, $number, $shapeCount): void {
            $query = MarketSpaceMapShape::query()
                ->where('market_space_id', $recordId);

            if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
                $query->where('is_active', true);
            }

            if ($action === 'delete') {
                $query->delete();
            } else {
                $updates = ['market_space_id' => null];

                if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
                    $updates['is_active'] = false;
                }

                $query->update($updates);
            }

            Operation::create([
                'market_id' => $marketId,
                'entity_type' => 'market_space',
                'entity_id' => $recordId,
                'type' => OperationType::SPACE_ATTRS_CHANGE,
                'status' => 'applied',
                'comment' => 'Разбор фигуры карты при переводе места в parent-группу',
                'payload' => [
                    'market_space_id' => $recordId,
                    'number' => $number !== '' ? $number : null,
                    'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
                    'map_shape_action' => $action,
                    'affected_shapes_count' => $shapeCount,
                ],
            ]);
        });

        Notification::make()
            ->success()
            ->title($action === 'delete' ? 'Фигура карты удалена' : 'Фигура карты отвязана')
            ->body($action === 'delete'
                ? 'Место стало parent-группой. Старая обычная фигура карты удалена.'
                : 'Место стало parent-группой. Старая обычная фигура карты отвязана и деактивирована.')
            ->send();

        $this->record->refresh();
        $this->fillForm();
    }

    public function toggleMarketSpaceActiveState(): void
    {
        $this->abortIfReadOnly();

        if (! $this->record) {
            return;
        }

        $newState = ! (bool) $this->record->is_active;

        $this->record->forceFill([
            'is_active' => $newState,
        ])->save();

        $this->record->refresh();

        Notification::make()
            ->success()
            ->title($newState ? 'Торговое место активно' : 'Торговое место отключено')
            ->send();
    }

    public function changeNumber(array $data): void
    {
        $this->abortIfReadOnly();

        if (! $this->record instanceof MarketSpace) {
            throw ValidationException::withMessages([
                'number' => 'Торговое место не найдено.',
            ]);
        }

        $number = trim((string) ($data['number'] ?? ''));

        if ($number === '') {
            throw ValidationException::withMessages([
                'number' => 'Введите новый номер.',
            ]);
        }

        $currentNumber = trim((string) ($this->record->number ?? ''));
        if ($currentNumber === $number) {
            Notification::make()
                ->info()
                ->title('Номер не изменился')
                ->send();

            return;
        }

        Operation::create([
            'market_id' => (int) $this->record->market_id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $this->record->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'status' => 'applied',
            'comment' => 'Изменение номера через карточку места',
            'payload' => [
                'market_space_id' => (int) $this->record->id,
                'number' => $number,
            ],
        ]);

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Номер места обновлён')
            ->body('Номер изменён через отдельное действие. Карта и остальные связи не менялись.')
            ->send();
    }

    public function deleteMarketSpaceWithShapes(array $data): void
    {
        $this->abortIfReadOnly();

        if (! $this->record instanceof MarketSpace) {
            return;
        }

        $confirmed = (bool) ($data['confirm_delete_with_shape'] ?? false);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_delete_with_shape' => 'Подтвердите удаление места и фигуры на карте.',
            ]);
        }

        if (! MarketSpaceResource::canDeleteWithMapShapeCascade($this->record)) {
            Notification::make()
                ->danger()
                ->title('Удаление недоступно')
                ->body('У места есть дополнительные связи. Для него нужен другой сценарий разбора.')
                ->send();

            return;
        }

        $recordId = (int) $this->record->id;
        $marketId = (int) $this->record->market_id;
        $number = trim((string) ($this->record->number ?? ''));
        $displayName = trim((string) ($this->record->display_name ?? ''));
        $shapeCount = (int) MarketSpaceMapShape::query()
            ->where('market_space_id', $recordId)
            ->count();

        DB::transaction(function () use ($recordId, $marketId, $number, $displayName, $shapeCount): void {
            MarketSpaceMapShape::query()
                ->where('market_space_id', $recordId)
                ->delete();

            MarketSpace::query()
                ->whereKey($recordId)
                ->delete();

            Operation::create([
                'market_id' => $marketId,
                'entity_type' => 'market_space',
                'entity_id' => $recordId,
                'type' => OperationType::SPACE_ATTRS_CHANGE,
                'status' => 'applied',
                'comment' => 'Удаление пустого места вместе с фигурой карты',
                'payload' => [
                    'market_space_id' => $recordId,
                    'deleted_with_map_shapes' => true,
                    'deleted_shapes_count' => $shapeCount,
                    'number' => $number !== '' ? $number : null,
                    'display_name' => $displayName !== '' ? $displayName : null,
                ],
            ]);
        });

        Notification::make()
            ->success()
            ->title('Место и фигура удалены')
            ->body('Карточка места удалена вместе с привязанной фигурой карты. История операций сохранена.')
            ->send();

        $returnUrl = request()->query('return_url');

        $this->redirect(
            is_string($returnUrl) && $returnUrl !== ''
                ? $returnUrl
                : MarketSpaceResource::getUrl('index')
        );
    }

    public function deleteMarketSpacePermanently(array $data): void
    {
        $this->abortIfReadOnly();

        if (! $this->record instanceof MarketSpace) {
            return;
        }

        $confirmed = (bool) ($data['confirm_delete_place'] ?? false);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_delete_place' => 'Подтвердите полное удаление места.',
            ]);
        }

        if (! MarketSpaceResource::canDelete($this->record)) {
            Notification::make()
                ->danger()
                ->title('Удаление недоступно')
                ->body('У места есть связи или у вас недостаточно прав для полного удаления.')
                ->send();

            return;
        }

        $recordId = (int) $this->record->id;
        $returnUrl = request()->query('return_url');

        MarketSpace::query()->whereKey($recordId)->delete();

        Notification::make()
            ->success()
            ->title('Место удалено')
            ->body('Карточка места удалена из системы полностью.')
            ->send();

        $this->redirect(
            is_string($returnUrl) && $returnUrl !== ''
                ? $returnUrl
                : MarketSpaceResource::getUrl('index')
        );
    }

    protected function canDeactivateAfterPrecheck(): bool
    {
        return (bool) ($this->buildDeactivatePrecheckViewData()['canDeactivate'] ?? false);
    }

    public function deactivateMarketSpaceAfterPrecheck(): void
    {
        $this->abortIfReadOnly();

        if (! $this->record instanceof MarketSpace) {
            return;
        }

        if (! $this->canDeactivateAfterPrecheck()) {
            Notification::make()
                ->warning()
                ->title('Упразднение пока недоступно')
                ->body('Сначала разберите связи, которые ещё мешают безопасно упразднить место.')
                ->send();

            return;
        }

        if (! (bool) $this->record->is_active) {
            Notification::make()
                ->info()
                ->title('Место уже упразднено')
                ->body('Карточка уже выведена из активной работы.')
                ->send();

            return;
        }

        Operation::create([
            'market_id' => (int) $this->record->market_id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $this->record->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'status' => 'applied',
            'comment' => 'Упразднение места после проверки связей',
            'payload' => [
                'market_space_id' => (int) $this->record->id,
                'is_active' => false,
            ],
        ]);

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Место упразднено')
            ->body('Место выведено из активной работы после успешной проверки связей.')
            ->send();
    }

    protected function buildDeactivatePrecheckViewData(): array
    {
        if (! $this->record) {
            return [
                'spaceLabel' => 'Торговое место',
                'statusLabel' => 'Нет данных для проверки',
                'statusTone' => 'gray',
                'introText' => 'Предпросмотр не доступен без карточки места.',
                'liveRelations' => [],
                'transferableRelations' => [],
                'blockingRelations' => [],
                'historicalRelations' => [],
                'contractsUrl' => null,
                'contractPreview' => [],
                'accrualsUrl' => null,
                'accrualPreview' => [],
                'canDeactivate' => false,
            ];
        }

        $recordId = (int) $this->record->getKey();
        $spaceLabel = trim((string) ($this->record->display_name ?: $this->record->number ?: ''));

        if ($spaceLabel === '') {
            $spaceLabel = 'Торговое место';
        }

        $countRows = static function (string $table, ?callable $scope = null): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            $query = DB::table($table);

            if ($scope) {
                $scope($query);
            }

            return (int) $query->count();
        };

        $makeItem = static function (string $label, int $count, string $bucketLabel, string $note): array {
            return [
                'label' => $label,
                'count' => $count,
                'bucket_label' => $bucketLabel,
                'note' => $note,
            ];
        };

        $liveRelations = [];
        $transferableRelations = [];
        $blockingRelations = [];
        $historicalRelations = [];
        $contractPreview = [];
        $accrualPreview = [];
        $contractsUrl = \App\Filament\Resources\TenantContractResource::getUrl('index', [
            'marketSpaceId' => $recordId,
            'tab' => 'all',
        ]);
        $accrualsUrl = \App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index', [
            'marketSpaceId' => $recordId,
            'tab' => 'all',
        ]);

        $currentTenantId = filled($this->record->tenant_id) ? (int) $this->record->tenant_id : null;
        $currentTenantName = null;

        if ($currentTenantId && Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'id') && Schema::hasColumn('tenants', 'name')) {
            $currentTenantName = DB::table('tenants')
                ->where('id', $currentTenantId)
                ->value('name');
        }

        if ($currentTenantId) {
            $item = $makeItem(
                'Текущий арендатор',
                1,
                'Блокирует',
                filled($currentTenantName)
                    ? 'Арендатор: '.$currentTenantName
                    : 'Место сейчас участвует в работе'
            );

            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        // Проверка inherited occupancy через родительскую группу
        $effectiveTenantId = $this->record->effectiveTenantId();
        $effectiveTenantName = $this->record->effectiveTenantName();
        $effectiveOccupancySource = $this->record->effectiveOccupancySource();

        if ($effectiveTenantId && $effectiveOccupancySource === 'parent') {
            $parentLabel = trim((string) ($this->record->spaceGroupParent?->number ?? ''));
            $parentLabel = $parentLabel !== '' ? $parentLabel : ('#'.(int) ($this->record->space_group_parent_id ?? 0));

            $item = $makeItem(
                'Наследуемый арендатор (через группу)',
                1,
                'Блокирует',
                filled($effectiveTenantName)
                    ? 'Арендатор группы '.e($parentLabel).': '.$effectiveTenantName
                    : 'Место занято через родительскую группу '.e($parentLabel)
            );

            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $shapeCount = $countRows('market_space_map_shapes', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);

            if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
                $query->where('is_active', true);
            }
        });

        if ($shapeCount > 0) {
            $item = $makeItem(
                'Фигуры на карте',
                $shapeCount,
                'Переносится',
                'Можно перенести в отдельном сценарии переноса'
            );
            $liveRelations[] = $item;
            $transferableRelations[] = $item;
        }

        $cabinetLinksCount = $countRows('tenant_user_market_spaces', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($cabinetLinksCount > 0) {
            $item = $makeItem(
                'Кабинетные связи',
                $cabinetLinksCount,
                'Переносится',
                'Можно перенести в отдельном сценарии переноса'
            );
            $liveRelations[] = $item;
            $transferableRelations[] = $item;
        }

        $productsCount = $countRows('marketplace_products', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($productsCount > 0) {
            $item = $makeItem(
                'Товары',
                $productsCount,
                'Переносится',
                'Можно перенести в отдельном сценарии переноса'
            );
            $liveRelations[] = $item;
            $transferableRelations[] = $item;
        }

        $contractCount = $countRows('tenant_contracts', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($contractCount > 0) {
            $item = $makeItem(
                'Договоры',
                $contractCount,
                'Блокирует',
                'Автоперенос небезопасен'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        if ($contractCount > 0 && Schema::hasTable('tenant_contracts')) {
            $contractPreview = DB::table('tenant_contracts as tc')
                ->leftJoin('tenants as t', 't.id', '=', 'tc.tenant_id')
                ->where('tc.market_space_id', $recordId)
                ->orderByRaw('CASE WHEN tc.is_active = true THEN 0 ELSE 1 END')
                ->orderByDesc('tc.starts_at')
                ->orderByDesc('tc.id')
                ->limit(5)
                ->get([
                    'tc.id',
                    'tc.number',
                    'tc.status',
                    'tc.is_active',
                    'tc.starts_at',
                    'tc.ends_at',
                    't.name as tenant_name',
                ])
                ->map(static function ($row): array {
                    return [
                        'id' => (int) $row->id,
                        'number' => $row->number ? (string) $row->number : '—',
                        'tenant_name' => $row->tenant_name ? (string) $row->tenant_name : '—',
                        'status' => $row->status ? (string) $row->status : '—',
                        'is_active' => (bool) ($row->is_active ?? false),
                        'starts_at' => $row->starts_at ? (string) $row->starts_at : null,
                        'ends_at' => $row->ends_at ? (string) $row->ends_at : null,
                        'edit_url' => \App\Filament\Resources\TenantContractResource::getUrl('edit', ['record' => (int) $row->id]),
                    ];
                })
                ->all();
        }

        $accrualCount = $countRows('tenant_accruals', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($accrualCount > 0) {
            $item = $makeItem(
                'Начисления',
                $accrualCount,
                'Блокирует',
                'Финансовую связь не нужно переносить автоматически'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;

            if (Schema::hasTable('tenant_accruals')) {
                $accrualPreview = DB::table('tenant_accruals as ta')
                    ->leftJoin('tenant_contracts as tc', 'tc.id', '=', 'ta.tenant_contract_id')
                    ->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id')
                    ->where('ta.market_space_id', $recordId)
                    ->orderByDesc('ta.period')
                    ->orderByDesc('ta.id')
                    ->limit(5)
                    ->get([
                        'ta.id',
                        'ta.period',
                        'ta.total_with_vat',
                        'tc.number as contract_number',
                        't.name as tenant_name',
                    ])
                    ->map(static function ($row): array {
                        return [
                            'id' => (int) $row->id,
                            'period' => $row->period ? (string) $row->period : '—',
                            'contract_number' => $row->contract_number ? (string) $row->contract_number : '—',
                            'tenant_name' => $row->tenant_name ? (string) $row->tenant_name : '—',
                            'total_with_vat' => $row->total_with_vat !== null ? (float) $row->total_with_vat : null,
                            'edit_url' => \App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('edit', ['record' => (int) $row->id]),
                        ];
                    })
                    ->all();
            }
        }

        $bindingActiveCount = $countRows('market_space_tenant_bindings', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);

            if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
                $query->whereNull('ended_at');
            }
        });

        if ($bindingActiveCount > 0) {
            $item = $makeItem(
                'Активные привязки',
                $bindingActiveCount,
                'Блокирует',
                'Автоперенос небезопасен'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $bindingHistoricalCount = $countRows('market_space_tenant_bindings', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);

            if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
                $query->whereNotNull('ended_at');
            }
        });

        if ($bindingHistoricalCount > 0) {
            $historicalRelations[] = $makeItem(
                'История привязок',
                $bindingHistoricalCount,
                'Архив',
                'Старые закрытые связи'
            );
        }

        $requestsCount = $countRows('tenant_requests', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($requestsCount > 0) {
            $item = $makeItem(
                'Заявки',
                $requestsCount,
                'Блокирует',
                'Автоперенос небезопасен'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $ticketsCount = $countRows('tickets', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($ticketsCount > 0) {
            $item = $makeItem(
                'Тикеты',
                $ticketsCount,
                'Блокирует',
                'Перенос не автоматизируется'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $reviewsCount = $countRows('tenant_reviews', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($reviewsCount > 0) {
            $item = $makeItem(
                'Оценки и отзывы',
                $reviewsCount,
                'Блокирует',
                'Контекст лучше оставить в этом месте'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $showcasesCount = $countRows('tenant_space_showcases', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($showcasesCount > 0) {
            $item = $makeItem(
                'Витрина',
                $showcasesCount,
                'Блокирует',
                'Ручная перепривязка ещё нужна'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $chatsCount = $countRows('marketplace_chats', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($chatsCount > 0) {
            $item = $makeItem(
                'Чаты',
                $chatsCount,
                'Блокирует',
                'История общения остаётся на месте'
            );
            $liveRelations[] = $item;
            $blockingRelations[] = $item;
        }

        $historyCount = $countRows('market_space_tenant_histories', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($historyCount > 0) {
            $historicalRelations[] = $makeItem(
                'История арендаторов',
                $historyCount,
                'Архив',
                'Старые смены арендатора'
            );
        }

        $rentHistoryCount = $countRows('market_space_rent_rate_histories', static function ($query) use ($recordId): void {
            $query->where('market_space_id', $recordId);
        });

        if ($rentHistoryCount > 0) {
            $historicalRelations[] = $makeItem(
                'История ставок',
                $rentHistoryCount,
                'Архив',
                'Финансовый след оставляем как есть'
            );
        }

        $operationsCount = $countRows('operations', static function ($query) use ($recordId): void {
            $query->where('entity_type', 'market_space')
                ->where('entity_id', $recordId);
        });

        if ($operationsCount > 0) {
            $historicalRelations[] = $makeItem(
                'Журнал операций',
                $operationsCount,
                'Архив',
                'Аудит и история действий'
            );
        }

        $blockingTotal = array_sum(array_map(static fn (array $item): int => (int) $item['count'], $blockingRelations));
        $transferableTotal = array_sum(array_map(static fn (array $item): int => (int) $item['count'], $transferableRelations));
        $liveTotal = array_sum(array_map(static fn (array $item): int => (int) $item['count'], $liveRelations));

        if ($blockingTotal > 0) {
            $statusLabel = 'Можно продолжать только после ручного разбора';
            $statusTone = 'warning';
        } elseif ($transferableTotal > 0) {
            $statusLabel = 'Нужен отдельный перенос переносимых связей';
            $statusTone = 'info';
        } elseif ($liveTotal > 0) {
            $statusLabel = 'Живые связи найдены';
            $statusTone = 'success';
        } else {
            $statusLabel = 'Живых связей не найдено';
            $statusTone = 'success';
        }

        return [
            'spaceLabel' => $spaceLabel,
            'statusLabel' => $statusLabel,
            'statusTone' => $statusTone,
            'introText' => 'Простое выключение места несёт риск рассинхрона связей. Сначала нужен просмотр связей и ручной разбор.',
            'liveRelations' => $liveRelations,
            'transferableRelations' => $transferableRelations,
            'blockingRelations' => $blockingRelations,
            'historicalRelations' => $historicalRelations,
            'contractsUrl' => $contractsUrl,
            'contractPreview' => $contractPreview,
            'accrualsUrl' => $accrualsUrl,
            'accrualPreview' => $accrualPreview,
            'canDeactivate' => $liveTotal === 0 && $transferableTotal === 0 && $blockingTotal === 0 && (bool) ($this->record->is_active ?? false),
        ];
    }

    /**
     * Pre-check для отметки места как свободного.
     * Проверяет блокирующие связи (договоры, начисления, привязки) и классифицирует их.
     *
     * @return array{
     *   canMarkFree: bool,
     *   contracts: list<array{id:int,number:string,tenant_name:string,ends_at:?string,is_expired:bool}>,
     *   accruals: list<array{id:int,period:string,is_current:bool,total:float,contract_number:string}>,
     *   currentAccrualsCount: int,
     *   blockingContractsCount: int,
     *   autoClosePossible: bool,
     *   warnings: list<string>,
     *   contractsUrl: ?string,
     *   accrualsUrl: ?string,
     * }
     */
    public function buildMarkSpaceFreePrecheckData(): array
    {
        if (! $this->record instanceof MarketSpace) {
            return [
                'canMarkFree' => false,
                'contracts' => [],
                'accruals' => [],
                'currentAccrualsCount' => 0,
                'blockingContractsCount' => 0,
                'autoClosePossible' => false,
                'warnings' => [],
                'contractsUrl' => null,
                'accrualsUrl' => null,
            ];
        }

        $recordId = (int) $this->record->getKey();
        $marketId = (int) $this->record->market_id;

        $contractsUrl = \App\Filament\Resources\TenantContractResource::getUrl('index', [
            'marketSpaceId' => $recordId,
            'tab' => 'all',
        ]);

        $accrualsUrl = \App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index', [
            'marketSpaceId' => $recordId,
            'tab' => 'all',
        ]);

        // 1. Договоры с классификацией
        $contracts = [];
        $blockingContractsCount = 0;

        if (Schema::hasTable('tenant_contracts')) {
            $contractsData = DB::table('tenant_contracts as tc')
                ->leftJoin('tenants as t', 't.id', '=', 'tc.tenant_id')
                ->where('tc.market_space_id', $recordId)
                ->where(function ($q) {
                    $q->where('tc.is_active', true)
                        ->orWhereNotIn('tc.status', ['terminated', 'archived']);
                })
                ->orderByDesc('tc.starts_at')
                ->orderByDesc('tc.id')
                ->get([
                    'tc.id',
                    'tc.number',
                    'tc.status',
                    'tc.is_active',
                    'tc.ends_at',
                    't.name as tenant_name',
                ]);

            foreach ($contractsData as $row) {
                $endsAt = $row->ends_at ? (string) $row->ends_at : null;
                $isExpired = $endsAt !== null && strtotime($endsAt) < time();

                if (! $isExpired) {
                    $blockingContractsCount++;
                }

                $contracts[] = [
                    'id' => (int) $row->id,
                    'number' => $row->number ? (string) $row->number : '—',
                    'tenant_name' => $row->tenant_name ? (string) $row->tenant_name : '—',
                    'ends_at' => $endsAt,
                    'is_expired' => $isExpired,
                ];
            }
        }

        // 2. Начисления с классификацией
        $accruals = [];
        $currentAccrualsCount = 0;

        if (Schema::hasTable('tenant_accruals')) {
            $accrualsData = DB::table('tenant_accruals as ta')
                ->leftJoin('tenant_contracts as tc', 'tc.id', '=', 'ta.tenant_contract_id')
                ->where('ta.market_space_id', $recordId)
                ->orderByDesc('ta.period')
                ->orderByDesc('ta.id')
                ->get([
                    'ta.id',
                    'ta.period',
                    'ta.total_with_vat',
                    'tc.number as contract_number',
                ]);

            $currentMonth = date('Y-m');

            foreach ($accrualsData as $row) {
                $period = $row->period ? (string) $row->period : '';
                $isCurrent = $period !== '' && $period >= $currentMonth;

                if ($isCurrent) {
                    $currentAccrualsCount++;
                }

                $accruals[] = [
                    'id' => (int) $row->id,
                    'period' => $period,
                    'is_current' => $isCurrent,
                    'total' => $row->total_with_vat !== null ? (float) $row->total_with_vat : 0.0,
                    'contract_number' => $row->contract_number ? (string) $row->contract_number : '—',
                ];
            }
        }

        // 3. Активные привязки по договору
        $activeBindingsCount = 0;
        if (Schema::hasTable('market_space_tenant_bindings')) {
            $activeBindingsCount = (int) DB::table('market_space_tenant_bindings')
                ->where('market_space_id', $recordId)
                ->whereNotNull('tenant_contract_id')
                ->whereNull('ended_at')
                ->count();
        }

        // 4. Открытые заявки
        $requestsCount = 0;
        if (Schema::hasTable('tenant_requests')) {
            $requestsCount = (int) DB::table('tenant_requests')
                ->where('market_space_id', $recordId)
                ->whereNotIn('status', ['closed', 'cancelled'])
                ->count();
        }

        // 5. Открытые тикеты
        $ticketsCount = 0;
        if (Schema::hasTable('tickets')) {
            $ticketsCount = (int) DB::table('tickets')
                ->where('market_space_id', $recordId)
                ->whereNotIn('status', ['closed', 'resolved', 'cancelled'])
                ->count();
        }

        // Классификация: можно ли отметить свободно?
        $canMarkFree = $blockingContractsCount === 0
            && $currentAccrualsCount === 0
            && $activeBindingsCount === 0
            && $requestsCount === 0
            && $ticketsCount === 0;

        // Можно ли автоматически завершить договоры?
        $autoClosePossible = $blockingContractsCount === 0
            && count($contracts) > 0
            && collect($contracts)->every(fn ($c) => $c['is_expired']);

        // Предупреждения
        $warnings = [];
        if ($currentAccrualsCount > 0) {
            $warnings[] = "Найдены текущие начисления ({$currentAccrualsCount}). Проверьте, не ошибочные ли они.";
        }
        if ($activeBindingsCount > 0) {
            $warnings[] = "Найдены активные привязки по договору ({$activeBindingsCount}). Требуется ручное завершение.";
        }
        if ($requestsCount > 0) {
            $warnings[] = "Найдены открытые заявки ({$requestsCount}). Требуется решение.";
        }
        if ($ticketsCount > 0) {
            $warnings[] = "Найдены открытые тикеты ({$ticketsCount}). Требуется решение.";
        }

        return [
            'canMarkFree' => $canMarkFree,
            'contracts' => $contracts,
            'accruals' => $accruals,
            'currentAccrualsCount' => $currentAccrualsCount,
            'blockingContractsCount' => $blockingContractsCount,
            'autoClosePossible' => $autoClosePossible,
            'warnings' => $warnings,
            'contractsUrl' => $contractsUrl,
            'accrualsUrl' => $accrualsUrl,
        ];
    }

    /**
     * Метод проверки перед отметкой места как свободного.
     */
    public function canMarkSpaceFreeAfterPrecheck(): bool
    {
        $precheck = $this->buildMarkSpaceFreePrecheckData();

        return (bool) ($precheck['canMarkFree'] ?? false);
    }

    /**
     * Отметить место как свободное после проверки связей.
     *
     * @param  array<string, mixed>  $data
     */
    public function markSpaceFreeAfterPrecheck(array $data): void
    {
        $this->abortIfReadOnly();

        if (! $this->record instanceof MarketSpace) {
            return;
        }

        $affectedChildren = $this->parentStatusChangeAffectedChildren();
        $precheck = $this->buildMarkSpaceFreePrecheckData();

        if (! $precheck['canMarkFree']) {
            $this->throwBlockingRelationsException($precheck);
        }

        $confirmContractsClose = (bool) ($data['confirm_contracts_close'] ?? false);
        $confirmAccrualsWarning = (bool) ($data['confirm_accruals_warning'] ?? false);

        // Проверка подтверждений
        if ($precheck['blockingContractsCount'] > 0 && ! $confirmContractsClose) {
            throw ValidationException::withMessages([
                'confirm_contracts_close' => 'Подтвердите завершение договоров.',
            ]);
        }

        if ($precheck['currentAccrualsCount'] > 0 && ! $confirmAccrualsWarning) {
            throw ValidationException::withMessages([
                'confirm_accruals_warning' => 'Подтвердите, что текущие начисления проверены.',
            ]);
        }

        // Завершение истёкших договоров (если разрешено)
        if ($confirmContractsClose && $precheck['contracts'] !== []) {
            $this->terminateExpiredContracts($this->record, $precheck['contracts']);
        }

        // Создать операцию mark_space_free
        $reason = trim((string) ($data['reason'] ?? ''));
        $operationPayload = [
            'market_space_id' => (int) $this->record->id,
            'decision' => 'mark_space_free',
        ];

        if ($reason !== '') {
            $operationPayload['reason'] = $reason;
        }

        if ($confirmContractsClose) {
            $operationPayload['contracts_closed'] = true;
            $operationPayload['closed_contracts_count'] = count($precheck['contracts']);
        }

        Operation::create([
            'market_id' => (int) $this->record->market_id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $this->record->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'effective_tz' => (string) config('app.timezone', 'UTC'),
            'status' => 'applied',
            'payload' => $operationPayload,
            'comment' => $reason !== '' ? $reason : null,
            'created_by' => Filament::auth()->id(),
        ]);

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Место отмечено как свободное')
            ->body((($confirmContractsClose && count($precheck['contracts']) > 0)
                ? 'Место переведено в свободные. Завершено договоров: '.count($precheck['contracts']).'.'
                : 'Место переведено в свободные.')
                .$this->buildParentStatusChangeNotificationSuffix($affectedChildren))
            ->send();
    }

    /**
     * Завершить истёкшие договоры.
     *
     * @param  list<array{id:int,number:string,tenant_name:string,ends_at:?string,is_expired:bool}>  $contracts
     */
    private function terminateExpiredContracts(MarketSpace $space, array $contracts): void
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return;
        }

        $now = now();

        foreach ($contracts as $contract) {
            if (! $contract['is_expired']) {
                continue;
            }

            $contractModel = \App\Models\TenantContract::query()
                ->where('market_id', (int) $space->market_id)
                ->whereKey($contract['id'])
                ->first();

            if (! $contractModel) {
                continue;
            }

            $contractModel->forceFill([
                'is_active' => false,
                'status' => 'terminated',
                'ends_at' => $contract['ends_at'] ?? $now->format('Y-m-d'),
            ])->save();
        }
    }

    /**
     * Бросить исключение с деталями блокирующих связей.
     *
     * @param  array<string, mixed>  $precheck
     */
    private function throwBlockingRelationsException(array $precheck): void
    {
        $parts = [];

        if ($precheck['blockingContractsCount'] > 0) {
            $parts[] = 'активные договоры';
        }
        if ($precheck['currentAccrualsCount'] > 0) {
            $parts[] = 'текущие начисления';
        }
        if (($precheck['contractsUrl'] ?? null) !== null) {
            // Дополнительная информация доступна
        }

        $message = 'Невозможно отметить место как свободное. Сначала разберите: '.implode(', ', $parts).'.';

        throw ValidationException::withMessages([
            'mark_space_free' => $message,
        ])->errorBanner();
    }

    private function buildTenantSwitchImpactHtml(): HtmlString
    {
        if (! $this->record instanceof MarketSpace) {
            return new HtmlString('—');
        }

        $record = $this->record->fresh(['tenant', 'spaceGroupParent.tenant', 'spaceGroupChildren']);
        $effectiveTenantName = trim((string) ($record?->effectiveTenantName() ?? ''));
        $isVacant = $effectiveTenantName === '';
        $effectiveTenantName = $effectiveTenantName !== '' ? $effectiveTenantName : '—';
        $stateLabel = $isVacant ? 'Занять свободное место' : 'Прямое место';
        $stateHint = $isVacant
            ? 'Арендатор будет назначен на карточку места с указанной даты.'
            : 'Смена затронет карточку места с указанной даты.';

        if ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD && filled($record->space_group_parent_id)) {
            $parentLabel = trim((string) ($record->spaceGroupParent?->number ?? ''));
            $parentLabel = $parentLabel !== '' ? $parentLabel : ('#'.(int) $record->space_group_parent_id);
            $stateLabel = 'Место в группе '.e($parentLabel);
            $stateHint = 'В дату вступления место выйдет из группы и получит прямого арендатора.';
        } elseif ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();
            $stateLabel = 'Группа мест';
            $stateHint = 'Child-места продолжат наследовать арендатора группы. Связанных мест: '.$childrenCount.'.';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #d7e3f4;border-radius:12px;background:#f8fbff;">'
            .'<div style="font-size:13px;line-height:1.45;color:#334155;"><strong>Текущий арендатор:</strong> '.e($effectiveTenantName).'</div>'
            .'<div style="font-size:13px;line-height:1.45;color:#334155;"><strong>Сценарий:</strong> '.$stateLabel.'</div>'
            .'<div style="font-size:12px;line-height:1.5;color:#475569;">'.$stateHint.' До этой даты карточка места не меняется.</div>'
            .'</div>'
        );
    }

    private function buildTenantSwitchWarningsHtml(): ?HtmlString
    {
        if (! $this->record instanceof MarketSpace) {
            return null;
        }

        $recordId = (int) $this->record->getKey();
        if ($recordId <= 0) {
            return null;
        }

        $contractCount = Schema::hasTable('tenant_contracts')
            ? (int) DB::table('tenant_contracts')->where('market_space_id', $recordId)->count()
            : 0;

        $accrualCount = Schema::hasTable('tenant_accruals')
            ? (int) DB::table('tenant_accruals')->where('market_space_id', $recordId)->count()
            : 0;

        $bindingCount = Schema::hasTable('market_space_tenant_bindings')
            ? (int) DB::table('market_space_tenant_bindings')
                ->where('market_space_id', $recordId)
                ->whereNull('ended_at')
                ->count()
            : 0;

        if ($contractCount === 0 && $accrualCount === 0 && $bindingCount === 0) {
            return null;
        }

        $links = [];

        if ($contractCount > 0) {
            $links[] = '<a href="'.e(\App\Filament\Resources\TenantContractResource::getUrl('index', [
                'marketSpaceId' => $recordId,
                'tab' => 'all',
            ])).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;">Договоры</a>';
        }

        if ($accrualCount > 0) {
            $links[] = '<a href="'.e(\App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index', [
                'marketSpaceId' => $recordId,
                'tab' => 'all',
            ])).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;">Начисления</a>';
        }

        $rows = [];

        if ($contractCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Договоры: '.$contractCount.'</span>';
        }

        if ($accrualCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Начисления: '.$accrualCount.'</span>';
        }

        if ($bindingCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Привязки: '.$bindingCount.'</span>';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;">'
            .'<div style="font-size:13px;font-weight:700;color:#92400e;">Найдены прямые связи. Их нужно проверить перед сменой арендатора.</div>'
            .'<div style="display:flex;flex-wrap:wrap;gap:8px;">'.implode('', $rows).'</div>'
            .($links !== [] ? '<div style="display:flex;flex-wrap:wrap;gap:8px;">'.implode('', $links).'</div>' : '')
            .'<div style="font-size:12px;line-height:1.45;color:#92400e;">Смена арендатора не переносит договоры и начисления автоматически, а меняет только управленческий snapshot по дате вступления.</div>'
            .'</div>'
        );
    }

    private function makeSharedUseManageAction(string $actionClass): mixed
    {
        return $actionClass::make('manage_shared_use')
            ->label('Участники')
            ->icon('heroicon-o-users')
            ->tooltip('Редактировать участников совместного использования и их площади')
            ->size('lg')
            ->outlined()
            ->color('primary')
            ->visible(fn (): bool => $this->record instanceof MarketSpace
                && ! $this->isMaintenanceSpace($this->record)
                && MarketSpaceResource::hasSharedUseTenants($this->record))
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--primary',
            ])
            ->modalHeading('Совместное использование')
            ->modalSubmitActionLabel('Сохранить участников')
            ->modalCancelActionLabel('Отмена')
            ->modalWidth(Width::FiveExtraLarge)
            ->slideOver()
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->fillForm(fn (): array => [
                'participants' => $this->sharedUseParticipantsFormState(),
            ])
            ->form([
                \Filament\Forms\Components\Placeholder::make('shared_use_manage_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="display:grid;gap:6px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e3a8a;">'
                        .'<div style="font-size:13px;font-weight:800;">Площадь и ставка меняются с датой вступления в силу.</div>'
                        .'<div style="font-size:12px;line-height:1.45;color:#475569;">Для действующего участника укажите более позднюю дату в поле «С даты действия». Система закроет текущую строку и создаст новую, чтобы сохранить историю и не спорить с импортом.</div>'
                        .'</div>'
                    )),
                \Filament\Forms\Components\Repeater::make('participants')
                    ->label('Участники')
                    ->schema([
                        \Filament\Forms\Components\Hidden::make('binding_id'),
                        \Filament\Forms\Components\Select::make('tenant_id')
                            ->label('Арендатор')
                            ->options(function (): array {
                                if (! $this->record instanceof MarketSpace) {
                                    return [];
                                }

                                return Tenant::query()
                                    ->where('market_id', (int) $this->record->market_id)
                                    ->active()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('Выберите арендатора')
                            ->disabled(fn ($get): bool => filled($get('binding_id'))),
                        \Filament\Forms\Components\TextInput::make('area_sqm')
                            ->label('Площадь, м²')
                            ->numeric()
                            ->inputMode('decimal')
                            ->required(fn ($get): bool => blank($get('binding_id')))
                            ->placeholder('Например: 2.5')
                            ->suffix('м²'),
                        \Filament\Forms\Components\TextInput::make('rent_rate')
                            ->label('Ставка')
                            ->numeric()
                            ->inputMode('decimal')
                            ->placeholder('Например: 2500')
                            ->suffix('₽'),
                        \Filament\Forms\Components\DateTimePicker::make('started_at')
                            ->label('С даты действия')
                            ->seconds(false)
                            ->default(fn (): \Illuminate\Support\Carbon => now())
                            ->helperText('Для новой версии строки укажите более позднюю дату, чем у текущей записи.'),
                        \Filament\Forms\Components\Textarea::make('share_note')
                            ->label('Комментарий')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Например: площадь этой части места'),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить участника')
                    ->reorderable(false)
                    ->cloneable(false)
                    ->itemLabel(function (array $state): ?string {
                        $tenantId = (int) ($state['tenant_id'] ?? 0);

                        if ($tenantId <= 0) {
                            return 'Новый участник';
                        }

                        return Tenant::query()->whereKey($tenantId)->value('name') ?: 'Участник';
                    }),
            ])
            ->action(function (array $data): void {
                $this->syncSharedUseParticipants($data['participants'] ?? []);
            });
    }

    private function makeStartSharedUseAction(string $actionClass): mixed
    {
        return $actionClass::make('start_shared_use')
            ->label('Начать совместное использование')
            ->icon('heroicon-o-user-group')
            ->tooltip('Подтвердить переход к совместному использованию и сразу выбрать участников')
            ->size('lg')
            ->outlined()
            ->color('primary')
            ->visible(function (): bool {
                if (! $this->record instanceof MarketSpace) {
                    return false;
                }

                return (string) ($this->record->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) === MarketSpace::SPACE_GROUP_ROLE_NONE
                    && ! $this->isMaintenanceSpace($this->record)
                    && ! MarketSpaceResource::hasSharedUseTenants($this->record)
                    && ! MarketSpaceResource::isSharedUseSourceSpace($this->record);
            })
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--primary',
            ])
            ->requiresConfirmation()
            ->modalHeading('Начать совместное использование')
            ->modalDescription('После подтверждения сразу откроется форма выбора арендатора и параметров его участия.')
            ->modalSubmitActionLabel('Продолжить')
            ->modalCancelActionLabel('Отмена')
            ->action(function (): void {
                if (! $this->record instanceof MarketSpace) {
                    return;
                }

                app(MarketSpaceStateGuard::class)->assertCanStartSharedUse($this->record);

                $this->replaceMountedAction('manage_shared_use');
            });
    }

    private function makeServiceStatusAction(string $actionClass): mixed
    {
        $isMaintenance = $this->record instanceof MarketSpace && $this->isMaintenanceSpace($this->record);

        return $actionClass::make($isMaintenance ? 'clear_service_status' : 'mark_service_status')
            ->label($isMaintenance ? 'Снять служебный статус' : 'Отметить как служебное')
            ->icon($isMaintenance ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-wrench-screwdriver')
            ->tooltip($isMaintenance
                ? 'Вернуть место в обычный режим без арендатора'
                : 'Перевести место в служебное и закрыть активные арендные связи')
            ->size('lg')
            ->outlined()
            ->color($isMaintenance ? 'gray' : 'warning')
            ->visible(fn (): bool => $this->record instanceof MarketSpace
                && $this->shouldShowServiceStatusAction())
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--secondary',
            ])
            ->requiresConfirmation()
            ->modalHeading($isMaintenance ? 'Снять служебный статус' : 'Отметить как служебное')
            ->modalDescription($isMaintenance
                ? 'Место станет обычным и получит статус «Свободно». Арендаторы и совместное использование автоматически не восстанавливаются.'
                : 'Место станет служебным. Активные привязки арендаторов и совместного использования будут закрыты.')
            ->modalSubmitActionLabel($isMaintenance ? 'Снять статус' : 'Перевести в служебное')
            ->modalCancelActionLabel('Отмена')
            ->form([
                \Filament\Forms\Components\Placeholder::make('parent_status_change_warning')
                    ->hiddenLabel()
                    ->content(fn (): ?HtmlString => $this->buildParentStatusChangeWarningHtml())
                    ->visible(fn (): bool => $this->buildParentStatusChangeWarningHtml() instanceof HtmlString),
            ])
            ->action(function () use ($isMaintenance): void {
                if (! $this->record instanceof MarketSpace) {
                    return;
                }

                if (! $isMaintenance) {
                    app(MarketSpaceStateGuard::class)->assertCanMarkAsService(
                        $this->record,
                        allowParentGroupDissolve: true,
                    );
                }

                $isMaintenance
                    ? $this->clearServiceStatus()
                    : $this->markRecordAsService();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sharedUseParticipantsFormState(): array
    {
        if (! $this->record instanceof MarketSpace) {
            return [];
        }

        return MarketSpaceTenantBinding::query()
            ->where('market_space_id', (int) $this->record->id)
            ->where('binding_type', 'shared_use')
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (MarketSpaceTenantBinding $binding): array => [
                'binding_id' => (int) $binding->id,
                'tenant_id' => (int) $binding->tenant_id,
                'area_sqm' => $binding->area_sqm !== null ? (float) $binding->area_sqm : null,
                'rent_rate' => $binding->rent_rate !== null ? (float) $binding->rent_rate : null,
                'started_at' => $binding->started_at?->format('Y-m-d H:i:s'),
                'share_note' => (string) ($binding->share_note ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     */
    private function syncSharedUseParticipants(array $participants): void
    {
        if (! $this->record instanceof MarketSpace) {
            return;
        }

        if ($this->isMaintenanceSpace($this->record) || $this->isGroupedSpace($this->record)) {
            throw ValidationException::withMessages([
                'participants' => 'Служебные и групповые места не могут быть совместными.',
            ]);
        }

        $activeBindings = MarketSpaceTenantBinding::query()
            ->where('market_space_id', (int) $this->record->id)
            ->where('binding_type', 'shared_use')
            ->whereNull('ended_at')
            ->get()
            ->keyBy('id');

        $normalized = [];
        $tenantIds = [];

        foreach ($participants as $index => $row) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            $bindingId = isset($row['binding_id']) && $row['binding_id'] !== '' ? (int) $row['binding_id'] : null;
            $startedAtRaw = trim((string) ($row['started_at'] ?? ''));
            $shareNote = trim((string) ($row['share_note'] ?? ''));
            $areaInput = $row['area_sqm'] ?? null;
            $rentInput = $row['rent_rate'] ?? null;

            if (
                $bindingId === null
                && $tenantId <= 0
                && trim((string) $areaInput) === ''
                && trim((string) $rentInput) === ''
                && $startedAtRaw === ''
                && $shareNote === ''
            ) {
                continue;
            }

            if ($tenantId <= 0) {
                throw ValidationException::withMessages([
                    "participants.{$index}.tenant_id" => 'Выберите арендатора.',
                ]);
            }

            if (in_array($tenantId, $tenantIds, true)) {
                throw ValidationException::withMessages([
                    "participants.{$index}.tenant_id" => 'Один и тот же арендатор не должен повторяться в активных участниках.',
                ]);
            }

            $tenantIds[] = $tenantId;

            if ($bindingId !== null && ! $activeBindings->has($bindingId)) {
                throw ValidationException::withMessages([
                    "participants.{$index}.tenant_id" => 'Участник не найден среди активных записей этого места.',
                ]);
            }

            if ($startedAtRaw === '') {
                throw ValidationException::withMessages([
                    "participants.{$index}.started_at" => 'Укажите дату начала действия.',
                ]);
            }

            $startedAt = \Illuminate\Support\Carbon::parse($startedAtRaw);
            $area = $this->normalizeNullableDecimal($areaInput, "participants.{$index}.area_sqm");
            $rentRate = $this->normalizeNullableDecimal($rentInput, "participants.{$index}.rent_rate");

            if ($bindingId === null && $area === null) {
                throw ValidationException::withMessages([
                    "participants.{$index}.area_sqm" => 'Укажите площадь, которую будет занимать арендатор.',
                ]);
            }

            if ($bindingId !== null) {
                /** @var MarketSpaceTenantBinding $binding */
                $binding = $activeBindings->get($bindingId);

                if ((int) $binding->tenant_id !== $tenantId) {
                    throw ValidationException::withMessages([
                        "participants.{$index}.tenant_id" => 'Для действующего участника нельзя менять арендатора в той же строке. Завершите участие и добавьте нового.',
                    ]);
                }

                $currentStartedAt = $binding->started_at?->copy();
                $currentArea = $binding->area_sqm !== null ? (float) $binding->area_sqm : null;
                $currentRentRate = $binding->rent_rate !== null ? (float) $binding->rent_rate : null;
                $hasTermsChange = $area !== $currentArea
                    || $rentRate !== $currentRentRate
                    || $shareNote !== trim((string) ($binding->share_note ?? ''))
                    || ! $currentStartedAt?->equalTo($startedAt);

                if ($hasTermsChange && $currentStartedAt !== null && $startedAt->lessThanOrEqualTo($currentStartedAt)) {
                    throw ValidationException::withMessages([
                        "participants.{$index}.started_at" => 'Для изменения площади или ставки укажите более позднюю дату начала действия, чтобы сохранить историю.',
                    ]);
                }
            }

            $normalized[] = [
                'binding_id' => $bindingId,
                'tenant_id' => $tenantId,
                'area_sqm' => $area,
                'rent_rate' => $rentRate,
                'started_at' => $startedAt,
                'share_note' => $shareNote,
            ];
        }

        $now = now();
        $keptBindingIds = array_values(array_filter(array_map(
            static fn (array $row): ?int => $row['binding_id'],
            $normalized,
        )));

        DB::transaction(function () use ($activeBindings, $normalized, $now, $keptBindingIds): void {
            foreach ($activeBindings as $bindingId => $binding) {
                if (in_array((int) $bindingId, $keptBindingIds, true)) {
                    continue;
                }

                $binding->forceFill([
                    'ended_at' => $now,
                    'resolution_reason' => 'shared_use_participation_ended',
                ])->save();
            }

            foreach ($normalized as $row) {
                if ($row['binding_id'] !== null) {
                    /** @var MarketSpaceTenantBinding $binding */
                    $binding = $activeBindings->get($row['binding_id']);
                    $currentStartedAt = $binding->started_at?->copy();
                    $currentArea = $binding->area_sqm !== null ? (float) $binding->area_sqm : null;
                    $currentRentRate = $binding->rent_rate !== null ? (float) $binding->rent_rate : null;
                    $currentShareNote = trim((string) ($binding->share_note ?? ''));
                    $hasTermsChange = $currentArea !== $row['area_sqm']
                        || $currentRentRate !== $row['rent_rate']
                        || $currentShareNote !== $row['share_note']
                        || ! $currentStartedAt?->equalTo($row['started_at']);

                    if (! $hasTermsChange) {
                        continue;
                    }

                    $binding->forceFill([
                        'ended_at' => $row['started_at']->copy()->subSecond(),
                        'resolution_reason' => 'shared_use_terms_updated',
                    ])->save();

                    MarketSpaceTenantBinding::query()->create([
                        'market_id' => (int) $this->record->market_id,
                        'market_space_id' => (int) $this->record->id,
                        'tenant_id' => $row['tenant_id'],
                        'tenant_contract_id' => $binding->tenant_contract_id,
                        'started_at' => $row['started_at'],
                        'ended_at' => null,
                        'area_sqm' => $row['area_sqm'],
                        'rent_rate' => $row['rent_rate'],
                        'share_note' => $row['share_note'] !== '' ? $row['share_note'] : null,
                        'binding_type' => 'shared_use',
                        'confidence' => 'medium',
                        'source' => 'manual_shared_use',
                        'created_by_user_id' => Filament::auth()->id(),
                        'resolution_reason' => 'shared_use_terms_updated',
                        'meta' => [
                            'previous_binding_id' => (int) $binding->id,
                        ],
                    ]);

                    continue;
                }

                MarketSpaceTenantBinding::query()->create([
                    'market_id' => (int) $this->record->market_id,
                    'market_space_id' => (int) $this->record->id,
                    'tenant_id' => $row['tenant_id'],
                    'tenant_contract_id' => null,
                    'started_at' => $row['started_at'],
                    'ended_at' => null,
                    'area_sqm' => $row['area_sqm'],
                    'rent_rate' => $row['rent_rate'],
                    'share_note' => $row['share_note'] !== '' ? $row['share_note'] : null,
                    'binding_type' => 'shared_use',
                    'confidence' => 'medium',
                    'source' => 'manual_shared_use',
                    'created_by_user_id' => Filament::auth()->id(),
                    'resolution_reason' => 'shared_space_use',
                    'meta' => [],
                ]);
            }
        });

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Совместное использование обновлено')
            ->body('Состав участников и их площади сохранены с историей изменений.')
            ->send();
    }

    private function normalizeNullableDecimal(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        if (! is_numeric($normalized)) {
            throw ValidationException::withMessages([
                $field => 'Введите число.',
            ]);
        }

        return (float) $normalized;
    }

    private function isVacantTenantSwitchAction(): bool
    {
        if (! $this->record instanceof MarketSpace) {
            return false;
        }

        return $this->record->fresh(['tenant', 'spaceGroupParent.tenant'])?->effectiveTenantId() === null;
    }

    private function makeTenantSwitchAction(string $actionClass): mixed
    {
        return $actionClass::make('switch_tenant')
            ->label(fn (): string => $this->isVacantTenantSwitchAction() ? 'Занять место' : 'Сменить арендатора')
            ->icon('heroicon-o-user-plus')
            ->tooltip(fn (): string => $this->isVacantTenantSwitchAction()
                ? 'Создать управленческую операцию занятия места с датой начала действия'
                : 'Создать управленческую операцию смены арендатора с датой вступления в силу')
            ->size('lg')
            ->outlined()
            ->color('warning')
            ->visible(fn (): bool => $this->record instanceof MarketSpace
                && ! $this->isMaintenanceSpace($this->record)
                && ! MarketSpaceResource::hasSharedUseTenants($this->record)
                && ! MarketSpaceResource::isSharedUseSourceSpace($this->record))
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--secondary',
            ])
            ->modalHeading(fn (): string => $this->isVacantTenantSwitchAction() ? 'Занять место' : 'Сменить арендатора')
            ->modalSubmitActionLabel(fn (): string => $this->isVacantTenantSwitchAction() ? 'Запланировать занятие' : 'Запланировать смену')
            ->modalCancelActionLabel('Отмена')
            ->form([
                \Filament\Forms\Components\Placeholder::make('tenant_switch_notice')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->buildTenantSwitchImpactHtml()),
                \Filament\Forms\Components\Placeholder::make('tenant_switch_warnings')
                    ->hiddenLabel()
                    ->content(fn (): ?HtmlString => $this->buildTenantSwitchWarningsHtml())
                    ->visible(fn (): bool => $this->buildTenantSwitchWarningsHtml() instanceof HtmlString),
                \Filament\Forms\Components\Select::make('target_tenant_id')
                    ->label(fn (): string => $this->isVacantTenantSwitchAction() ? 'Арендатор' : 'Новый арендатор')
                    ->options(function (): array {
                        if (! $this->record instanceof MarketSpace) {
                            return [];
                        }

                        return Tenant::query()
                            ->where('market_id', (int) $this->record->market_id)
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->placeholder('Выберите арендатора'),
                \Filament\Forms\Components\DateTimePicker::make('effective_at')
                    ->label('Дата начала действия')
                    ->seconds(false)
                    ->required()
                    ->default(fn (): \Illuminate\Support\Carbon => now()),
                \Filament\Forms\Components\Textarea::make('reason')
                    ->label(fn (): string => $this->isVacantTenantSwitchAction() ? 'Основание' : 'Причина')
                    ->rows(2)
                    ->required()
                    ->maxLength(1000)
                    ->placeholder(fn (): string => $this->isVacantTenantSwitchAction()
                        ? 'Кратко укажите основание занятия места.'
                        : 'Кратко укажите причину смены арендатора.'),
            ])
            ->action(function (array $data): void {
                $isOccupyAction = $this->isVacantTenantSwitchAction();
                $targetTenant = Tenant::query()->find((int) ($data['target_tenant_id'] ?? 0));

                if (! $targetTenant instanceof Tenant) {
                    throw ValidationException::withMessages([
                        'target_tenant_id' => 'Выберите корректного арендатора.',
                    ]);
                }

                $operation = app(TenantSwitchPlanner::class)->plan(
                    $this->record->fresh(),
                    $targetTenant,
                    (string) ($data['effective_at'] ?? ''),
                    (string) ($data['reason'] ?? ''),
                    Filament::auth()->id(),
                );

                $this->record->refresh();
                $this->fillForm();

                $effectiveAtLabel = $operation->effective_at
                    ? $operation->effective_at->timezone((string) ($operation->effective_tz ?: config('app.timezone', 'UTC')))->format('d.m.Y H:i')
                    : '—';

                Notification::make()
                    ->success()
                    ->title($isOccupyAction ? 'Занятие места запланировано' : 'Смена арендатора запланирована')
                    ->body(
                        ((bool) ($operation->payload['detach_from_group'] ?? false))
                            ? 'Место будет выведено из группы и перейдёт к новому арендатору с '.$effectiveAtLabel.'.'
                            : ($isOccupyAction
                                ? 'Арендатор вступит в силу с '.$effectiveAtLabel.'.'
                                : 'Новый арендатор вступит в силу с '.$effectiveAtLabel.'.')
                    )
                    ->send();
            });
    }

    private function makeRegroupAction(string $actionClass): mixed
    {
        $isChild = fn (): bool => $this->record instanceof MarketSpace
            && ! $this->isMaintenanceSpace($this->record)
            && (string) ($this->record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD
            && filled($this->record->space_group_parent_id);

        $isOrdinary = fn (): bool => $this->record instanceof MarketSpace
            && ! $this->isMaintenanceSpace($this->record)
            && (
                (string) ($this->record->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) === MarketSpace::SPACE_GROUP_ROLE_NONE
                || (
                    (string) ($this->record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD
                    && blank($this->record->space_group_parent_id)
                )
            );

        return $actionClass::make('regroup_child')
            ->label(fn (): string => $isChild() ? 'Перенести в группу' : 'Добавить в группу')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->tooltip(fn (): string => $isChild()
                ? 'Сменить родительскую группу и номер внутри группы'
                : 'Сделать место частью группы с выбором родительской группы и номера'
            )
            ->size('lg')
            ->outlined()
            ->color('primary')
            ->visible(fn (): bool => ($isChild() || $isOrdinary())
                && ! MarketSpaceResource::hasSharedUseTenants($this->record)
                && ! MarketSpaceResource::isSharedUseSourceSpace($this->record))
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--primary',
            ])
            ->modalHeading(fn (): string => $isChild() ? 'Перенести место в другую группу' : 'Добавить место в группу')
            ->modalSubmitActionLabel(fn (): string => $isChild() ? 'Перенести' : 'Добавить в группу')
            ->modalCancelActionLabel('Отмена')
            ->form([
                \Filament\Forms\Components\Placeholder::make('regroup_notice')
                    ->hiddenLabel()
                    ->content(fn (): string => $isChild()
                        ? 'Будет изменена только группировка места. Арендатор, договоры, начисления и задолженности не переносятся и не копируются.'
                        : 'Место станет частью выбранной группы. Сразу выберите родительскую группу и номер внутри группы — промежуточное состояние без группы не создаётся.'
                    ),
                \Filament\Forms\Components\Select::make('target_parent_id')
                    ->label(fn (): string => $isChild() ? 'Новая группа' : 'Родительская группа')
                    ->options(fn (): array => MarketSpaceResource::parentGroupOptionsForMarket(
                        $this->record?->market_id ? (int) $this->record->market_id : null,
                        $isChild() && $this->record?->space_group_parent_id ? (int) $this->record->space_group_parent_id : null,
                    ))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->placeholder('Выберите группу')
                    ->helperText(fn (): string => $isChild()
                        ? 'Текущая группа не показывается в списке, чтобы избежать псевдо-переноса без изменений.'
                        : 'Выберите группу, в которую нужно добавить это место.'
                    ),
                \Filament\Forms\Components\TextInput::make('target_slot')
                    ->label('Номер внутри группы')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (): ?string => $isChild() && $this->record?->space_group_slot ? (string) $this->record->space_group_slot : null)
                    ->helperText('Используется для отображения места внутри родительской группы. Например: 7.'),
            ])
            ->action(function (array $data) use ($isChild, $isOrdinary): void {
                $targetParent = MarketSpace::query()->find((int) ($data['target_parent_id'] ?? 0));

                if (! $targetParent instanceof MarketSpace) {
                    throw ValidationException::withMessages([
                        'target_parent_id' => 'Выберите корректную группу.',
                    ]);
                }

                $space = $this->record->fresh();

                if (! $space instanceof MarketSpace) {
                    throw ValidationException::withMessages([
                        'target_parent_id' => 'Торговое место не найдено.',
                    ]);
                }

                if ($isOrdinary()) {
                    $result = app(SpaceGroupManager::class)->addToGroup(
                        $space,
                        $targetParent,
                        (string) ($data['target_slot'] ?? ''),
                    );

                    $title = 'Место добавлено в группу';
                    $defaultBody = 'Место стало частью выбранной группы.';
                } elseif ($isChild()) {
                    $result = app(SpaceGroupManager::class)->regroupChild(
                        $space,
                        $targetParent,
                        (string) ($data['target_slot'] ?? ''),
                    );

                    $title = 'Место перенесено в другую группу';
                    $defaultBody = 'Связь с группой обновлена.';
                } else {
                    throw ValidationException::withMessages([
                        'target_parent_id' => 'Это действие доступно только для обычного места или места в группе.',
                    ]);
                }

                $this->record->refresh();
                $this->fillForm();

                $renamedParents = collect($result['renamed_parents'] ?? [])
                    ->map(function (array $item): string {
                        return $item['old_number'].' → '.$item['new_number'];
                    })
                    ->values();

                $body = $renamedParents->isNotEmpty()
                    ? 'Переименованы группы: '.$renamedParents->implode('; ')
                    : $defaultBody;

                Notification::make()
                    ->success()
                    ->title($title)
                    ->body($body)
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        $marketSpaceId = $this->record?->id ? (int) $this->record->id : null;
        $mapUrl = null;
        $isMapLinked = false;
        $mapStatus = 'Торговое место не привязано к объектам карты.';

        if ($marketSpaceId) {
            $page = 1;
            $version = 1;
            $bbox = null;

            if (Schema::hasTable('market_space_map_shapes')) {
                $shape = MarketSpaceMapShape::query()
                    ->where('market_id', (int) $this->record->market_id)
                    ->where('market_space_id', $marketSpaceId)
                    ->where('is_active', true)
                    ->orderByDesc('id')
                    ->first(['page', 'version', 'bbox_x1', 'bbox_y1', 'bbox_x2', 'bbox_y2']);

                if ($shape) {
                    $isMapLinked = true;
                    $mapStatus = 'Торговое место привязано к карте.';
                    $page = (int) ($shape->page ?? 1);
                    $version = (int) ($shape->version ?? 1);

                    $bbox = [
                        'bbox_x1' => $shape->bbox_x1 !== null ? (float) $shape->bbox_x1 : null,
                        'bbox_y1' => $shape->bbox_y1 !== null ? (float) $shape->bbox_y1 : null,
                        'bbox_x2' => $shape->bbox_x2 !== null ? (float) $shape->bbox_x2 : null,
                        'bbox_y2' => $shape->bbox_y2 !== null ? (float) $shape->bbox_y2 : null,
                    ];
                } elseif ((string) ($this->record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
                    $childShape = MarketSpaceMapShape::query()
                        ->join('market_spaces', 'market_spaces.id', '=', 'market_space_map_shapes.market_space_id')
                        ->where('market_space_map_shapes.market_id', (int) $this->record->market_id)
                        ->where('market_spaces.market_id', (int) $this->record->market_id)
                        ->where('market_spaces.space_group_parent_id', $marketSpaceId)
                        ->where('market_spaces.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                        ->where('market_space_map_shapes.is_active', true)
                        ->orderByDesc('market_space_map_shapes.id')
                        ->first([
                            'market_space_map_shapes.page',
                            'market_space_map_shapes.version',
                        ]);

                    if ($childShape) {
                        $isMapLinked = true;
                        $mapStatus = 'Группа отображается на карте через дочерние места.';
                        $page = (int) ($childShape->page ?? 1);
                        $version = (int) ($childShape->version ?? 1);
                        $bbox = null;
                    }
                }
            }

            if ($isMapLinked) {
                $params = [
                    'market_space_id' => $marketSpaceId,
                    'page' => $page,
                    'version' => $version,
                    'return_url' => request()->fullUrl(),
                ];

                if ($bbox
                    && $bbox['bbox_x1'] !== null
                    && $bbox['bbox_y1'] !== null
                    && $bbox['bbox_x2'] !== null
                    && $bbox['bbox_y2'] !== null
                ) {
                    $params = array_merge($params, $bbox);
                }

                $params['return_url'] = request()->fullUrl();
                $mapUrl = route('filament.admin.market-map', $params);
            }
        }

        if ($this->isReadOnly()) {
            $actionClass = class_exists(\Filament\Actions\Action::class)
                ? \Filament\Actions\Action::class
                : \Filament\Pages\Actions\Action::class;

            $actions[] = $actionClass::make('readonly_hint')
                ->label("\u{0422}\u{043E}\u{043B}\u{044C}\u{043A}\u{043E} \u{043F}\u{0440}\u{043E}\u{0441}\u{043C}\u{043E}\u{0442}\u{0440}")
                ->color('gray')
                ->disabled()
                ->action(fn () => null);

            if ($isMapLinked) {
                $actions[] = $actionClass::make('openMap')
                    ->label("\u{041F}\u{043E}\u{043A}\u{0430}\u{0437}\u{0430}\u{0442}\u{044C} \u{043D}\u{0430} \u{043A}\u{0430}\u{0440}\u{0442}\u{0435}")
                    ->icon('heroicon-o-map')
                    ->tooltip($mapStatus)
                    ->size('lg')
                    ->outlined()
                    ->color('primary')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--primary',
                    ])
                    ->url($mapUrl, shouldOpenInNewTab: true);
            }

            return $actions;
        }

        if (class_exists(\Filament\Actions\Action::class)) {
            $actions[] = \Filament\Actions\Action::make('active_state')
                ->view('filament.resources.market-spaces.partials.active-state-toggle')
                ->viewData([
                    'isActive' => (bool) ($this->record?->is_active ?? false),
                ]);
            $actions[] = $this->makeMarkSpaceFreeAction();
            $actions[] = $this->makeServiceStatusAction(\Filament\Actions\Action::class);
            $actions[] = $this->makeStartSharedUseAction(\Filament\Actions\Action::class);
            $actions[] = $this->makeSharedUseManageAction(\Filament\Actions\Action::class);
            $actions[] = $this->makeTenantSwitchAction(\Filament\Actions\Action::class);
            $actions[] = $this->makeRegroupAction(\Filament\Actions\Action::class);

            $actions[] = \Filament\Actions\Action::make('deactivate_precheck')
                ->label('Упразднить место')
                ->icon('heroicon-o-archive-box')
                ->tooltip('Проверка связей перед деактивацией')
                ->action(fn () => $this->deactivateMarketSpaceAfterPrecheck())
                ->size('lg')
                ->outlined()
                ->color('gray')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--secondary',
                ])
                ->modalHeading('Упразднить место')
                ->modalSubmitAction(fn ($action) => $this->canDeactivateAfterPrecheck()
                    ? $action
                        ->label('Упразднить место')
                        ->color('danger')
                    : false)
                ->modalCancelActionLabel('Закрыть')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (): View => view(
                    'filament.resources.market-spaces.partials.deactivate-precheck-modal',
                    array_merge($this->buildDeactivatePrecheckViewData(), [
                        'mapUrl' => $mapUrl,
                        'tenantUrl' => $this->record?->tenant_id
                            ? \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => (int) $this->record->tenant_id])
                            : null,
                        'historyUrl' => MarketSpaceResource::getUrl('edit', ['record' => $this->record]).'?tab=istoria::data::tab',
                    ]),
                ));

            if ($isMapLinked) {
                $actions[] = \Filament\Actions\Action::make('openMap')
                    ->label('Показать на карте')
                    ->icon('heroicon-o-map')
                    ->tooltip($mapStatus)
                    ->size('lg')
                    ->outlined()
                    ->color('primary')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--primary',
                    ])
                    ->url($mapUrl, shouldOpenInNewTab: true);
            } else {
                $actions[] = \Filament\Actions\Action::make('mapStatus')
                    ->label('Нет карты')
                    ->icon('heroicon-o-link-slash')
                    ->tooltip($mapStatus)
                    ->disabled()
                    ->size('lg')
                    ->outlined()
                    ->color('gray')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--secondary',
                    ]);
            }
        } elseif (class_exists(\Filament\Pages\Actions\Action::class)) {
            $actions[] = \Filament\Pages\Actions\Action::make('active_state')
                ->view('filament.resources.market-spaces.partials.active-state-toggle')
                ->viewData([
                    'isActive' => (bool) ($this->record?->is_active ?? false),
                ]);
            $actions[] = $this->makeMarkSpaceFreeAction();
            $actions[] = $this->makeServiceStatusAction(\Filament\Pages\Actions\Action::class);
            $actions[] = $this->makeStartSharedUseAction(\Filament\Pages\Actions\Action::class);
            $actions[] = $this->makeSharedUseManageAction(\Filament\Pages\Actions\Action::class);
            $actions[] = $this->makeTenantSwitchAction(\Filament\Pages\Actions\Action::class);
            $actions[] = $this->makeRegroupAction(\Filament\Pages\Actions\Action::class);

            $actions[] = \Filament\Pages\Actions\Action::make('deactivate_precheck')
                ->label('Упразднить место')
                ->icon('heroicon-o-archive-box')
                ->tooltip('Проверка связей перед деактивацией')
                ->action(fn () => $this->deactivateMarketSpaceAfterPrecheck())
                ->size('lg')
                ->outlined()
                ->color('gray')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--secondary',
                ])
                ->modalHeading('Упразднить место')
                ->modalSubmitAction(fn ($action) => $this->canDeactivateAfterPrecheck()
                    ? $action
                        ->label('Упразднить место')
                        ->color('danger')
                    : false)
                ->modalCancelActionLabel('Закрыть')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (): View => view(
                    'filament.resources.market-spaces.partials.deactivate-precheck-modal',
                    array_merge($this->buildDeactivatePrecheckViewData(), [
                        'mapUrl' => $mapUrl,
                        'tenantUrl' => $this->record?->tenant_id
                            ? \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => (int) $this->record->tenant_id])
                            : null,
                        'historyUrl' => MarketSpaceResource::getUrl('edit', ['record' => $this->record]).'?tab=istoria::data::tab',
                    ]),
                ));

            if ($isMapLinked) {
                $actions[] = \Filament\Pages\Actions\Action::make('openMap')
                    ->label('Показать на карте')
                    ->icon('heroicon-o-map')
                    ->tooltip($mapStatus)
                    ->size('lg')
                    ->outlined()
                    ->color('primary')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--primary',
                    ])
                    ->url($mapUrl, shouldOpenInNewTab: true);
            } else {
                $actions[] = \Filament\Pages\Actions\Action::make('mapStatus')
                    ->label('Нет карты')
                    ->icon('heroicon-o-link-slash')
                    ->tooltip($mapStatus)
                    ->disabled()
                    ->size('lg')
                    ->outlined()
                    ->color('gray')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--secondary',
                    ]);
            }
        }

        return $actions;
    }

    protected function getHeroActions(): array
    {
        return array_values(array_filter(
            $this->getCachedHeaderActions(),
            static fn ($action): bool => ! method_exists($action, 'getName')
                || ! in_array($action->getName(), ['delete', 'delete_with_shapes'], true),
        ));
    }

    protected function getFormActions(): array
    {
        if ($this->isReadOnly()) {
            return [];
        }

        return parent::getFormActions();
    }

    protected function isReadOnly(): bool
    {
        return ! MarketSpaceResource::canEdit($this->record);
    }

    private function abortIfReadOnly(): void
    {
        if ($this->isReadOnly()) {
            abort(403);
        }
    }

    protected function getDangerZoneActions(): array
    {
        $user = Filament::auth()->user();

        if (! $this->record || ! $user || ! $user->isSuperAdmin()) {
            return [];
        }

        $canDelete = MarketSpaceResource::canDelete($this->record);

        if ($canDelete) {
            return [$this->makeDangerDeleteAction()];
        }

        if (MarketSpaceResource::canDeleteWithMapShapeCascade($this->record)) {
            return [$this->makeDangerDeleteWithShapesAction()];
        }

        return [];
    }

    protected function makeDangerDeleteAction(): mixed
    {
        $actionClass = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::class
            : \Filament\Pages\Actions\Action::class;

        return $actionClass::make('delete_place')
            ->label('Удалить место')
            ->icon('heroicon-o-trash')
            ->tooltip('Безвозвратно удалить карточку места')
            ->size('lg')
            ->color('danger')
            ->modalHeading('Удалить место')
            ->modalSubmitActionLabel('Удалить место')
            ->modalCancelActionLabel('Отмена')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalDescription('Это необратимое действие. Карточка места будет удалена из системы полностью. Используйте этот сценарий только если место нужно убрать окончательно, а не просто упразднить.')
            ->form([
                Checkbox::make('confirm_delete_place')
                    ->label('Подтверждаю полное удаление места без возможности восстановления')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->deleteMarketSpacePermanently($data);
            })
            ->extraAttributes([
                'class' => 'market-space-danger-action',
            ]);
    }

    protected function makeDangerDeleteWithShapesAction(): mixed
    {
        $actionClass = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::class
            : \Filament\Pages\Actions\Action::class;

        return $actionClass::make('delete_with_shapes')
            ->label('Удалить место')
            ->icon('heroicon-o-trash')
            ->tooltip('Удалить пустое место вместе с фигурой на карте')
            ->size('lg')
            ->color('danger')
            ->extraAttributes([
                'class' => 'market-space-danger-action',
            ])
            ->modalHeading('Удалить место и фигуру')
            ->modalSubmitActionLabel('Удалить место')
            ->modalCancelActionLabel('Отмена')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalDescription('Это необратимое действие. Место будет удалено вместе с привязанной фигурой на карте. История операций останется как аудит.')
            ->form([
                Checkbox::make('confirm_delete_with_shape')
                    ->label('Подтверждаю удаление места и фигуры на карте')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->deleteMarketSpaceWithShapes($data);
            });
    }

    private function makeMarkSpaceFreeAction(): mixed
    {
        $actionClass = class_exists(\Filament\Actions\Action::class)
            ? \Filament\Actions\Action::class
            : \Filament\Pages\Actions\Action::class;

        return $actionClass::make('mark_space_free')
            ->label('Освободить место')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->tooltip('Проверка связей перед отметкой места как свободного')
            ->size('lg')
            ->outlined()
            ->color('warning')
            ->visible(fn (): bool => $this->shouldShowMarkSpaceFreeAction())
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--secondary',
            ])
            ->modalHeading('Освободить место')
            ->modalSubmitActionLabel('Освободить')
            ->modalCancelActionLabel('Отмена')
            ->modalWidth(Width::FiveExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->form([
                \Filament\Forms\Components\Placeholder::make('mark_space_free_notice')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $this->buildMarkSpaceFreePrecheckModalContent()),
                \Filament\Forms\Components\Placeholder::make('mark_space_free_parent_status_warning')
                    ->hiddenLabel()
                    ->content(fn (): ?HtmlString => $this->buildParentStatusChangeWarningHtml())
                    ->visible(fn (): bool => $this->buildParentStatusChangeWarningHtml() instanceof HtmlString),
                \Filament\Forms\Components\Textarea::make('reason')
                    ->label('Причина')
                    ->rows(2)
                    ->required()
                    ->maxLength(1000)
                    ->placeholder('Укажите причину, почему место считается свободным.'),
                \Filament\Forms\Components\Checkbox::make('confirm_contracts_close')
                    ->label('Завершить истёкшие договоры')
                    ->visible(fn (): bool => $this->buildMarkSpaceFreePrecheckData()['contracts'] !== [])
                    ->helperText('Система завершит только истёкшие договоры. Активные договоры потребуют ручного завершения.'),
                \Filament\Forms\Components\Checkbox::make('confirm_accruals_warning')
                    ->label('Подтвердить проверку текущих начислений')
                    ->visible(fn (): bool => $this->buildMarkSpaceFreePrecheckData()['currentAccrualsCount'] > 0)
                    ->helperText('Текущие начисления останутся на месте как финансовая история.'),
            ])
            ->action(function (array $data): void {
                $this->markSpaceFreeAfterPrecheck($data);
            });
    }

    /**
     * Содержимое модального окна pre-check.
     */
    private function buildMarkSpaceFreePrecheckModalContent(): HtmlString
    {
        if (! $this->record instanceof MarketSpace) {
            return new HtmlString('<div>Нет данных для проверки.</div>');
        }

        $precheck = $this->buildMarkSpaceFreePrecheckData();

        $html = '<div style="display:grid;gap:12px;">';

        // Статус
        if ($precheck['canMarkFree']) {
            $html .= '<div style="display:grid;gap:6px;padding:12px 14px;border:1px solid #86efac;border-radius:12px;background:#f0fdf4;color:#166534;">';
            $html .= '<div style="font-size:13px;font-weight:700;">Блокирующих связей не найдено</div>';
            $html .= '<div style="font-size:12px;line-height:1.45;">Место можно отметить как свободное.</div>';
            $html .= '</div>';
        } else {
            $html .= '<div style="display:grid;gap:6px;padding:12px 14px;border:1px solid #fca5a5;border-radius:12px;background:#fef2f2;color:#991b1b;">';
            $html .= '<div style="font-size:13px;font-weight:700;">Найдены блокирующие связи</div>';
            $html .= '<div style="font-size:12px;line-height:1.45;">Сначала завершите активные связи вручную.</div>';
            $html .= '</div>';
        }

        // Договоры
        if ($precheck['contracts'] !== []) {
            $html .= '<div style="display:grid;gap:6px;">';
            $html .= '<div style="font-size:13px;font-weight:700;color:#0f172a;">Договоры: '.count($precheck['contracts']).'</div>';
            $html .= '<div style="display:grid;gap:4px;">';
            foreach ($precheck['contracts'] as $contract) {
                $statusColor = $contract['is_expired'] ? '#86efac' : '#fca5a5';
                $statusText = $contract['is_expired'] ? 'истёк' : 'активен';
                $html .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">';
                $html .= '<div style="font-size:12px;color:#334155;">'.e($contract['number']).' · '.e($contract['tenant_name']).'</div>';
                $html .= '<span style="font-size:11px;padding:2px 8px;border-radius:999px;background:'.$statusColor.';color:#0f172a;">'.$statusText.'</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
            if ($precheck['contractsUrl']) {
                $html .= '<a href="'.e($precheck['contractsUrl']).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;margin-top:4px;">Все договоры →</a>';
            }
            $html .= '</div>';
        }

        // Начисления
        if ($precheck['accruals'] !== []) {
            $html .= '<div style="display:grid;gap:6px;">';
            $html .= '<div style="font-size:13px;font-weight:700;color:#0f172a;">Начисления: '.count($precheck['accruals']).'</div>';
            $html .= '<div style="display:grid;gap:4px;">';
            foreach (array_slice($precheck['accruals'], 0, 5) as $accrual) {
                $statusColor = $accrual['is_current'] ? '#fde68a' : '#e5e7eb';
                $statusText = $accrual['is_current'] ? 'текущий' : 'прошедший';
                $html .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;">';
                $html .= '<div style="font-size:12px;color:#334155;">'.e($accrual['period']).' · '.number_format($accrual['total'], 0, ',', ' ').' ₽</div>';
                $html .= '<span style="font-size:11px;padding:2px 8px;border-radius:999px;background:'.$statusColor.';color:#0f172a;">'.$statusText.'</span>';
                $html .= '</div>';
            }
            if (count($precheck['accruals']) > 5) {
                $html .= '<div style="font-size:11px;color:#64748b;">Ещё '.(count($precheck['accruals']) - 5).' записей...</div>';
            }
            $html .= '</div>';
            if ($precheck['accrualsUrl']) {
                $html .= '<a href="'.e($precheck['accrualsUrl']).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;margin-top:4px;">Все начисления →</a>';
            }
            $html .= '</div>';
        }

        // Предупреждения
        if ($precheck['warnings'] !== []) {
            $html .= '<div style="display:grid;gap:4px;padding:12px 14px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;">';
            $html .= '<div style="font-size:12px;font-weight:700;color:#92400e;">Предупреждения:</div>';
            foreach ($precheck['warnings'] as $warning) {
                $html .= '<div style="font-size:12px;color:#92400e;">• '.e($warning).'</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    private function resolveSpaceHeading(): string|Htmlable
    {
        $displayName = trim((string) ($this->record?->display_name ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $number = trim((string) ($this->record?->number ?? ''));
        if ($number !== '') {
            return 'Место '.$number;
        }

        return 'Торговое место';
    }

    private function resolveStatusLabel(): ?string
    {
        if (! $this->record) {
            return null;
        }

        $source = $this->record->effectiveOccupancySource();
        $sourceSpace = $this->record->effectiveOccupancySourceSpace();
        $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

        if ($source === 'parent') {
            if ($sourceLabel === '') {
                $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
            }
            if ($sourceLabel === '') {
                $sourceLabel = '#'.(int) ($sourceSpace?->id ?? 0);
            }

            return 'Входит в группу: '.$sourceLabel;
        }

        if (MarketSpaceResource::hasSharedUseTenants($this->record)) {
            return 'Занято совместно';
        }

        if ($source === 'direct') {
            return 'Занято напрямую';
        }

        $state = $this->record->status;

        if ($state === 'free') {
            $state = 'vacant';
        }

        return match ($state) {
            'vacant' => 'Свободно',
            'occupied' => 'Занято',
            'reserved' => 'Зарезервировано',
            'maintenance' => 'Служебное место',
            default => $state,
        };
    }

    private function resolveStatusColor(): string
    {
        if (! $this->record) {
            return 'gray';
        }

        $source = $this->record->effectiveOccupancySource();

        if ($source === 'parent' || $source === 'direct') {
            return 'success';
        }

        $state = $this->record->status;

        if ($state === 'free') {
            $state = 'vacant';
        }

        return match ($state) {
            'occupied' => 'success',
            'vacant' => 'danger',
            'reserved' => 'warning',
            'maintenance' => 'gray',
            default => 'gray',
        };
    }

    private function isMaintenanceSpace(?MarketSpace $space): bool
    {
        return $space instanceof MarketSpace && (string) ($space->status ?? '') === 'maintenance';
    }

    private function isGroupedSpace(?MarketSpace $space): bool
    {
        if (! $space instanceof MarketSpace) {
            return false;
        }

        return (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_NONE
            || filled($space->space_group_parent_id);
    }

    private function isChildGroupSpace(?MarketSpace $space): bool
    {
        return $space instanceof MarketSpace
            && (string) ($space->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD;
    }

    private function isSharedUseSpace(?MarketSpace $space): bool
    {
        return $space instanceof MarketSpace
            && (
                MarketSpaceResource::hasSharedUseTenants($space)
                || MarketSpaceResource::isSharedUseSourceSpace($space)
            );
    }

    private function shouldShowMarkSpaceFreeAction(): bool
    {
        if (! $this->record instanceof MarketSpace) {
            return false;
        }

        if ($this->isMaintenanceSpace($this->record) || $this->isSharedUseSpace($this->record)) {
            return false;
        }

        $status = (string) ($this->record->status ?? '');

        return $this->record->isEffectivelyOccupied()
            || in_array($status === 'free' ? 'vacant' : $status, ['occupied', 'reserved'], true);
    }

    private function shouldShowServiceStatusAction(): bool
    {
        if (! $this->record instanceof MarketSpace) {
            return false;
        }

        if ($this->isMaintenanceSpace($this->record)) {
            return true;
        }

        return ! $this->isSharedUseSpace($this->record)
            && ! $this->isChildGroupSpace($this->record);
    }

    private function markRecordAsService(): void
    {
        if (! $this->record instanceof MarketSpace) {
            return;
        }

        app(MarketSpaceStateGuard::class)->assertCanMarkAsService(
            $this->record,
            allowParentGroupDissolve: true,
        );

        $affectedChildren = $this->parentStatusChangeAffectedChildren();
        $spaceId = (int) $this->record->id;
        $marketId = (int) $this->record->market_id;
        $now = now();

        DB::transaction(function () use ($marketId, $spaceId, $now): void {
            DB::table('market_space_tenant_bindings')
                ->where('market_id', $marketId)
                ->where('market_space_id', $spaceId)
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => $now,
                    'updated_at' => $now,
                    'resolution_reason' => 'maintenance_space_reconciled',
                ]);

            $space = MarketSpace::query()->whereKey($spaceId)->first();
            if ($space instanceof MarketSpace) {
                $space->forceFill([
                    'status' => 'maintenance',
                    'tenant_id' => null,
                    'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
                    'space_group_parent_id' => null,
                    'space_group_slot' => null,
                    'space_group_token' => null,
                    'updated_at' => $now,
                ])->save();
            }
        });

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Место отмечено как служебное')
            ->body('Активные арендные связи закрыты.'.$this->buildParentStatusChangeNotificationSuffix($affectedChildren))
            ->send();
    }

    private function clearServiceStatus(): void
    {
        if (! $this->record instanceof MarketSpace) {
            return;
        }

        $affectedChildren = $this->parentStatusChangeAffectedChildren();

        $this->record->forceFill([
            'status' => 'vacant',
            'tenant_id' => null,
            'updated_at' => now(),
        ])->save();

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Служебный статус снят')
            ->body('Место переведено в статус «Свободно».'.$this->buildParentStatusChangeNotificationSuffix($affectedChildren))
            ->send();
    }

    /**
     * @return list<string>
     */
    private function parentStatusChangeAffectedChildren(): array
    {
        if (! $this->record instanceof MarketSpace) {
            return [];
        }

        if ((string) ($this->record->space_group_role ?? '') !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return [];
        }

        return MarketSpace::query()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->where('space_group_parent_id', (int) $this->record->id)
            ->orderByRaw('COALESCE(space_group_slot, number, display_name, id::text)')
            ->get(['id', 'number', 'display_name'])
            ->map(static function (MarketSpace $space): string {
                $number = trim((string) ($space->number ?? ''));
                $displayName = trim((string) ($space->display_name ?? ''));

                if ($number !== '' && $displayName !== '' && $displayName !== $number) {
                    return $number.' ('.$displayName.')';
                }

                return $number !== '' ? $number : ($displayName !== '' ? $displayName : ('#'.(int) $space->id));
            })
            ->values()
            ->all();
    }

    private function buildParentStatusChangeWarningHtml(): ?HtmlString
    {
        $children = $this->parentStatusChangeAffectedChildren();
        if ($children === []) {
            return null;
        }

        $items = implode('', array_map(
            static fn (string $label): string => '<li>'.e($label).'</li>',
            $children,
        ));

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #f59e0b;border-radius:12px;background:#fff7ed;">'
            .'<div style="font-size:13px;line-height:1.45;color:#9a3412;"><strong>Предупреждение.</strong> При смене статуса parent-группы child-места будут разгруппированы и станут обычными местами.</div>'
            .'<div style="font-size:12px;line-height:1.5;color:#7c2d12;"><strong>Будут разгруппированы:</strong><ul style="margin:6px 0 0 18px;padding:0;">'.$items.'</ul></div>'
            .'</div>'
        );
    }

    /**
     * @param  list<string>  $children
     */
    private function buildParentStatusChangeNotificationSuffix(array $children): string
    {
        if ($children === []) {
            return '';
        }

        return ' Разгруппированы child-места: '.implode(', ', $children).'.';
    }
}
