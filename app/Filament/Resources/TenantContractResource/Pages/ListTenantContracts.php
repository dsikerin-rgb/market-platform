<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantContractResource\Pages;

use App\Filament\Resources\TenantContractResource;
use App\Models\MarketSpace;
use App\Filament\Widgets\TenantContractsWorkspaceWidget;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListTenantContracts extends ListRecords
{
    protected static string $resource = TenantContractResource::class;

    protected static ?string $title = 'Договоры';

    protected array $queryString = [
        'activeTab' => ['as' => 'tab', 'except' => 'operational'],
        'marketSpaceId' => ['except' => null],
    ];

    public ?string $activeTab = 'operational';
    public ?int $marketSpaceId = null;

    public function mount(): void
    {
        parent::mount();

        $legacyTab = request()->query('activeTab');
        $currentTab = request()->query('tab');

        if (filled($legacyTab) && blank($currentTab)) {
            $this->activeTab = (string) $legacyTab;
        }

        $marketSpaceId = request()->query('marketSpaceId');
        $this->marketSpaceId = is_numeric($marketSpaceId) && (int) $marketSpaceId > 0 ? (int) $marketSpaceId : null;
    }

    public function getBreadcrumb(): string
    {
        return 'Договоры';
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
            TenantContractsWorkspaceWidget::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): HtmlString => $this->renderMarketSpaceFilterBanner()),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
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
        return 'operational';
    }

    public function getTabs(): array
    {
        $tabClass = static::resolveTabClass();
        $tabs = [
            'operational' => $this->makeTab(
                $tabClass,
                'Рабочий контур',
                fn (Builder $query) => TenantContractResource::applyOperationalContractsScope($query, true)
            ),
            'operational_unmapped' => $this->makeTab(
                $tabClass,
                'Без привязки к месту',
                fn (Builder $query) => TenantContractResource::applyOperationalContractsScope(
                    $query->whereNull('market_space_id'),
                    true
                )
            ),
        ];

        if ($this->canSeeTechnicalTabs()) {
            $tabs['mapping_candidates'] = $this->makeTab(
                $tabClass,
                'Кандидаты на привязку',
                fn (Builder $query) => TenantContractResource::applyWorkbenchBucketScope(
                    TenantContractResource::applyOperationalContractsScope(
                        TenantContractResource::applyWorkbenchBucketScope($query, 'primary_contract', true),
                        true
                    ),
                    'needs_mapping',
                    true
                )
            );

            $tabs['overlaps'] = $this->makeTab(
                $tabClass,
                'С наложением',
                fn (Builder $query) => TenantContractResource::applyWorkbenchBucketScope(
                    TenantContractResource::applyOperationalContractsScope($query, true),
                    'has_overlap',
                    true
                )
            );

            $tabs['financial'] = $this->makeTab(
                $tabClass,
                'Есть в последней выгрузке долга',
                fn (Builder $query) => TenantContractResource::applyLatestDebtSnapshotScope($query, true)
            );

            $tabs['accruals'] = $this->makeTab(
                $tabClass,
                'Найдены по начислениям',
                fn (Builder $query) => TenantContractResource::applyAccrualHistoryScope($query, true)
            );

            $tabs['review'] = $this->makeTab(
                $tabClass,
                'Требуют разбора',
                fn (Builder $query) => TenantContractResource::applyWorkbenchBucketScope(
                    TenantContractResource::applyOperationalContractsScope($query, true),
                    'needs_review',
                    true
                )
            );
        }

        $tabs['all'] = $tabClass::make('Все договоры');

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

    private function canSeeTechnicalTabs(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-resource-contracts-list-page',
        ];
    }

    private function renderMarketSpaceFilterBanner(): HtmlString
    {
        $space = $this->resolveMarketSpaceFilterRecord();

        if (! $space) {
            return new HtmlString('');
        }

        $params = [];
        if (filled($this->activeTab)) {
            $params['tab'] = $this->activeTab;
        }

        $url = TenantContractResource::getUrl('index', $params);
        $spaceLabel = trim((string) ($space->number ?: $space->display_name ?: ('#' . (int) $space->id)));

        return new HtmlString(
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px solid rgba(37,99,235,.22);background:rgba(37,99,235,.08);border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.4;">'
                . '<div>Показаны договоры места: <strong>' . e($spaceLabel) . '</strong></div>'
                . '<a href="' . e($url) . '" style="font-weight:600;text-decoration:underline;text-underline-offset:2px;">Показать все договоры</a>'
                . '</div>'
        );
    }

    private function resolveMarketSpaceFilterRecord(): ?MarketSpace
    {
        if (! $this->marketSpaceId) {
            return null;
        }

        $user = Filament::auth()->user();
        if (! $user) {
            return null;
        }

        $query = MarketSpace::query()->whereKey($this->marketSpaceId);

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
