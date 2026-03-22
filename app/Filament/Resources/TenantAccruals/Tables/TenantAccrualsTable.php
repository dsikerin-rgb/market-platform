<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantAccruals\Tables;

use App\Models\MarketLocation;
use App\Models\TenantAccrual;
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
            ->defaultSort('period', 'desc')
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) Filament::auth()->user()?->isSuperAdmin() && blank(static::selectedMarketIdFromSession())),

                TextColumn::make('period')
                    ->label('Период начисления')
                    ->date('Y-m')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Источник')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        '1c' => '1С',
                        'excel', 'csv' => 'Исторический импорт',
                        'manual' => 'Вручную',
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        '1c' => 'success',
                        'excel', 'csv' => 'warning',
                        'manual' => 'gray',
                        default => 'gray',
                    })
                    ->visible(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contract_link_status')
                    ->visible(false)
                    ->label('Связь с договором')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        TenantAccrual::CONTRACT_LINK_STATUS_EXACT => 'Точное совпадение',
                        TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED => 'Разрешено по контексту',
                        TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS => 'Неоднозначно',
                        TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED => 'Без договора',
                        default => 'Не проверено',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        TenantAccrual::CONTRACT_LINK_STATUS_EXACT => 'success',
                        TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED => 'info',
                        TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS => 'warning',
                        TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (TenantAccrual $record): ?string => $record->contract_link_note ?: null)
                    ->toggleable(),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('tenantContract.number')
                    ->label('Договор')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('contract_external_id')
                    ->label('ID договора 1С')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) Filament::auth()->user()?->isSuperAdmin()),

                TextColumn::make('marketSpace.location.name')
                    ->label('Локация')
                    ->sortable()
                    ->placeholder('—')
                    ->visible(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('marketSpace.number')
                    ->label('Место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->visible(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rent_amount')
                    ->label('Аренда')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->visible(false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('utilities_amount')
                    ->label('Коммунальные')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('electricity_amount')
                    ->label('Электроэнергия')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('management_fee')
                    ->label('Управление')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => static::formatMoney($state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Итого')
                    ->alignEnd()
                    ->getStateUsing(fn (TenantAccrual $record) => $record->total_with_vat ?? $record->total_no_vat)
                    ->formatStateUsing(fn ($state): string => static::formatMoney($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            'COALESCE(total_with_vat, total_no_vat) ' . ($direction === 'asc' ? 'ASC' : 'DESC')
                        );
                    })
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Состояние строки')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'imported' => 'Импортировано',
                        'adjusted' => 'Скорректировано',
                        'manual' => 'Вручную',
                        default => $state ?: '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source_file')
                    ->label('Файл / пакет')
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
                    ->label('Период начисления')
                    ->options(function (): array {
                        $marketId = static::resolveMarketIdForCurrentUser();
                        $query = DB::table('tenant_accruals')->select('period')->distinct();

                        if ($marketId) {
                            $query->where('market_id', $marketId);
                        }

                        $periods = $query
                            ->orderByDesc('period')
                            ->limit(48)
                            ->pluck('period')
                            ->all();

                        $options = [];

                        foreach ($periods as $period) {
                            $key = is_string($period) ? $period : (string) $period;
                            $options[$key] = substr($key, 0, 7);
                        }

                        return $options;
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

                        return $query->whereHas('marketSpace', function (Builder $marketSpaceQuery) use ($value): void {
                            $marketSpaceQuery->where('location_id', (int) $value);
                        });
                    }),

                SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        '1c' => '1С',
                        'excel' => 'Исторический импорт',
                        'csv' => 'Исторический импорт',
                        'manual' => 'Вручную',
                    ]),

                SelectFilter::make('contract_link_status')
                    ->visible(fn (): bool =>
                        \App\Filament\Resources\TenantAccruals\TenantAccrualResource::hasTenantAccrualColumn('contract_link_status')
                        && static::hasContractLinkStatusInCurrentScope(TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS)
                    )
                    ->label('Связь с договором')
                    ->options([
                        TenantAccrual::CONTRACT_LINK_STATUS_EXACT => 'Точное совпадение',
                        TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED => 'Разрешено по контексту',
                        TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS => 'Неоднозначно',
                        TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED => 'Без договора',
                    ]),

                TernaryFilter::make('has_contract')
                    ->label('Есть договор')
                    ->trueLabel('Только с договором')
                    ->falseLabel('Только без договора')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('tenant_contract_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('tenant_contract_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('has_market_space')
                    ->label('Есть место')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('market_space_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('market_space_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                static::editAction(),
            ])
            ->toolbarActions([]);
    }

    private static function editAction()
    {
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

    private static function hasContractLinkStatusInCurrentScope(string $status): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $query = DB::table('tenant_accruals')
            ->where('contract_link_status', $status);

        $marketId = static::resolveMarketIdForCurrentUser();
        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        return $query->exists();
    }

    private static function formatMoney($state, string $suffix = ' ₽'): string
    {
        if ($state === null || $state === '') {
            return '—';
        }

        $value = (float) $state;

        if (abs($value) < 0.00001) {
            return '—';
        }

        return number_format($value, 2, ',', ' ') . $suffix;
    }
}
