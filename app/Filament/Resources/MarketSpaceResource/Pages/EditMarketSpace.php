<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Services\MarketSpaces\SpaceGroupManager;
use App\Services\MarketSpaces\TenantSwitchPlanner;
use App\Models\Tenant;
use App\Domain\Operations\OperationType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Filament\Support\Enums\Width;

class EditMarketSpace extends BaseEditRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = null;

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

    public function toggleMarketSpaceActiveState(): void
    {
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
                    ? 'Арендатор: ' . $currentTenantName
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
            $parentLabel = $parentLabel !== '' ? $parentLabel : ('#' . (int) ($this->record->space_group_parent_id ?? 0));

            $item = $makeItem(
                'Наследуемый арендатор (через группу)',
                1,
                'Блокирует',
                filled($effectiveTenantName)
                    ? 'Арендатор группы ' . e($parentLabel) . ': ' . $effectiveTenantName
                    : 'Место занято через родительскую группу ' . e($parentLabel)
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

    private function buildTenantSwitchImpactHtml(): HtmlString
    {
        if (! $this->record instanceof MarketSpace) {
            return new HtmlString('—');
        }

        $record = $this->record->fresh(['tenant', 'spaceGroupParent.tenant', 'spaceGroupChildren']);
        $effectiveTenantName = trim((string) ($record?->effectiveTenantName() ?? ''));
        $effectiveTenantName = $effectiveTenantName !== '' ? $effectiveTenantName : '—';
        $stateLabel = 'Прямое место';
        $stateHint = 'Смена затронет карточку места с указанной даты.';

        if ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD && filled($record->space_group_parent_id)) {
            $parentLabel = trim((string) ($record->spaceGroupParent?->number ?? ''));
            $parentLabel = $parentLabel !== '' ? $parentLabel : ('#' . (int) $record->space_group_parent_id);
            $stateLabel = 'Место в группе ' . e($parentLabel);
            $stateHint = 'В дату вступления место выйдет из группы и получит прямого арендатора.';
        } elseif ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();
            $stateLabel = 'Группа мест';
            $stateHint = 'Child-места продолжат наследовать арендатора группы. Связанных мест: ' . $childrenCount . '.';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #d7e3f4;border-radius:12px;background:#f8fbff;">'
            . '<div style="font-size:13px;line-height:1.45;color:#334155;"><strong>Текущий арендатор:</strong> ' . e($effectiveTenantName) . '</div>'
            . '<div style="font-size:13px;line-height:1.45;color:#334155;"><strong>Сценарий:</strong> ' . $stateLabel . '</div>'
            . '<div style="font-size:12px;line-height:1.5;color:#475569;">' . $stateHint . ' До этой даты карточка места не меняется.</div>'
            . '</div>'
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
            $links[] = '<a href="' . e(\App\Filament\Resources\TenantContractResource::getUrl('index', [
                'marketSpaceId' => $recordId,
                'tab' => 'all',
            ])) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;">Договоры</a>';
        }

        if ($accrualCount > 0) {
            $links[] = '<a href="' . e(\App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index', [
                'marketSpaceId' => $recordId,
                'tab' => 'all',
            ])) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:999px;color:#1d4ed8;font-weight:600;text-decoration:none;background:#fff;">Начисления</a>';
        }

        $rows = [];

        if ($contractCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Договоры: ' . $contractCount . '</span>';
        }

        if ($accrualCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Начисления: ' . $accrualCount . '</span>';
        }

        if ($bindingCount > 0) {
            $rows[] = '<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;">Привязки: ' . $bindingCount . '</span>';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;">'
            . '<div style="font-size:13px;font-weight:700;color:#92400e;">Найдены прямые связи. Их нужно проверить перед сменой арендатора.</div>'
            . '<div style="display:flex;flex-wrap:wrap;gap:8px;">' . implode('', $rows) . '</div>'
            . ($links !== [] ? '<div style="display:flex;flex-wrap:wrap;gap:8px;">' . implode('', $links) . '</div>' : '')
            . '<div style="font-size:12px;line-height:1.45;color:#92400e;">Смена арендатора не переносит договоры и начисления автоматически, а меняет только управленческий snapshot по дате вступления.</div>'
            . '</div>'
        );
    }

    private function makeTenantSwitchAction(string $actionClass): mixed
    {
        return $actionClass::make('switch_tenant')
            ->label('Сменить арендатора')
            ->icon('heroicon-o-user-plus')
            ->tooltip('Создать управленческую операцию смены арендатора с датой вступления в силу')
            ->size('lg')
            ->outlined()
            ->color('warning')
            ->visible(fn (): bool => $this->record instanceof MarketSpace)
            ->extraAttributes([
                'class' => 'market-space-card-action market-space-card-action--secondary market-space-card-action--tenant-switch-hidden',
            ])
            ->modalHeading('Сменить арендатора')
            ->modalSubmitActionLabel('Запланировать смену')
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
                    ->label('Новый арендатор')
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
                    ->label('Причина')
                    ->rows(2)
                    ->required()
                    ->maxLength(1000)
                    ->placeholder('Кратко укажите причину смены арендатора.'),
            ])
            ->action(function (array $data): void {
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
                    ->title('Смена арендатора запланирована')
                    ->body(
                        ((bool) ($operation->payload['detach_from_group'] ?? false))
                            ? 'Место будет выведено из группы и перейдёт к новому арендатору с ' . $effectiveAtLabel . '.'
                            : 'Новый арендатор вступит в силу с ' . $effectiveAtLabel . '.'
                    )
                    ->send();
            });
    }


    private function makeRegroupAction(string $actionClass): mixed
    {
        $isChild = fn (): bool => $this->record instanceof MarketSpace
            && (string) ($this->record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD;

        $isOrdinary = fn (): bool => $this->record instanceof MarketSpace
            && (string) ($this->record->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) === MarketSpace::SPACE_GROUP_ROLE_NONE;

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
            ->visible(fn (): bool => $isChild() || $isOrdinary())
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
                        return $item['old_number'] . ' → ' . $item['new_number'];
                    })
                    ->values();

                $body = $renamedParents->isNotEmpty()
                    ? 'Переименованы группы: ' . $renamedParents->implode('; ')
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

        if (class_exists(\Filament\Actions\Action::class)) {
            $actions[] = \Filament\Actions\Action::make('active_state')
                ->view('filament.resources.market-spaces.partials.active-state-toggle')
                ->viewData([
                    'isActive' => (bool) ($this->record?->is_active ?? false),
                ]);
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
                        'historyUrl' => MarketSpaceResource::getUrl('edit', ['record' => $this->record]) . '?tab=istoria::data::tab',
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
                        'historyUrl' => MarketSpaceResource::getUrl('edit', ['record' => $this->record]) . '?tab=istoria::data::tab',
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

    private function resolveSpaceHeading(): string|Htmlable
    {
        $displayName = trim((string) ($this->record?->display_name ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $number = trim((string) ($this->record?->number ?? ''));
        if ($number !== '') {
            return 'Место ' . $number;
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
                $sourceLabel = '#' . (int) ($sourceSpace?->id ?? 0);
            }

            return 'Входит в группу: ' . $sourceLabel;
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

}
