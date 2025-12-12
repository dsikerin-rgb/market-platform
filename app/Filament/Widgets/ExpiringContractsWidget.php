<?php

namespace App\Filament\Widgets;

use App\Models\TenantContract;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpiringContractsWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Ближайшие окончания договоров';

    protected int|string|array $columnSpan = 'full';

    protected int $recordsPerPage = 10;

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        $query = TenantContract::query()
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<=', now()->addDays(30))
            ->orderBy('ends_at');

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? session('dashboard_market_id')
            : $user->market_id;

        if ($marketId) {
            $query->where('market_id', $marketId);
        } elseif (! $isSuperAdmin) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('tenant.name')
                ->label('Арендатор')
                ->sortable()
                ->searchable(),

            TextColumn::make('number')
                ->label('Номер договора')
                ->sortable()
                ->searchable(),

            TextColumn::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'draft' => 'Черновик',
                    'active' => 'Активен',
                    'paused' => 'Приостановлен',
                    'terminated' => 'Расторгнут',
                    'archived' => 'Архив',
                    default => $state,
                }),

            TextColumn::make('starts_at')
                ->label('Дата начала')
                ->date(),

            TextColumn::make('ends_at')
                ->label('Дата окончания')
                ->date()
                ->sortable(),

            TextColumn::make('days_left')
                ->label('Осталось дней')
                ->state(function (TenantContract $record) {
                    if (! $record->ends_at) {
                        return null;
                    }

                    return Carbon::now()->startOfDay()->diffInDays($record->ends_at, false);
                }),
        ];
    }
}
