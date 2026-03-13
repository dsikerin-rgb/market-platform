<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ContractDebt;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OneCContractDebtsTableWidget extends BaseTableWidget
{
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Строки 1С: начислено, оплачено, долг';

    protected int|string|array $columnSpan = 'full';

    private string $marketTimezone = 'UTC';

    private ?string $emptyStateNote = null;

    public function table(Table $table): Table
    {
        return $table
            ->searchable()
            ->defaultPaginationPageOption(25);
    }

    public function getTableRecordsPerPage(): int
    {
        return 25;
    }

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        if (! $user) {
            $this->emptyStateNote = 'Нет пользователя';

            return ContractDebt::query()->whereRaw('1 = 0');
        }

        $marketId = $this->resolveMarketId($user);

        if ($marketId <= 0) {
            $this->emptyStateNote = 'Выберите рынок';

            return ContractDebt::query()->whereRaw('1 = 0');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $this->marketTimezone = $this->resolveTimezone($market?->timezone);
        $this->emptyStateNote = 'Это строки обмена 1С по договорам и периодам, а не бухгалтерские проводки по каждой отдельной оплате.';

        return ContractDebt::query()
            ->with([
                'tenant',
                'tenantContract.marketSpace.location',
            ])
            ->where('market_id', $marketId)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('calculated_at')
                ->label('Время 1С')
                ->formatStateUsing(function ($state): ?string {
                    if (! $state) {
                        return null;
                    }

                    return CarbonImmutable::parse($state)
                        ->setTimezone($this->marketTimezone)
                        ->format('d.m.Y H:i');
                })
                ->sortable(),

            TextColumn::make('period')
                ->label('Период')
                ->formatStateUsing(fn (?string $state): string => $this->formatPeriod($state))
                ->sortable(),

            TextColumn::make('tenant.name')
                ->label('Арендатор')
                ->placeholder('—')
                ->searchable()
                ->sortable(),

            TextColumn::make('tenantContract.number')
                ->label('Договор')
                ->state(function (ContractDebt $record): string {
                    $number = trim((string) ($record->tenantContract?->number ?? ''));

                    return $number !== '' ? $number : (string) $record->contract_external_id;
                })
                ->wrap()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->where(function (Builder $builder) use ($search): void {
                        $builder
                            ->where('contract_external_id', 'like', '%' . $search . '%')
                            ->orWhereHas('tenantContract', fn (Builder $contractQuery) => $contractQuery->where('number', 'like', '%' . $search . '%'));
                    });
                }),

            TextColumn::make('tenantContract.marketSpace.location.name')
                ->label('Локация')
                ->placeholder('—')
                ->toggleable(),

            TextColumn::make('tenantContract.marketSpace.number')
                ->label('Место')
                ->placeholder('—')
                ->toggleable(),

            TextColumn::make('accrued_amount')
                ->label('Начислено')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => $this->formatMoney($state))
                ->sortable(),

            TextColumn::make('paid_amount')
                ->label('Оплачено')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => $this->formatMoney($state))
                ->sortable(),

            TextColumn::make('debt_amount')
                ->label('Долг')
                ->alignEnd()
                ->formatStateUsing(fn ($state): string => $this->formatMoney($state))
                ->sortable(),

            TextColumn::make('source')
                ->label('Источник')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => $state === '1c' ? '1С' : (string) $state)
                ->color('success')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('contract_external_id')
                ->label('ID договора 1С')
                ->toggleable(isToggledHiddenByDefault: true)
                ->searchable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            SelectFilter::make('period')
                ->label('Период')
                ->options(function (): array {
                    $marketId = $this->resolveMarketId(Filament::auth()->user());
                    $query = ContractDebt::query()->select('period')->distinct();

                    if ($marketId > 0) {
                        $query->where('market_id', $marketId);
                    }

                    return $query
                        ->orderByDesc('period')
                        ->limit(36)
                        ->pluck('period', 'period')
                        ->mapWithKeys(fn (string $period): array => [$period => $this->formatPeriod($period)])
                        ->all();
                }),

            TernaryFilter::make('has_debt')
                ->label('Есть долг')
                ->trueLabel('Только с долгом')
                ->falseLabel('Только без долга')
                ->queries(
                    true: fn (Builder $query): Builder => $query->where('debt_amount', '>', 0),
                    false: fn (Builder $query): Builder => $query->where('debt_amount', '<=', 0),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return 'Нет строк 1С';
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return $this->emptyStateNote;
    }

    private function resolveMarketId($user): int
    {
        if (! $user) {
            return 0;
        }

        if (! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
        }

        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id")
                ?? session("filament_{$panelId}_market_id");
        }

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        if (filled($value)) {
            return (int) $value;
        }

        $fallback = DB::table('markets')->orderBy('name')->value('id');

        return $fallback ? (int) $fallback : 0;
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private function formatPeriod(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m', (string) $value)->format('m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatMoney($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 2, ',', ' ') . ' ₽';
    }
}
