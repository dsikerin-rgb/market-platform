<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Models\MarketSpaceMapShape;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

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

                $mapUrl = route('filament.admin.market-map', $params);
            }
        }

        if (class_exists(\Filament\Actions\Action::class)) {
            $actions[] = \Filament\Actions\Action::make('active_state')
                ->view('filament.resources.market-spaces.partials.active-state-toggle')
                ->viewData([
                    'isActive' => (bool) ($this->record?->is_active ?? false),
                ]);

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
