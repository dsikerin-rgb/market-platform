<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\MarketSpaceMapShape;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Filament\Resources\Pages\EditRecord;

class EditMarketSpace extends EditRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Редактирование торгового места';

    public function getBreadcrumb(): string
    {
        return 'Редактирование торгового места';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $data;
        }

        // Рыночные роли никогда не меняют market_id
        if (! $user->isSuperAdmin()) {
            $data['market_id'] = $this->record->market_id;
            return $data;
        }

        // Super-admin: если выбран рынок через переключатель — фиксируем market_id
        $selectedMarketId = session('filament.admin.selected_market_id');
        if (filled($selectedMarketId)) {
            $data['market_id'] = (int) $selectedMarketId;
        } else {
            // иначе не даём случайно "обнулить"
            if (empty($data['market_id'])) {
                $data['market_id'] = $this->record->market_id;
            }
        }

        return $data;
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
            $openMapAction = \Filament\Actions\Action::make('openMap')
                ->label('Перейти на карту')
                ->disabled(! $isMapLinked);

            if ($mapUrl) {
                $openMapAction->url($mapUrl, shouldOpenInNewTab: true);
            }

            $actions[] = $openMapAction;

            if (! $isMapLinked) {
                $actions[] = \Filament\Actions\Action::make('mapStatus')
                    ->label($mapStatus)
                    ->disabled();
            }
        } elseif (class_exists(\Filament\Pages\Actions\Action::class)) {
            $openMapAction = \Filament\Pages\Actions\Action::make('openMap')
                ->label('Перейти на карту')
                ->disabled(! $isMapLinked);

            if ($mapUrl) {
                $openMapAction->url($mapUrl, shouldOpenInNewTab: true);
            }

            $actions[] = $openMapAction;

            if (! $isMapLinked) {
                $actions[] = \Filament\Pages\Actions\Action::make('mapStatus')
                    ->label($mapStatus)
                    ->disabled();
            }
        }

        $canDelete = MarketSpaceResource::canDelete($this->record);

        if ($canDelete) {
            if (class_exists(\Filament\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить торговое место');
            } elseif (class_exists(\Filament\Pages\Actions\DeleteAction::class)) {
                $actions[] = \Filament\Pages\Actions\DeleteAction::make()->label('Удалить торговое место');
            }
        }

        return $actions;
    }
}
