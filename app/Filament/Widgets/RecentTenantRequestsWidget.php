<?php

namespace App\Filament\Widgets;

use App\Models\TenantRequest;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentTenantRequestsWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Последние обращения арендаторов';

    protected int $recordsPerPage = 10;

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        $query = TenantRequest::query()->orderByDesc('created_at');

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('market.name')
                ->label('Рынок')
                ->sortable(),
            TextColumn::make('tenant.name')
                ->label('Арендатор')
                ->sortable()
                ->searchable(),
            TextColumn::make('subject')
                ->label('Тема')
                ->sortable()
                ->searchable(),
            TextColumn::make('status')
                ->label('Статус обращения')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'new' => 'Новое',
                    'in_progress' => 'В работе',
                    'resolved' => 'Решено',
                    'closed' => 'Закрыто',
                    default => $state,
                }),
            TextColumn::make('priority')
                ->label('Приоритет')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'low' => 'Низкий',
                    'normal' => 'Обычный',
                    'high' => 'Высокий',
                    'urgent' => 'Критичный',
                    default => $state,
                }),
            TextColumn::make('created_at')
                ->label('Создано')
                ->dateTime()
                ->sortable(),
            TextColumn::make('resolved_at')
                ->label('Решено')
                ->dateTime(),
            TextColumn::make('closed_at')
                ->label('Закрыто')
                ->dateTime(),
        ];
    }
}
