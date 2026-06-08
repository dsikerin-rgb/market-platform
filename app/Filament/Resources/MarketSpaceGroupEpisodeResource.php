<?php
# app/Filament/Resources/MarketSpaceGroupEpisodeResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceGroupEpisodeResource\Pages;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\MarketSpaceGroupEpisodeChild;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MarketSpaceGroupEpisodeResource extends BaseResource
{
    protected static ?string $model = MarketSpaceGroupEpisode::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $modelLabel = 'Эпизод группы мест';

    protected static ?string $pluralModelLabel = 'Эпизоды групп мест';

    protected static ?string $navigationLabel = 'Эпизоды групп мест';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 96;

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ревизия и диагностика';
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema->components([
            Section::make('Период действия группы')
                ->description('Эпизод фиксирует, из каких физических мест состояла parent-группа в конкретный период. Пока это справочный слой: он ничего не пересчитывает автоматически.')
                ->schema([
                    $user?->isSuperAdmin()
                        ? Forms\Components\Select::make('market_id')
                            ->label('Рынок')
                            ->relationship('market', 'name')
                            ->default(fn (): ?int => static::selectedMarketIdFromSession())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->reactive()
                        : Forms\Components\Hidden::make('market_id')
                            ->default(fn (): ?int => $user?->market_id),

                    Forms\Components\Select::make('parent_market_space_id')
                        ->label('Parent-группа')
                        ->options(function ($get, ?MarketSpaceGroupEpisode $record) use ($user): array {
                            $marketId = $get('market_id') ?: $record?->market_id ?: $user?->market_id;
                            if (! $marketId) {
                                return [];
                            }

                            return MarketSpace::query()
                                ->where('market_id', (int) $marketId)
                                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT)
                                ->orderBy('number')
                                ->orderBy('display_name')
                                ->get(['id', 'number', 'display_name', 'code'])
                                ->mapWithKeys(fn (MarketSpace $space): array => [
                                    (int) $space->id => static::spaceLabel($space),
                                ])
                                ->all();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->helperText('Договоры по child-местам трактуются через parent-группу. Состав ниже должен описывать именно эту группу.'),

                    Forms\Components\DatePicker::make('valid_from')
                        ->label('Действует с')
                        ->native(false),

                    Forms\Components\DatePicker::make('valid_to')
                        ->label('Действует по')
                        ->native(false)
                        ->afterOrEqual('valid_from'),

                    Forms\Components\Select::make('source')
                        ->label('Источник')
                        ->options([
                            'manual' => 'Вручную',
                            'contract' => 'По договору',
                            'accrual' => 'По начислениям',
                            'import' => 'Импорт',
                            'backfill_current' => 'Снимок текущего состава',
                            'test' => 'Тест',
                        ])
                        ->default('manual')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('source_contract_id')
                        ->label('Опорный договор')
                        ->relationship(
                            name: 'sourceContract',
                            titleAttribute: 'number',
                            modifyQueryUsing: function (Builder $query, $get, ?MarketSpaceGroupEpisode $record) use ($user): Builder {
                                $marketId = $get('market_id') ?: $record?->market_id ?: $user?->market_id;

                                return $query
                                    ->when($marketId, fn (Builder $contractQuery): Builder => $contractQuery->where('market_id', (int) $marketId))
                                    ->orderByDesc('starts_at')
                                    ->orderBy('number');
                            },
                        )
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Комментарий')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Состав')
                ->description('Указывайте физические места, которые входили в группу в этом периоде. Parent-группу сюда добавлять не нужно.')
                ->schema([
                    Forms\Components\Repeater::make('children')
                        ->relationship('children')
                        ->label('Child-места')
                        ->schema([
                            Forms\Components\Select::make('child_market_space_id')
                                ->label('Место')
                                ->options(function ($get, mixed $record) use ($user): array {
                                    $episode = $record instanceof MarketSpaceGroupEpisode
                                        ? $record
                                        : ($record instanceof MarketSpaceGroupEpisodeChild ? $record->episode : null);

                                    $marketId = $get('../../market_id') ?: $episode?->market_id ?: $user?->market_id;
                                    $parentId = (int) ($get('../../parent_market_space_id') ?: $episode?->parent_market_space_id ?: 0);
                                    if (! $marketId) {
                                        return [];
                                    }

                                    return MarketSpace::query()
                                        ->where('market_id', (int) $marketId)
                                        ->where(function (Builder $query): void {
                                            $query
                                                ->whereNull('space_group_role')
                                                ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_PARENT);
                                        })
                                        ->when($parentId > 0, fn (Builder $query): Builder => $query->whereKeyNot($parentId))
                                        ->orderBy('number')
                                        ->orderBy('display_name')
                                        ->get(['id', 'number', 'display_name', 'code'])
                                        ->mapWithKeys(fn (MarketSpace $space): array => [
                                            (int) $space->id => static::spaceLabel($space),
                                        ])
                                        ->all();
                                })
                                ->required()
                                ->searchable()
                                ->preload(),

                            Forms\Components\TextInput::make('slot')
                                ->label('Слот')
                                ->maxLength(64),

                            Forms\Components\TextInput::make('area_sqm')
                                ->label('Площадь, м²')
                                ->numeric()
                                ->inputMode('decimal'),

                            Forms\Components\TextInput::make('sort_order')
                                ->label('Порядок')
                                ->numeric()
                                ->default(0),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Добавить место')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($user): Builder {
                return $query
                    ->withCount('children')
                    ->when(
                        $user && ! $user->isSuperAdmin(),
                        fn (Builder $marketQuery): Builder => $marketQuery->where('market_id', (int) $user->market_id),
                    );
            })
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->visible(fn (): bool => (bool) $user?->isSuperAdmin()),

                TextColumn::make('parentMarketSpace.number')
                    ->label('Группа')
                    ->formatStateUsing(fn ($state, MarketSpaceGroupEpisode $record): string => $record->parentMarketSpace ? static::spaceLabel($record->parentMarketSpace) : '—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('period')
                    ->label('Период')
                    ->state(fn (MarketSpaceGroupEpisode $record): string => ($record->valid_from?->format('d.m.Y') ?? '—').' - '.($record->valid_to?->format('d.m.Y') ?? '—')),

                TextColumn::make('children_count')
                    ->label('Мест')
                    ->counts('children')
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Источник')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual' => 'Вручную',
                        'contract' => 'Договор',
                        'accrual' => 'Начисления',
                        'import' => 'Импорт',
                        'backfill_current' => 'Снимок текущего состава',
                        'test' => 'Тест',
                        default => $state ?: '—',
                    }),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('valid_from', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketSpaceGroupEpisodes::route('/'),
            'create' => Pages\CreateMarketSpaceGroupEpisode::route('/create'),
            'edit' => Pages\EditMarketSpaceGroupEpisode::route('/{record}/edit'),
        ];
    }

    private static function spaceLabel(MarketSpace $space): string
    {
        $number = trim((string) ($space->number ?? ''));
        $displayName = trim((string) ($space->display_name ?? ''));
        $code = trim((string) ($space->code ?? ''));

        if ($number !== '' && $displayName !== '' && $number !== $displayName) {
            return $number.' / '.$displayName;
        }

        return $number !== '' ? $number : ($displayName !== '' ? $displayName : ($code !== '' ? $code : '#'.(int) $space->id));
    }
}
