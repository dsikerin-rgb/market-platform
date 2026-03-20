<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Widgets\StaffWorkspaceWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

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
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StaffWorkspaceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getBreadcrumbs(): array
    {
        return [];
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
            'admins' => $this->makeTab(
                $tabClass,
                'Администраторы',
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'market-admin'))
            ),
            'managers' => $this->makeTab(
                $tabClass,
                'Менеджеры',
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'market-manager'))
            ),
            'operators' => $this->makeTab(
                $tabClass,
                'Операторы',
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'market-operator'))
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
            'fi-resource-staff-list-page',
        ];
    }
}
