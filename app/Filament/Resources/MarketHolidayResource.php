<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketHolidayResource\Pages;
use App\Models\MarketHoliday;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class MarketHolidayResource extends BaseResource
{
    protected static ?string $model = MarketHoliday::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Событие';
    protected static ?string $pluralModelLabel = 'События';
    protected static ?string $navigationLabel = 'События';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 70;

    public static function getModelLabel(): string
    {
        return 'Событие';
    }

    public static function getPluralModelLabel(): string
    {
        return 'События';
    }

    public static function getNavigationLabel(): string
    {
        return 'События';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'title',
            'source',
            'market.name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $marketField = [];

        if ($user && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $marketField[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $marketField[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->hintIcon('heroicon-m-question-mark-circle')
                    ->hintIconTooltip('Выберите рынок, чтобы корректно подставить ответственных в сценариях.')
                    ->dehydrated(true);
            }
        } else {
            $marketField[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        return $schema->components([
            Section::make('Событие')
                ->schema([
                    ...$marketField,

                    Forms\Components\Hidden::make('audience_scope')
                        ->default('staff')
                        ->dehydrated(true),

                    Forms\Components\TextInput::make('title')
                        ->label('Название события')
                        ->required()
                        ->maxLength(255)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Короткое название события, которое увидят сотрудники в календаре и уведомлениях.')
                        ->columnSpan(2),

                    Forms\Components\Select::make('source')
                        ->label('Тип события')
                        ->options([
                            'national_holiday' => 'Государственный праздник',
                            'sanitary_auto' => 'Санитарный день',
                            'promotion' => 'Акция',
                            'market_event' => 'Мероприятие рынка',
                            'maintenance' => 'Технические работы',
                            'other' => 'Другое',
                        ])
                        ->default('market_event')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('От типа зависит набор сценариев и ответственных ролей.'),

                    Forms\Components\FileUpload::make('cover_image')
                        ->label('Изображение события')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('market-holidays/events')
                        ->visibility('public')
                        ->maxSize(5120)
                        ->columnSpan(2),

                    Forms\Components\Placeholder::make('cover_image_preview')
                        ->label('Превью фона')
                        ->content(function (Get $get): HtmlString {
                            $raw = trim((string) ($get('cover_image') ?? ''));

                            if ($raw === '') {
                                return new HtmlString('<span style="color:#94a3b8;">Изображение не загружено</span>');
                            }

                            $url = Str::startsWith($raw, ['http://', 'https://', 'data:', '/'])
                                ? $raw
                                : Storage::disk('public')->url($raw);

                            $safeUrl = e($url);

                            return new HtmlString(
                                '<div style="width:100%;max-width:560px;border:1px solid rgba(148,163,184,.25);border-radius:14px;overflow:hidden;background:#f8fafc;">'
                                . '<img src="' . $safeUrl . '" alt="Preview" style="display:block;width:100%;height:220px;object-fit:cover;">'
                                . '</div>'
                            );
                        })
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('starts_at')
                        ->default(fn () => static::resolvePrefilledStartDate())
                        ->label('Дата начала')
                        ->required(),

                    Forms\Components\DatePicker::make('ends_at')
                        ->label('Дата окончания')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Можно оставить пустым для события на один день.'),

                    Forms\Components\Toggle::make('all_day')
                        ->label('Весь день')
                        ->default(true),

                    Forms\Components\TextInput::make('notify_before_days')
                        ->label('Уведомить за (дней)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(90)
                        ->suffix('дн.')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Если пусто, используется настройка рынка.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Сценарии автоматизации')
                ->description('Система может автоматически создавать и обновлять задачи подготовки по этому событию.')
                ->schema([
                    Forms\Components\Toggle::make('audience_payload.scenarios.enabled_tasks')
                        ->label('Автоматически ставить задачи')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\Toggle::make('audience_payload.scenarios.communication_plan')
                        ->label('Формировать план информирования')
                        ->default(true)
                        ->inline(false)
                        ->visible(fn (Get $get): bool => (string) $get('source') !== 'sanitary_auto'),

                    Forms\Components\Toggle::make('audience_payload.scenarios.ad_materials')
                        ->label('Подготовить рекламные материалы')
                        ->default(true)
                        ->inline(false)
                        ->visible(fn (Get $get): bool => (string) $get('source') !== 'sanitary_auto'),

                    Forms\Components\Toggle::make('audience_payload.scenarios.auto_ai_drafts')
                        ->label('Черновики текстов автоматически (AI)')
                        ->default(true)
                        ->inline(false)
                        ->visible(fn (Get $get): bool => (bool) $get('audience_payload.scenarios.ad_materials')),

                    Forms\Components\TextInput::make('audience_payload.scenarios.lead_days')
                        ->label('Горизонт подготовки')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(30)
                        ->suffix('дн.')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Через сколько дней до события создавать/обновлять сценарные задачи. Если пусто — берется notify_before_days.'),

                    Forms\Components\CheckboxList::make('audience_payload.scenarios.channels')
                        ->label('Каналы коммуникации')
                        ->options([
                            'in_app' => 'В кабинете',
                            'email' => 'Email',
                            'telegram' => 'Telegram',
                            'sms' => 'SMS',
                            'vk' => 'VK',
                            'media' => 'СМИ',
                        ])
                        ->columns(3)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('audience_payload.scenarios.responsible_user_ids')
                        ->label('Ответственные сотрудники (опционально)')
                        ->options(function (Get $get): array {
                            $marketId = $get('market_id');

                            if (! is_numeric($marketId) || (int) $marketId <= 0) {
                                return [];
                            }

                            return User::query()
                                ->where('market_id', (int) $marketId)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('audience_payload.scenarios.note')
                        ->label('Комментарий к сценарию')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeUpcoming($query))
            ->defaultSort('starts_at', 'asc')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->html()
                    ->formatStateUsing(function ($state, MarketHoliday $record): string {
                        $title = e((string) $state);
                        $color = static::isNationalHoliday($record)
                            ? '#ef4444'
                            : (static::isPromotion($record) ? '#0284c7' : 'inherit');

                        return "<span style=\"color: {$color}; font-weight: 600;\">{$title}</span>";
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date()
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date()
                    ->sortable(),

                IconColumn::make('all_day')
                    ->label('Весь день')
                    ->boolean(),

                TextColumn::make('notify_before_days')
                    ->label('Уведомить за')
                    ->suffix(' дн.')
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Источник')
                    ->sortable(),

                TextColumn::make('audience_scope')
                    ->label('Аудитория')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (MarketHoliday $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()
                ->label('')
                ->icon('heroicon-o-pencil-square')
                ->tooltip('Редактировать')
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('')
                ->icon('heroicon-o-pencil-square')
                ->tooltip('Редактировать')
                ->iconButton();
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->iconButton();
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketHolidays::route('/'),
            'create' => Pages\CreateMarketHoliday::route('/create'),
            'edit' => Pages\EditMarketHoliday::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
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

        if ($user->market_id) {
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

        return $user->market_id && $user->hasRole('market-admin');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! ($record instanceof MarketHoliday)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id
            && (int) $record->market_id === (int) $user->market_id
            && $user->hasRole('market-admin');
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    protected static function resolvePrefilledStartDate(): ?string
    {
        $date = request()->query('date');

        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value = session("filament.{$panelId}.selected_market_id");

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    public static function scopeUpcoming(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where(function (Builder $q) use ($today): void {
            $q->where(function (Builder $sub) use ($today): void {
                $sub->whereNull('ends_at')
                    ->whereDate('starts_at', '>=', $today);
            })->orWhere(function (Builder $sub) use ($today): void {
                $sub->whereNotNull('ends_at')
                    ->whereDate('ends_at', '>=', $today);
            });
        });
    }

    public static function isNationalHoliday(MarketHoliday $record): bool
    {
        $source = mb_strtolower(trim((string) ($record->source ?? '')), 'UTF-8');

        return in_array($source, ['national_holiday', 'file'], true);
    }

    public static function isPromotion(MarketHoliday $record): bool
    {
        $source = mb_strtolower(trim((string) ($record->source ?? '')), 'UTF-8');

        return in_array($source, ['promotion', 'promo'], true);
    }
}
