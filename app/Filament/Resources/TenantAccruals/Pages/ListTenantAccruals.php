<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantAccruals\Pages;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Widgets\TenantAccrualsWorkspaceWidget;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): HtmlString => $this->renderTenantFilterBanner()),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
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

    private function renderTenantFilterBanner(): HtmlString
    {
        $tenant = $this->resolveTenantFilterRecord();

        if (! $tenant) {
            return new HtmlString('');
        }

        $params = [];
        if (filled($this->activeTab)) {
            $params['tab'] = $this->activeTab;
        }

        $url = TenantAccrualResource::getUrl('index', $params);

        return new HtmlString(
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px solid rgba(37,99,235,.22);background:rgba(37,99,235,.08);border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.4;">'
                . '<div>Показаны начисления арендатора: <strong>' . e((string) $tenant->name) . '</strong></div>'
                . '<a href="' . e($url) . '" style="font-weight:600;text-decoration:underline;text-underline-offset:2px;">Показать все начисления</a>'
                . '</div>'
        );
    }

    private function resolveTenantFilterRecord(): ?Tenant
    {
        if (! $this->tenantId) {
            return null;
        }

        $user = Filament::auth()->user();
        if (! $user) {
            return null;
        }

        $query = Tenant::query()->whereKey($this->tenantId);

        if ($user->isSuperAdmin()) {
            $selectedMarketId = $this->selectedMarketIdFromSession();
            if (filled($selectedMarketId)) {
                $query->where('market_id', (int) $selectedMarketId);
            }
        } elseif ($user->market_id) {
            $query->where('market_id', (int) $user->market_id);
        } else {
            return null;
        }

        return $query->first();
    }

    private function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";
        $value = session($key);

        return filled($value) ? (int) $value : null;
    }
}
