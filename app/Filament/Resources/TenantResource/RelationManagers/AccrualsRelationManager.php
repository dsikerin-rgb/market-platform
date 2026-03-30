<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantAccruals\Tables\TenantAccrualsTable;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccrualsRelationManager extends RelationManager
{
    protected static string $relationship = 'accruals';

    protected static ?string $title = 'Начисления';

    protected static ?string $recordTitleAttribute = 'source_file';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return TenantAccrualsTable::configure($table, [
            'marketId' => $owner?->market_id ? (int) $owner->market_id : null,
            'tenantId' => $owner?->id ? (int) $owner->id : null,
            'hideMarketColumn' => true,
            'hideTenantColumn' => true,
            'readOnly' => true,
        ])
            ->emptyStateHeading('Начислений пока нет')
            ->emptyStateDescription('После импорта или синхронизации 1С строки появятся здесь.')
            ->recordUrl(fn ($record): ?string => $record && TenantAccrualResource::canEdit($record)
                ? TenantAccrualResource::getUrl('edit', ['record' => $record])
                : null)
            ->headerActions([])
            ->bulkActions([]);
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()
            ->getQuery()
            ->with(['tenantContract', 'marketSpace.location']);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $owner = $this->getOwnerRecord();
        if (! $owner) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->where('market_id', (int) $owner->market_id);
        }

        if ($user->market_id && (int) $user->market_id === (int) $owner->market_id) {
            return $query->where('market_id', (int) $owner->market_id);
        }

        return $query->whereRaw('1 = 0');
    }
}
