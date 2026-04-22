<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Models\MarketSpaceMapShape;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'statusLabel' => $this->resolveStatusLabel(),
            'statusColor' => $this->resolveStatusColor(),
        ]);
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
        ];
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

            $actions[] = \Filament\Actions\Action::make('deactivate_precheck')
                ->label('Упразднить место')
                ->icon('heroicon-o-archive-box')
                ->tooltip('Проверка связей перед деактивацией')
                ->size('lg')
                ->outlined()
                ->color('gray')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--secondary',
                ])
                ->modalHeading('Упразднить место')
                ->modalSubmitAction(false)
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

            $openMapAction = \Filament\Actions\Action::make('openMap')
                ->label('Показать на карте')
                ->icon('heroicon-o-map')
                ->tooltip($isMapLinked ? 'Откроет связанную карту объекта' : 'Привязка к карте ещё не настроена')
                ->disabled(! $isMapLinked)
                ->size('lg')
                ->outlined()
                ->color('primary')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--primary',
                ]);

            if ($mapUrl) {
                $openMapAction->url($mapUrl, shouldOpenInNewTab: true);
            }

            $actions[] = $openMapAction;

            if (! $isMapLinked) {
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



            $actions[] = \Filament\Pages\Actions\Action::make('deactivate_precheck')
                ->label('Упразднить место')
                ->icon('heroicon-o-archive-box')
                ->tooltip('Проверка связей перед деактивацией')
                ->size('lg')
                ->outlined()
                ->color('gray')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--secondary',
                ])
                ->modalHeading('Упразднить место')
                ->modalSubmitAction(false)
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

            $openMapAction = \Filament\Pages\Actions\Action::make('openMap')
                ->label('Показать на карте')
                ->icon('heroicon-o-map')
                ->tooltip($isMapLinked ? 'Откроет связанную карту объекта' : 'Привязка к карте ещё не настроена')
                ->disabled(! $isMapLinked)
                ->size('lg')
                ->outlined()
                ->color('primary')
                ->extraAttributes([
                    'class' => 'market-space-card-action market-space-card-action--primary',
                ]);

            if ($mapUrl) {
                $openMapAction->url($mapUrl, shouldOpenInNewTab: true);
            }

            $actions[] = $openMapAction;

            if (! $isMapLinked) {
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

        $canDelete = MarketSpaceResource::canDelete($this->record);

        if ($canDelete) {
            if (class_exists(\Filament\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Actions\DeleteAction::make()
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->tooltip('Безвозвратно удалить карточку места')
                    ->size('lg')
                    ->outlined()
                    ->color('danger')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--danger',
                    ]);
            } elseif (class_exists(\Filament\Pages\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Pages\Actions\DeleteAction::make()
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->tooltip('Безвозвратно удалить карточку места')
                    ->size('lg')
                    ->outlined()
                    ->color('danger')
                    ->extraAttributes([
                        'class' => 'market-space-card-action market-space-card-action--danger',
                    ]);
            }
        }

        return $actions;
    }

    private function resolveSpaceHeading(): string
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
        $state = $this->record?->status;

        if ($state === 'free') {
            $state = 'vacant';
        }

        return match ($state) {
            'vacant' => 'Свободно',
            'occupied' => 'Занято',
            'reserved' => 'Зарезервировано',
            'maintenance' => 'На обслуживании',
            default => $state,
        };
    }

    private function resolveStatusColor(): string
    {
        $state = $this->record?->status;

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
