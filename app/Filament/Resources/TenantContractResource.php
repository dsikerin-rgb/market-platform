<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TenantContractResource\Pages;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantContractResource extends BaseResource
{
    protected static ?string $model = TenantContract::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Договор';
    protected static ?string $pluralModelLabel = 'Договоры';
    protected static ?string $navigationLabel = 'Договоры';
    protected static ?string $slug = 'contracts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 35;

    /** @var array<string, array<string, mixed>> */
    private static array $classificationCache = [];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['market-admin', 'market-manager'])
            && (bool) $user->market_id;
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'number',
            'external_id',
            'status',
            'tenant.name',
            'tenant.short_name',
            'marketSpace.number',
            'marketSpace.display_name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (TenantContract $record): ?string => filled($record->tenant?->short_name) ? (string) $record->tenant?->short_name : null)
                    ->wrap(),

                TextColumn::make('number')
                    ->label('Номер документа')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->wrap()
                    ->description(fn (TenantContract $record): ?string => filled($record->external_id) ? '1С: ' . (string) $record->external_id : null),

                TextColumn::make('ordering_status')
                    ->label('Статус упорядочивания')
                    ->state(fn (TenantContract $record): string => static::orderingMeta($record)['label'])
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::orderingMeta($record)['color']),

                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->state(fn (TenantContract $record): string => (string) static::classificationFor($record)['label'])
                    ->badge()
                    ->color(fn (TenantContract $record): string => static::documentTypeColor((string) static::classificationFor($record)['category'])),

                TextColumn::make('place_token')
                    ->label('Токен места')
                    ->state(fn (TenantContract $record): string => (string) (static::classificationFor($record)['place_token'] ?: '—'))
                    ->toggleable(),

                TextColumn::make('document_date')
                    ->label('Дата из номера')
                    ->state(fn (TenantContract $record): string => static::formatClassifierDate(static::classificationFor($record)['document_date'] ?? null))
                    ->toggleable(),

                TextColumn::make('market_space_link')
                    ->label('Текущее место')
                    ->state(fn (TenantContract $record): string => static::spaceLabel($record))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('signed_at')
                    ->label('Подписан')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Статус договора')
                    ->formatStateUsing(fn (?string $state): string => static::contractStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => static::contractStatusColor($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус договора')
                    ->options(static function (): array {
                        $query = TenantContract::query()
                            ->whereNotNull('status')
                            ->where('status', '!=', '')
                            ->distinct()
                            ->orderBy('status');

                        $user = Filament::auth()->user();

                        if ($user?->isSuperAdmin()) {
                            $selectedMarketId = static::selectedMarketIdFromSession();

                            if (filled($selectedMarketId)) {
                                $query->where('market_id', (int) $selectedMarketId);
                            }
                        } elseif ($user?->market_id) {
                            $query->where('market_id', (int) $user->market_id);
                        }

                        $values = $query->pluck('status', 'status')->all();

                        $options = [];
                        foreach ($values as $value) {
                            $value = (string) $value;
                            $options[$value] = static::contractStatusLabel($value);
                        }

                        return $options;
                    }),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_active', true),
                        false: fn (Builder $query) => $query->where('is_active', false),
                        blank: fn (Builder $query) => $query,
                    ),

                TernaryFilter::make('has_market_space')
                    ->label('Привязка к месту')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('market_space_id'),
                        false: fn (Builder $query) => $query->whereNull('market_space_id'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(null);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantContracts::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'market:id,name',
                'tenant:id,name,short_name',
                'marketSpace:id,number,display_name',
            ]);

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if ($user->hasAnyRole(['market-admin', 'market-manager']) && $user->market_id) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['market-admin', 'market-manager'])
            && (bool) $user->market_id;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * @return array{
     *   category: string,
     *   label: string,
     *   actionable: bool,
     *   normalized: string,
     *   matched_rule: ?string,
     *   place_token: ?string,
     *   document_date: ?string
     * }
     */
    private static function classificationFor(TenantContract $record): array
    {
        $cacheKey = implode(':', [
            (string) $record->getKey(),
            md5((string) ($record->number ?? '')),
        ]);

        if (! isset(static::$classificationCache[$cacheKey])) {
            static::$classificationCache[$cacheKey] = app(ContractDocumentClassifier::class)
                ->classify((string) ($record->number ?? ''));
        }

        return static::$classificationCache[$cacheKey];
    }

    /**
     * @return array{label: string, color: string}
     */
    private static function orderingMeta(TenantContract $record): array
    {
        $classified = static::classificationFor($record);
        $hasToken = filled($classified['place_token'] ?? null);
        $hasDate = filled($classified['document_date'] ?? null);
        $hasSpace = filled($record->market_space_id);

        if (! (bool) ($classified['actionable'] ?? false)) {
            return [
                'label' => 'Вне цепочки',
                'color' => 'gray',
            ];
        }

        if ($hasToken && $hasDate && $hasSpace) {
            return [
                'label' => 'Готов',
                'color' => 'success',
            ];
        }

        if ($hasToken && $hasDate) {
            return [
                'label' => 'Ждёт привязки',
                'color' => 'warning',
            ];
        }

        if ($hasToken || $hasDate) {
            return [
                'label' => 'Частично',
                'color' => 'warning',
            ];
        }

        return [
            'label' => 'Нужно разобрать',
            'color' => 'danger',
        ];
    }

    private static function documentTypeColor(string $category): string
    {
        return match ($category) {
            'primary_contract' => 'success',
            'supplemental_document', 'service_document' => 'warning',
            'penalty_document', 'non_rent_document' => 'danger',
            'placeholder_document' => 'gray',
            default => 'gray',
        };
    }

    private static function formatClassifierDate(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        if (preg_match('/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})$/', (string) $value, $matches) === 1) {
            return "{$matches['d']}.{$matches['m']}.{$matches['y']}";
        }

        return (string) $value;
    }

    private static function contractStatusLabel(?string $state): string
    {
        return match (trim((string) $state)) {
            '' => '—',
            'draft' => 'Черновик',
            'active' => 'Активен',
            'paused' => 'Приостановлен',
            'terminated' => 'Расторгнут',
            'archived' => 'Архив',
            default => (string) $state,
        };
    }

    private static function contractStatusColor(?string $state): string
    {
        return match (trim((string) $state)) {
            'active' => 'success',
            'paused' => 'warning',
            'terminated' => 'danger',
            'archived' => 'gray',
            default => 'gray',
        };
    }

    private static function spaceLabel(TenantContract $record): string
    {
        $displayName = trim((string) ($record->marketSpace?->display_name ?? ''));
        $number = trim((string) ($record->marketSpace?->number ?? ''));

        if ($displayName !== '' && $number !== '' && $displayName !== $number) {
            return $displayName . ' · ' . $number;
        }

        if ($displayName !== '') {
            return $displayName;
        }

        if ($number !== '') {
            return $number;
        }

        return '—';
    }
}
