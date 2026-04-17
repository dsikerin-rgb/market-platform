<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Widgets\TenantAccrualsWorkspaceWidget;
use App\Models\TenantAccrual;
use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ListTenantAccruals extends ListRecords
{
    protected static string $resource = TenantAccrualResource::class;

    protected static ?string $title = 'Начисления';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab'],
        'tenantId' => ['except' => null],
    ];

    public ?string $activeTab = null;
    public ?int $tenantId = null;

    public function mount(): void
    {
        parent::mount();

        $legacyTab = request()->query('activeTab');
        $currentTab = request()->query('tab');

        if (filled($legacyTab) && blank($currentTab)) {
            $this->activeTab = (string) $legacyTab;
        }

        if (blank($this->activeTab)) {
            $this->activeTab = $this->resolveDefaultTab();
        }

        $tenantId = request()->query('tenantId');
        $this->tenantId = is_numeric($tenantId) && (int) $tenantId > 0 ? (int) $tenantId : null;

        if ($this->activeTab === 'ambiguous' && ! $this->hasAmbiguousOneCAccruals()) {
            $this->activeTab = $this->resolveDefaultTab();
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
        $tabs = [];
        $hasContractLinkStatus = TenantAccrualResource::hasTenantAccrualColumn('contract_link_status');
        $hasAmbiguousOneC = $hasContractLinkStatus && $this->hasAmbiguousOneCAccruals();

        $tabs['one_c'] = $this->makeTab(
            $tabClass,
            'Из 1С',
            fn (Builder $query) => $query->where('source', '1c'),
        );

        if ($hasContractLinkStatus) {
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

        $tabs['without_contract'] = $this->makeTab(
            $tabClass,
            'Без договора',
            fn (Builder $query) => $query
                ->where('period', '>=', $this->resolveWithoutContractSincePeriod())
                ->whereNull('tenant_contract_id'),
        );

        if ($hasAmbiguousOneC) {
            $tabs['ambiguous'] = $this->makeTab(
                $tabClass,
                'Неоднозначные',
                fn (Builder $query) => $query
                    ->where('source', '1c')
                    ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS),
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

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-accruals-list-page',
        ];
    }

    private function resolveDefaultTab(): string
    {
        if ($this->hasOneCAccruals()) {
            return 'one_c';
        }

        return 'all';
    }

    private function hasOneCAccruals(): bool
    {
        return TenantAccrualResource::getEloquentQuery()
            ->where('source', '1c')
            ->exists();
    }

    private function hasAmbiguousOneCAccruals(): bool
    {
        if (! TenantAccrualResource::hasTenantAccrualColumn('contract_link_status')) {
            return false;
        }

        return TenantAccrualResource::getEloquentQuery()
            ->where('source', '1c')
            ->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS)
            ->exists();
    }

    private function resolveWithoutContractSincePeriod(): string
    {
        $lookbackMonths = TenantAccrualContractResolver::LOOKBACK_MONTHS;

        return CarbonImmutable::now()
            ->startOfMonth()
            ->subMonths($lookbackMonths - 1)
            ->toDateString();
    }
}
