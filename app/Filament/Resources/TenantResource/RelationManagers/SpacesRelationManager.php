<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantContractResource;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;

class SpacesRelationManager extends RelationManager
{
    protected static string $relationship = 'spaces';

    protected static ?string $title = 'Торговые места';

    protected static ?string $recordTitleAttribute = 'number';

    /** @var array<int, array{label: string, tooltip: ?string, url: ?string}>|null */
    private ?array $primaryContractMeta = null;

    /** @var array{period_label: string, amounts: array<int, float>}|null */
    private ?array $lastPeriodAccrualMeta = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $marketId = $owner?->market_id ? (int) $owner->market_id : null;

        return $table
            ->defaultSort('number')
            ->columns([
                TextColumn::make('number')
                    ->label('Место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->url(fn (MarketSpace $record): ?string => MarketSpaceResource::canEdit($record)
                        ? MarketSpaceResource::getUrl('edit', ['record' => $record])
                        : null),

                TextColumn::make('display_name')
                    ->label('Название')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('primary_contract')
                    ->label('Основной договор')
                    ->state(fn (MarketSpace $record): string => $this->primaryContractMetaForSpace((int) $record->id)['label'])
                    ->tooltip(fn (MarketSpace $record): ?string => $this->primaryContractMetaForSpace((int) $record->id)['tooltip'])
                    ->url(fn (MarketSpace $record): ?string => $this->primaryContractMetaForSpace((int) $record->id)['url'])
                    ->color(fn (MarketSpace $record): ?string => match ($this->primaryContractMetaForSpace((int) $record->id)['label']) {
                        'Конфликт', 'Требует разбора' => 'danger',
                        '—' => null,
                        default => 'primary',
                    })
                    ->badge(fn (MarketSpace $record): bool => in_array($this->primaryContractMetaForSpace((int) $record->id)['label'], ['Конфликт', 'Требует разбора'], true))
                    ->placeholder('—'),

                TextColumn::make('rent_rate_value')
                    ->label('Ставка')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('last_period_accrual')
                    ->label('Начислено за последний период')
                    ->state(fn (MarketSpace $record): string => $this->formatMoney($this->lastPeriodAmountForSpace((int) $record->id)))
                    ->description(fn (): ?string => $this->lastPeriodAccrualMeta()['period_label'] !== '—'
                        ? ('Период: ' . $this->lastPeriodAccrualMeta()['period_label'])
                        : null)
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('activity_type')
                    ->label('Деятельность')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('location_id')
                    ->label('Локация')
                    ->options(fn (): array => $marketId
                        ? MarketLocation::query()
                            ->where('market_id', $marketId)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        : []),

                TernaryFilter::make('has_primary_contract')
                    ->label('Основной договор')
                    ->trueLabel('Только с договором')
                    ->falseLabel('Только без договора')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereExists(function ($subQuery): void {
                            $subQuery->selectRaw('1')
                                ->from('tenant_contracts as tc')
                                ->whereColumn('tc.market_space_id', 'market_spaces.id')
                                ->where('tc.is_active', true);
                        }),
                        false: fn (Builder $query): Builder => $query->whereNotExists(function ($subQuery): void {
                            $subQuery->selectRaw('1')
                                ->from('tenant_contracts as tc')
                                ->whereColumn('tc.market_space_id', 'market_spaces.id')
                                ->where('tc.is_active', true);
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_active', true),
                        false: fn (Builder $query): Builder => $query->where('is_active', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordUrl(fn ($record): ?string => $record && MarketSpaceResource::canEdit($record)
                ? MarketSpaceResource::getUrl('edit', ['record' => $record])
                : null)
            ->recordActions([
                static::openAction(),
            ])
            ->headerActions([])
            ->bulkActions([])
            ->emptyStateHeading('Торговых мест пока нет')
            ->emptyStateDescription('Закрепленные за арендатором торговые места появятся здесь.');
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()
            ->getQuery()
            ->with(['location']);

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

    /**
     * @return array{label: string, tooltip: ?string, url: ?string}
     */
    private function primaryContractMetaForSpace(int $spaceId): array
    {
        return $this->primaryContractMeta()[$spaceId] ?? [
            'label' => '—',
            'tooltip' => null,
            'url' => null,
        ];
    }

    /**
     * @return array<int, array{label: string, tooltip: ?string, url: ?string}>
     */
    private function primaryContractMeta(): array
    {
        if ($this->primaryContractMeta !== null) {
            return $this->primaryContractMeta;
        }

        $owner = $this->getOwnerRecord();
        if (! $owner) {
            return $this->primaryContractMeta = [];
        }

        $contractsBySpace = [];
        $now = now();

        if (DbSchema::hasTable('market_space_tenant_bindings')) {
            $bindingRows = DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->where('mstb.market_id', (int) $owner->market_id)
                ->where('mstb.tenant_id', (int) $owner->id)
                ->whereNotNull('mstb.tenant_contract_id')
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where('tc.is_active', true)
                ->orderBy('mstb.market_space_id')
                ->orderByDesc('tc.starts_at')
                ->orderByDesc('tc.id')
                ->get([
                    'mstb.market_space_id',
                    'tc.id as contract_id',
                    'tc.number',
                    'tc.starts_at',
                    'tc.ends_at',
                ]);

            foreach ($bindingRows as $row) {
                $spaceId = (int) ($row->market_space_id ?? 0);
                if ($spaceId <= 0) {
                    continue;
                }

                $contractsBySpace[$spaceId][] = [
                    'id' => (int) ($row->contract_id ?? 0),
                    'number' => trim((string) ($row->number ?? '')),
                    'starts_at' => $row->starts_at,
                    'ends_at' => $row->ends_at,
                ];
            }
        }

        if (empty($contractsBySpace)) {
            $fallbackRows = DB::table('tenant_contracts')
                ->where('market_id', (int) $owner->market_id)
                ->where('tenant_id', (int) $owner->id)
                ->whereNotNull('market_space_id')
                ->where('is_active', true)
                ->orderBy('market_space_id')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get([
                    'market_space_id',
                    'id',
                    'number',
                    'starts_at',
                    'ends_at',
                ]);

            foreach ($fallbackRows as $row) {
                $spaceId = (int) ($row->market_space_id ?? 0);
                if ($spaceId <= 0) {
                    continue;
                }

                $contractsBySpace[$spaceId][] = [
                    'id' => (int) ($row->id ?? 0),
                    'number' => trim((string) ($row->number ?? '')),
                    'starts_at' => $row->starts_at,
                    'ends_at' => $row->ends_at,
                ];
            }
        }

        $meta = [];
        foreach ($contractsBySpace as $spaceId => $contracts) {
            if (count($contracts) === 1) {
                $contract = $contracts[0];
                $meta[$spaceId] = [
                    'label' => $contract['number'] !== '' ? $contract['number'] : ('Договор #' . $contract['id']),
                    'tooltip' => $this->contractPeriodTooltip($contract['starts_at'], $contract['ends_at']),
                    'url' => TenantContractResource::getUrl('edit', ['record' => $contract['id']]),
                ];

                continue;
            }

            if (count($contracts) > 1) {
                $labels = array_map(
                    fn (array $contract): string => $contract['number'] !== '' ? $contract['number'] : ('#' . $contract['id']),
                    $contracts,
                );

                $meta[$spaceId] = [
                    'label' => 'Конфликт',
                    'tooltip' => 'Найдено несколько активных кандидатов: ' . implode(', ', $labels),
                    'url' => null,
                ];
            }
        }

        return $this->primaryContractMeta = $meta;
    }

    /**
     * @return array{period_label: string, amounts: array<int, float>}
     */
    private function lastPeriodAccrualMeta(): array
    {
        if ($this->lastPeriodAccrualMeta !== null) {
            return $this->lastPeriodAccrualMeta;
        }

        $owner = $this->getOwnerRecord();
        if (! $owner || ! DbSchema::hasTable('tenant_accruals')) {
            return $this->lastPeriodAccrualMeta = [
                'period_label' => '—',
                'amounts' => [],
            ];
        }

        $lastPeriod = DB::table('tenant_accruals')
            ->where('market_id', (int) $owner->market_id)
            ->where('tenant_id', (int) $owner->id)
            ->max('period');

        if (! $lastPeriod) {
            return $this->lastPeriodAccrualMeta = [
                'period_label' => '—',
                'amounts' => [],
            ];
        }

        $periodLabel = substr((string) $lastPeriod, 0, 7);

        $amounts = DB::table('tenant_accruals')
            ->where('market_id', (int) $owner->market_id)
            ->where('tenant_id', (int) $owner->id)
            ->where('period', $lastPeriod)
            ->whereNotNull('market_space_id')
            ->selectRaw('market_space_id, COALESCE(SUM(total_with_vat), 0) as total')
            ->groupBy('market_space_id')
            ->pluck('total', 'market_space_id')
            ->map(fn ($value): float => (float) $value)
            ->all();

        return $this->lastPeriodAccrualMeta = [
            'period_label' => $periodLabel !== '' ? $periodLabel : '—',
            'amounts' => $amounts,
        ];
    }

    private function lastPeriodAmountForSpace(int $spaceId): ?float
    {
        $amount = $this->lastPeriodAccrualMeta()['amounts'][$spaceId] ?? null;

        return $amount !== null ? (float) $amount : null;
    }

    private function contractPeriodTooltip($startsAt, $endsAt): ?string
    {
        $parts = [];

        if ($startsAt) {
            $parts[] = 'c ' . substr((string) $startsAt, 0, 10);
        }

        if ($endsAt) {
            $parts[] = 'по ' . substr((string) $endsAt, 0, 10);
        }

        return $parts !== [] ? implode(' ', $parts) : null;
    }

    private function formatMoney(?float $amount): string
    {
        if ($amount === null || abs($amount) < 0.00001) {
            return '—';
        }

        return number_format($amount, 2, ',', ' ') . ' ₽';
    }

    private static function openAction()
    {
        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            return \Filament\Tables\Actions\Action::make('open')
                ->label('')
                ->tooltip('Открыть')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->iconButton()
                ->url(fn (MarketSpace $record): string => MarketSpaceResource::getUrl('edit', ['record' => $record]));
        }

        return \Filament\Actions\Action::make('open')
            ->label('')
            ->tooltip('Открыть')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->iconButton()
            ->url(fn (MarketSpace $record): string => MarketSpaceResource::getUrl('edit', ['record' => $record]));
    }
}
