<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Widgets\MarketSpacesWorkspaceWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMarketSpaces extends ListRecords
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Торговые места';

    public function mount(): void
    {
        parent::mount();

        if (! request()->boolean('only_vacant')) {
            return;
        }

        $currentValue = $this->tableFilters['status']['value'] ?? null;

        if (blank($currentValue)) {
            $this->tableFilters['status']['value'] = 'vacant';
        }
    }

    public function getBreadcrumb(): string
    {
        return 'Торговые места';
    }

    protected function getHeaderActions(): array
    {
        $createAction = Actions\CreateAction::make()
            ->label('Создать торговое место');

        if (method_exists($createAction, 'slideOver')) {
            $createAction->slideOver();
        }

        if (method_exists($createAction, 'modalWidth')) {
            $createAction->modalWidth('7xl');
        }

        return [$createAction];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MarketSpacesWorkspaceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
