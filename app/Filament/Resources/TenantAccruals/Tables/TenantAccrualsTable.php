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
        return $table
            ->columns([
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
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rent_amount')
                    ->label('Аренда')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('management_fee')
                    ->label('Управление')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('utilities_amount')
                    ->label('Коммуналка')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('electricity_amount')
                    ->label('Эл-во')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_no_vat')
                    ->label('Итого без НДС')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_with_vat')
                    ->label('Итого')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
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
                // Период: берём уникальные значения из таблицы и показываем как список "YYYY-MM"
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(function (): array {
                        $periods = DB::table('tenant_accruals')
                            ->select('period')
                            ->distinct()
                            ->orderByDesc('period')
                            ->limit(48)
                            ->pluck('period')
                            ->all();

                        $out = [];
                        foreach ($periods as $p) {
                            $key = is_string($p) ? $p : (string) $p;
                            $out[$key] = substr($key, 0, 7);
                        }
                        return $out;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }
                        return $query->whereDate('period', $value);
                    }),

                // Локация: фильтр по market_spaces.location_id через relation (без join руками)
                SelectFilter::make('location_id')
                    ->label('Локация')
                    ->options(function (): array {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
                            $key = "filament_{$panelId}_market_id";
                            $marketId = filled(session($key)) ? (int) session($key) : null;
                        } else {
                            $marketId = $user?->market_id ? (int) $user->market_id : null;
                        }

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

                // Только строки с привязкой к месту
                TernaryFilter::make('has_market_space')
                    ->label('Есть место')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('market_space_id'),
                        false: fn (Builder $q) => $q->whereNull('market_space_id'),
                        blank: fn (Builder $q) => $q,
                    ),

                // Только строки с суммой (удобно скрыть нулевые/служебные)
                TernaryFilter::make('has_total')
                    ->label('Есть сумма')
                    ->trueLabel('Только с итогом')
                    ->falseLabel('Только без итога')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('total_with_vat')->orWhereNotNull('total_no_vat'),
                        false: fn (Builder $q) => $q->whereNull('total_with_vat')->whereNull('total_no_vat'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                // Edit оставляем (точечные правки), но иконками и без текста
                \Filament\Actions\EditAction::make()
                    ->label('')
                    ->tooltip('Открыть')
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton(),
            ])
            // Bulk delete запрещаем (история импорта)
            ->toolbarActions([]);
    }
}
