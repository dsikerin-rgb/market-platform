<?php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Widgets\TenantAccrualsWorkspaceWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисления';

    public ?string $activeTab = null;

    public function mount(): void
    {
        parent::mount();
        $this->activeTab = $this->resolveDefaultTab();
    }

    public function getBreadcrumb(): string
    {
        return 'Начисления';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TenantAccrualsWorkspaceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return 'Детализация начислений. Вкладка 1С показывается отдельно, если в текущем контуре уже есть начисления из 1С.';
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();
        $tabs = [];

        if ($this->hasOneCAccruals()) {
            $tabs['one_c'] = $this->makeTab(
                $tabClass,
                '1С',
                fn (Builder $query) => $query->where('source', '1c')
            );
        }

        $tabs['without_contract'] = $this->makeTab(
            $tabClass,
            'Без договора',
            fn (Builder $query) => $query->whereNull('tenant_contract_id')
        );

        $tabs['history'] = $this->makeTab(
            $tabClass,
            'Исторический импорт',
            fn (Builder $query) => $query->where('source', '!=', '1c')
        );

        $tabs['all'] = $tabClass::make('Все начисления');

        return $tabs;
    }

    protected static function resolveTabClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            return \Filament\Schemas\Components\Tabs\Tab::class;
        }

        if (class_exists(\Filament\Resources\Components\Tab::class)) {
            return \Filament\Resources\Components\Tab::class;
        }

        throw new \RuntimeException('Filament Tab class not found for this version.');
    }

    protected function makeTab(string $tabClass, string $label, ?callable $modifyQueryUsing = null): object
    {
        $tab = $tabClass::make($label);

        if ($modifyQueryUsing && method_exists($tab, 'modifyQueryUsing')) {
            $tab->modifyQueryUsing($modifyQueryUsing);
        }

        return $tab;
    }

    private function resolveDefaultTab(): string
    {
        return $this->hasOneCAccruals() ? 'one_c' : 'all';
    }

    private function hasOneCAccruals(): bool
    {
        return TenantAccrualResource::getEloquentQuery()
            ->where('source', '1c')
            ->exists();
    }
}
