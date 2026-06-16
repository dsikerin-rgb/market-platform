<?php

// app/Filament/Resources/MarketSpaceResource/Pages/ListMarketSpaces.php

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Widgets\MarketSpacesWorkspaceWidget;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use App\Support\AdminCapabilities;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        if (! $this->canViewFinanceTabs() && $this->activeTab === 'missing-1c-link') {
            $this->activeTab = 'all';
        }

        if (request()->boolean('only_vacant')) {
            if ($this->activeTab === 'all') {
                $this->activeTab = 'vacant';
            }

            $currentValue = $this->tableFilters['status']['value'] ?? null;

            if (blank($currentValue)) {
                $this->tableFilters['status']['value'] = 'vacant';
            }

            return;
        }

        if (! request()->boolean('only_maintenance')) {
            return;
        }

        $currentValue = $this->tableFilters['status']['value'] ?? null;

        if (blank($currentValue)) {
            $this->tableFilters['status']['value'] = 'maintenance';
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

        $tabs = [
            'all' => $tabClass::make('Все'),
            'vacant' => $this->makeTab(
                $tabClass,
                'Свободные',
                fn (Builder $query) => $query->where(function (Builder $query): void {
                    // non-child / orphan-child без tenant_id
                    $query->whereNull('tenant_id')
                        ->where(function (Builder $query): void {
                            $query->where('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                ->orWhereNull('space_group_role')
                                ->orWhere('space_group_parent_id', null);
                        });

                    // child с parent_id, у которого parent не имеет tenant_id
                    $query->orWhere(function (Builder $query): void {
                        $query->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                            ->whereNotNull('space_group_parent_id')
                            ->whereDoesntHave('spaceGroupParent', function (Builder $query): void {
                                $query->whereNotNull('tenant_id');
                            });
                    });
                })
            ),
            'occupied' => $this->makeTab(
                $tabClass,
                'Занятые',
                fn (Builder $query) => $query->where(function (Builder $query): void {
                    // non-child / orphan-child с tenant_id
                    $query->whereNotNull('tenant_id')
                        ->where(function (Builder $query): void {
                            $query->where('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                ->orWhereNull('space_group_role')
                                ->orWhere('space_group_parent_id', null);
                        });

                    // child с parent_id, у которого parent имеет tenant_id
                    $query->orWhere(function (Builder $query): void {
                        $query->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                            ->whereNotNull('space_group_parent_id')
                            ->whereHas('spaceGroupParent', function (Builder $query): void {
                                $query->whereNotNull('tenant_id');
                            });
                    });
                })
            ),
        ];

        if (! $this->canViewFinanceTabs()) {
            return $tabs;
        }

        return [
            ...$tabs,
            'missing-1c-link' => $this->makeTab(
                $tabClass,
                'Без точной связи 1С',
                fn (Builder $query) => $this->applyMissingFinancialLinkScope($query)
            ),
        ];
    }

    private function canViewFinanceTabs(): bool
    {
        return AdminCapabilities::canViewFinance(Filament::auth()->user());
    }

    private function applyMissingFinancialLinkScope(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'maintenance');
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('id', $this->sharedUseSpaceIdsWithMultipleActiveParticipants())
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereNotNull('tenant_id')
                            ->where(function (Builder $query): void {
                                $query->where('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->orWhereNull('space_group_role')
                                    ->orWhereNull('space_group_parent_id');
                            })
                            ->whereDoesntHave('tenantContracts', fn (Builder $contractQuery): Builder => $this->activeExactContractQuery($contractQuery));
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                            ->whereNotNull('space_group_parent_id')
                            ->whereHas('spaceGroupParent', function (Builder $parentQuery): void {
                                $parentQuery
                                    ->whereNotNull('tenant_id')
                                    ->whereDoesntHave('tenantContracts', fn (Builder $contractQuery): Builder => $this->activeExactContractQuery($contractQuery));
                            });
                    });
            });
    }

    private function activeExactContractQuery(Builder $query): Builder
    {
        return $query
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->where(function (Builder $query): void {
                $query->whereNull('space_mapping_mode')
                    ->orWhere('space_mapping_mode', '!=', TenantContract::SPACE_MAPPING_MODE_EXCLUDED);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('is_active')
                    ->orWhere('is_active', true);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['terminated', 'archived']);
            });
    }

    private function sharedUseSpaceIdsWithMultipleActiveParticipants(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('market_space_tenant_bindings')) {
            return [];
        }

        return DB::table('market_space_tenant_bindings')
            ->select('market_space_id')
            ->where('binding_type', 'shared_use')
            ->whereNull('ended_at')
            ->whereNotNull('tenant_id')
            ->groupBy('market_space_id')
            ->havingRaw('COUNT(DISTINCT tenant_id) > 1')
            ->pluck('market_space_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
