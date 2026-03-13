<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Widgets\TenantAccrualsWorkspaceWidget;
use App\Models\TenantAccrual;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисления';

    public ?string $activeTab = null;

    /** @var array<string, int>|null */
    private ?array $tabCounts = null;

    public function mount(): void
    {
        parent::mount();

        $availableTabs = array_keys($this->getTabs());

        if (! in_array((string) $this->activeTab, $availableTabs, true)) {
            $this->activeTab = $this->resolveDefaultTab();
        }
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
        return null;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();
        $tabCounts = $this->resolveTabCounts();
        $tabs = [];

        if ($tabCounts['one_c'] > 0) {
            $tabs['one_c'] = $this->makeTab(
                $tabClass,
                '1С',
                fn (Builder $query) => $query->where('source', '1c'),
            );
        }

        if ($tabCounts['linked'] > 0) {
            $tabs['linked'] = $this->makeTab(
                $tabClass,
                'Связаны с договором',
                fn (Builder $query) => $query
                    ->where('source', '1c')
                    ->whereIn('contract_link_status', [
                        TenantAccrual::CONTRACT_LINK_STATUS_EXACT,
                        TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED,
                    ]),
            );
        }

        if ($tabCounts['without_contract'] > 0) {
            $tabs['without_contract'] = $this->makeTab(
                $tabClass,
                'Без договора',
                fn (Builder $query) => $query
                    ->where('source', '1c')
                    ->where(function (Builder $builder): void {
                        $builder
                            ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED)
                            ->orWhereNull('contract_link_status');
                    }),
            );
        }

        if ($tabCounts['ambiguous'] > 0) {
            $tabs['ambiguous'] = $this->makeTab(
                $tabClass,
                'Неоднозначные',
                fn (Builder $query) => $query
                    ->where('source', '1c')
                    ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS),
            );
        }

        if ($tabCounts['history'] > 0) {
            $tabs['history'] = $this->makeTab(
                $tabClass,
                'Исторический импорт',
                fn (Builder $query) => $query->where('source', '!=', '1c'),
            );
        }

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

    private function resolveDefaultTab(): string
    {
        $tabCounts = $this->resolveTabCounts();

        if ($tabCounts['one_c'] > 0) {
            return 'one_c';
        }

        if ($tabCounts['history'] > 0) {
            return 'history';
        }

        return 'all';
    }

    /**
     * @return array{one_c:int,linked:int,without_contract:int,ambiguous:int,history:int}
     */
    private function resolveTabCounts(): array
    {
        if ($this->tabCounts !== null) {
            return $this->tabCounts;
        }

        $baseQuery = TenantAccrualResource::getEloquentQuery();
        $oneCQuery = (clone $baseQuery)->where('source', '1c');
        $exact = (clone $oneCQuery)
            ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_EXACT)
            ->count();
        $resolved = (clone $oneCQuery)
            ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED)
            ->count();

        $this->tabCounts = [
            'one_c' => (clone $oneCQuery)->count(),
            'linked' => $exact + $resolved,
            'without_contract' => (clone $oneCQuery)
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED)
                        ->orWhereNull('contract_link_status');
                })
                ->count(),
            'ambiguous' => (clone $oneCQuery)
                ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS)
                ->count(),
            'history' => (clone $baseQuery)
                ->where('source', '!=', '1c')
                ->count(),
        ];

        return $this->tabCounts;
    }
}
