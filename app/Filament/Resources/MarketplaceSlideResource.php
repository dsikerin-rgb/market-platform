<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceSlideResource\Pages;
use App\Models\MarketplaceSlide;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceSlideResource extends BaseResource
{
    protected static ?string $model = MarketplaceSlide::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Слайд маркетплейса';

    protected static ?string $pluralModelLabel = 'Слайды маркетплейса';

    protected static ?string $navigationLabel = 'Слайды';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 2;

    protected static function selectedMarketIdFromSession(): ?int
    {
        $value = session('dashboard_market_id');

        if (! filled($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id");
        }

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'title',
            'badge',
            'description',
            'market.name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $marketFields = [];

        if ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            if ($selectedMarketId) {
                $marketFields[] = Forms\Components\Hidden::make('market_id')
                    ->default((int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $marketFields[] = Forms\Components\Select::make('market_id')
                    ->label('Ярмарка')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload();
            }
        } else {
            $marketFields[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        return $schema->components([
            Section::make('Контент')
                ->description('Слайды — это управляемый промо-слой главной страницы. Они не заменяют акции и праздники из календаря.')
                ->schema([
                    ...$marketFields,
                    Forms\Components\TextInput::make('title')
                        ->label('Заголовок')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('badge')
                        ->label('Чипс / категория')
                        ->maxLength(255),

                    Forms\Components\Select::make('theme')
                        ->label('Тема')
                        ->options([
                            'info' => 'Инфо',
                            'buyer' => 'Покупатели',
                            'seller' => 'Продавцы',
                            'partner' => 'Партнёры',
                        ])
                        ->default('info')
                        ->required(),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('image_path')
                        ->label('Изображение')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('marketplace/slides')
                        ->visibility('public')
                        ->maxSize(5120)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Переход и показ')
                ->schema([
                    Forms\Components\TextInput::make('cta_label')
                        ->label('Текст кнопки')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('cta_url')
                        ->label('Ссылка кнопки')
                        ->maxLength(2048),

                    Forms\Components\Select::make('placement')
                        ->label('Зона размещения')
                        ->options([
                            'home_info_carousel' => 'Главная: информационный слайдер',
                        ])
                        ->default('home_info_carousel')
                        ->required(),

                    Forms\Components\Select::make('audience')
                        ->label('Аудитория')
                        ->options([
                            'all' => 'Все',
                            'buyers' => 'Покупатели',
                            'sellers' => 'Продавцы',
                            'partners' => 'Партнёры',
                        ])
                        ->default('all')
                        ->required(),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Активен')
                        ->default(true),

                    Forms\Components\DateTimePicker::make('starts_at')
                        ->label('Показ с')
                        ->seconds(false),

                    Forms\Components\DateTimePicker::make('ends_at')
                        ->label('Показ до')
                        ->seconds(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('market.name')
                    ->label('Ярмарка')
                    ->visible(fn () => (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                    ->sortable(),

                ImageColumn::make('image_path')
                    ->label('Изображение')
                    ->disk('public')
                    ->square(),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('badge')
                    ->label('Чипс')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('theme')
                    ->label('Тема')
                    ->badge(),

                TextColumn::make('placement')
                    ->label('Зона')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->recordUrl(fn (MarketplaceSlide $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null)
            ->actions([
                tap(\Filament\Actions\EditAction::make()->label('Редактировать'), function ($action): void {
                    if (method_exists($action, 'slideOver')) {
                        $action->slideOver();
                    }

                    if (method_exists($action, 'modalWidth')) {
                        $action->modalWidth('5xl');
                    }
                }),
                \Filament\Actions\DeleteAction::make()->label('Удалить'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketplaceSlides::route('/'),
            'create' => Pages\CreateMarketplaceSlide::route('/create'),
            'edit' => Pages\EditMarketplaceSlide::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return $selectedMarketId
                ? $query->where('market_id', $selectedMarketId)
                : $query;
        }

        return $query->where('market_id', (int) ($user->market_id ?? 0));
    }

    public static function canViewAny(): bool
    {
        return static::canManageSlides();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if (! static::canManageSlides()) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return ! method_exists($user, 'can') || $user->can('marketplace.slides.create') || $user->can('marketplace.slides.update');
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $record instanceof MarketplaceSlide) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ((int) ($user->market_id ?? 0) <= 0 || (int) $record->market_id !== (int) $user->market_id) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('market-admin')) {
            return true;
        }

        return ! method_exists($user, 'can') || $user->can('marketplace.slides.update');
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $record instanceof MarketplaceSlide) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ((int) ($user->market_id ?? 0) <= 0 || (int) $record->market_id !== (int) $user->market_id) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('market-admin')) {
            return true;
        }

        return ! method_exists($user, 'can') || $user->can('marketplace.slides.delete');
    }

    protected static function canManageSlides(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $hasMarket = (int) ($user->market_id ?? 0) > 0;

        if (! $hasMarket) {
            return false;
        }

        $hasRoleAccess = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $hasPermissionAccess = method_exists($user, 'can') && (
            $user->can('marketplace.slides.viewAny')
            || $user->can('marketplace.slides.view')
            || $user->can('marketplace.slides.create')
            || $user->can('marketplace.slides.update')
            || $user->can('marketplace.slides.delete')
        );

        return $hasRoleAccess || $hasPermissionAccess;
    }
}
