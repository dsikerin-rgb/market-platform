<?php

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисления';

    public ?string $activeTab = 'one_c';

    public function getBreadcrumb(): string
    {
        return 'Начисления';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Детализация начислений из 1С и исторического импорта. Для договоров и задолженности используйте 1С-контур.';
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();

        return [
            'one_c' => $this->makeTab(
                $tabClass,
                '1С',
                fn (Builder $query) => $query->where('source', '1c')
            ),
            'without_contract' => $this->makeTab(
                $tabClass,
                'Без договора',
                fn (Builder $query) => $query->whereNull('tenant_contract_id')
            ),
            'history' => $this->makeTab(
                $tabClass,
                'Исторический импорт',
                fn (Builder $query) => $query->where('source', '!=', '1c')
            ),
            'all' => $tabClass::make('Все начисления'),
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
}
