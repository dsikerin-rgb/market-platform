<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantContractResource\Pages;

use App\Filament\Resources\TenantContractResource;
use App\Filament\Widgets\TenantContractsWorkspaceWidget;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTenantContracts extends ListRecords
{
    protected static string $resource = TenantContractResource::class;

    protected static ?string $title = 'Договоры';

    public ?string $activeTab = 'operational';

    public function getBreadcrumb(): string
    {
        return 'Договоры';
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

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
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
            $tabs['financial'] = $this->makeTab(
                $tabClass,
                'Последняя задолженность',
                fn (Builder $query) => TenantContractResource::applyLatestDebtSnapshotScope($query, true)
            );

            $tabs['accruals'] = $this->makeTab(
                $tabClass,
                'Договоры из начислений',
                fn (Builder $query) => TenantContractResource::applyAccrualHistoryScope($query, true)
            );

            $tabs['mapping_candidates'] = $this->makeTab(
                $tabClass,
                'Основные кандидаты на привязку',
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
}
