<?php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Widgets\MarketSpacesWorkspaceWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ListMarketSpaces extends ListRecords
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Торговые места';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'all'],
    ];

    public ?string $activeTab = 'all';

    public function mount(): void
    {
        parent::mount();

        $legacyTab = request()->query('activeTab');
        $currentTab = request()->query('tab');

        if (filled($legacyTab) && blank($currentTab)) {
            $this->activeTab = (string) $legacyTab;
        }

        if (! request()->boolean('only_vacant')) {
            return;
        }

        if ($this->activeTab === 'all') {
            $this->activeTab = 'vacant';
        }

        $currentValue = $this->tableFilters['status']['value'] ?? null;

        if (blank($currentValue)) {
            $this->tableFilters['status']['value'] = 'vacant';
        }
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
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

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();

        return [
            'all' => $tabClass::make('Все'),
            'vacant' => $this->makeTab(
                $tabClass,
                'Свободные',
                fn (Builder $query) => $query->where('status', 'vacant')
            ),
            'occupied' => $this->makeTab(
                $tabClass,
                'Занятые',
                fn (Builder $query) => $query->where('status', 'occupied')
            ),
        ];
    }

    protected static function resolveTabClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            return \Filament\Schemas\Components\Tabs\Tab::class;
        }

        if (class_exists(\Filament\Resources\Components\Tab::class)) {
            return \Filament\Resources\Components\Tab::class;
        }

        throw new RuntimeException('Filament Tab class not found for this version.');
    }

    protected function makeTab(string $tabClass, string $label, ?callable $modifyQueryUsing = null): object
    {
        $tab = $tabClass::make($label);

        if ($modifyQueryUsing && method_exists($tab, 'modifyQueryUsing')) {
            $tab->modifyQueryUsing($modifyQueryUsing);
        }

        return $tab;
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-market-spaces-list-page',
        ];
    }
}
