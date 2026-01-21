<?php
# app/Filament/Resources/TenantAccruals/Tables/TenantAccrualsTable.php

namespace App\Filament\Resources\TenantAccruals\Tables;

use App\Models\MarketLocation;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TenantAccrualsTable
{
    public static function configure(Table $table): Table
    {
        $statusOptions = [
            'imported' => 'Импортировано',
            'adjusted' => 'Скорректировано',
            'manual' => 'Вручную',
        ];

        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => (bool) Filament::auth()->user()?->isSuperAdmin() && blank(static::selectedMarketIdFromSession())),

                TextColumn::make('period')
                    ->label('Период')
                    ->date('Y-m')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('marketSpace.location.name')
                    ->label('Локация')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('marketSpace.number')
                    ->label('Место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source_place_code')
                    ->label('Код места (из файла)')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source_place_name')
                    ->label('Название отдела')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('activity_type')
                    ->label('Вид деятельности')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('rent_rate')
                    ->label('Ставка')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => static::formatMoney($state, suffix: ' ₽/м²'))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rent_amount')
                    ->label('Аренда')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('management_fee')
                    ->label('Управление')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('utilities_amount')
                    ->label('Коммуналка')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('electricity_amount')
                    ->label('Эл-во')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Итого')
                    ->alignEnd()
                    ->getStateUsing(fn ($record) => $record->total_with_vat ?? $record->total_no_vat)
                    ->formatStateUsing(fn ($state) => static::formatMoney($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        // сортируем по total_with_vat, иначе total_no_vat
                        return $query->orderByRaw(
                            'COALESCE(total_with_vat, total_no_vat) ' . ($direction === 'asc' ? 'ASC' : 'DESC')
                        );
                    })
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $statusOptions[$state ?? ''] ?? ($state ?: '—'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source_file')
                    ->label('Файл')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('imported_at')
                    ->label('Импортировано')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(function (): array {
                        $marketId = static::resolveMarketIdForCurrentUser();
                        $q = DB::table('tenant_accruals')->select('period')->distinct();

                        if ($marketId) {
                            $q->where('market_id', $marketId);
                        }

                        $periods = $q->orderByDesc('period')
                            ->limit(48)
                            ->pluck('period')
                            ->all();

                        $out = [];
                        foreach ($periods as $p) {
                            $key = is_string($p) ? $p : (string) $p; // обычно YYYY-MM-DD
                            $out[$key] = substr($key, 0, 7);         // YYYY-MM
                        }

                        return $out;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return blank($value)
                            ? $query
                            : $query->whereDate('period', (string) $value);
                    }),

                SelectFilter::make('location_id')
                    ->label('Локация')
                    ->options(function (): array {
                        $marketId = static::resolveMarketIdForCurrentUser();

                        if (! $marketId) {
                            return [];
                        }

                        return MarketLocation::query()
                            ->where('market_id', $marketId)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereHas('marketSpace', function (Builder $q) use ($value) {
                            $q->where('location_id', (int) $value);
                        });
                    }),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'imported' => 'Импортировано',
                        'adjusted' => 'Скорректировано',
                        'manual' => 'Вручную',
                    ]),

                TernaryFilter::make('has_market_space')
                    ->label('Есть место')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('market_space_id'),
                        false: fn (Builder $q) => $q->whereNull('market_space_id'),
                        blank: fn (Builder $q) => $q,
                    ),

                TernaryFilter::make('has_total')
                    ->label('Есть сумма')
                    ->trueLabel('Только с итогом')
                    ->falseLabel('Только без итога')
                    ->queries(
                        true: fn (Builder $q) => $q->where(function (Builder $qq) {
                            $qq->whereNotNull('total_with_vat')->orWhereNotNull('total_no_vat');
                        }),
                        false: fn (Builder $q) => $q->whereNull('total_with_vat')->whereNull('total_no_vat'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                static::editAction(),
            ])
            ->toolbarActions([]); // bulk delete запрещаем (история импорта)
    }

    private static function editAction()
    {
        // Совместимость с разными версиями Filament
        if (class_exists(\Filament\Actions\EditAction::class)) {
            return \Filament\Actions\EditAction::make()
                ->label('')
                ->tooltip('Открыть')
                ->icon('heroicon-o-pencil-square')
                ->iconButton();
        }

        return \Filament\Tables\Actions\EditAction::make()
            ->label('')
            ->tooltip('Открыть')
            ->icon('heroicon-o-pencil-square')
            ->iconButton();
    }

    private static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";
        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    private static function resolveMarketIdForCurrentUser(): ?int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return static::selectedMarketIdFromSession();
        }

        return $user->market_id ? (int) $user->market_id : null;
    }

    private static function formatMoney($state, string $suffix = ' ₽'): string
    {
        if ($state === null || $state === '') {
            return '—';
        }

        $v = (float) $state;

        if (abs($v) < 0.00001) {
            return '—';
        }

        return number_format($v, 2, ',', ' ') . $suffix;
    }
}
