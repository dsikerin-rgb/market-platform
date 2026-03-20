<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\TenantsWorkspaceWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Арендаторы';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'all'],
    ];

    public ?string $activeTab = 'all';

    public function getBreadcrumb(): string
    {
        return 'Арендаторы';
    }

    public function mount(): void
    {
        parent::mount();

        if (request()->boolean('with_red_debt')) {
            $currentCriticalValue = $this->tableFilters['has_critical_debt']['value'] ?? null;

            if (blank($currentCriticalValue)) {
                $this->tableFilters['has_critical_debt']['value'] = '1';
            }
        }

        if (request()->boolean('with_debt')) {
            $currentValue = $this->tableFilters['has_debt']['value'] ?? null;

            if (blank($currentValue)) {
                $this->tableFilters['has_debt']['value'] = '1';
            }
        }

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
            TenantsWorkspaceWidget::class,
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

    public function getBreadcrumbs(): array
    {
        return [];
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
            'with_debt' => $this->makeTab(
                $tabClass,
                'Есть задолженность',
                fn (Builder $query) => TenantResource::applyHasDebtFilter($query, true)
            ),
            'critical_debt' => $this->makeTab(
                $tabClass,
                'Критичная просрочка',
                fn (Builder $query) => TenantResource::applyCriticalDebtFilter($query)
            ),
            'without_debt' => $this->makeTab(
                $tabClass,
                'Без задолженности',
                fn (Builder $query) => TenantResource::applyHasDebtFilter($query, false)
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
            'fi-resource-tenants-list-page',
        ];
    }
}
