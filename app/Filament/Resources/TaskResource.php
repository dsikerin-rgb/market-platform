<?php
# app/Filament/Resources/TaskResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskResource\Pages;
use App\Filament\Resources\TaskResource\RelationManagers\TaskAttachmentsRelationManager;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Задача';

    protected static ?string $pluralModelLabel = 'Задачи';

    protected static ?string $navigationLabel = 'Задачи';

    // Задачи + Обращения в одной группе
    protected static \UnitEnum|string|null $navigationGroup = 'Оперативная работа';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $components = [];

        if ((bool) $user && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $components[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $components[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->dehydrated(true);
            }
        } else {
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        $formFields = [
            Forms\Components\TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->label('Описание')
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    Task::STATUS_NEW => 'Новая',
                    Task::STATUS_IN_PROGRESS => 'В работе',
                    Task::STATUS_ON_HOLD => 'На паузе',
                    Task::STATUS_COMPLETED => 'Завершена',
                    Task::STATUS_CANCELLED => 'Отменена',
                ])
                ->default(Task::STATUS_NEW)
                ->required(),

            Forms\Components\Select::make('priority')
                ->label('Приоритет')
                ->options([
                    Task::PRIORITY_LOW => 'Низкий',
                    Task::PRIORITY_NORMAL => 'Обычный',
                    Task::PRIORITY_HIGH => 'Высокий',
                    Task::PRIORITY_URGENT => 'Критичный',
                ])
                ->default(Task::PRIORITY_NORMAL)
                ->required(),

            Forms\Components\DateTimePicker::make('due_at')
                ->label('Дедлайн'),

            Forms\Components\Select::make('assignee_id')
                ->label('Исполнитель')
                ->relationship('assignee', 'name', function (Builder $query) use ($user) {
                    return static::limitUsersToMarket($query, $user);
                })
                ->searchable()
                ->preload()
                ->nullable(),

            Forms\Components\Select::make('participants')
                ->label('Участники/наблюдатели')
                ->relationship('participants', 'name', function (Builder $query) use ($user) {
                    return static::limitUsersToMarket($query, $user);
                })
                ->searchable()
                ->preload()
                ->multiple(),

            Forms\Components\Hidden::make('created_by_user_id')
                ->default(fn () => $user?->id)
                ->dehydrated(true),

            Forms\Components\Placeholder::make('source_label')
                ->label('Источник')
                ->content(function (?Task $record): string {
                    return $record?->source_label ?? '—';
                }),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components([
                    ...$components,
                    ...$formFields,
                ]),
            ]);
        }

        return $schema->components([
            ...$components,
            ...$formFields,
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('title')
                    ->label('Название')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Task::STATUS_NEW => 'Новая',
                        Task::STATUS_IN_PROGRESS => 'В работе',
                        Task::STATUS_ON_HOLD => 'На паузе',
                        Task::STATUS_COMPLETED => 'Завершена',
                        Task::STATUS_CANCELLED => 'Отменена',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Task::PRIORITY_LOW => 'Низкий',
                        Task::PRIORITY_NORMAL => 'Обычный',
                        Task::PRIORITY_HIGH => 'Высокий',
                        Task::PRIORITY_URGENT => 'Критичный',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label('Исполнитель')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('due_at')
                    ->label('Дедлайн')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('source_label')
                    ->label('Источник'),

                TextColumn::make('comments_count')
                    ->label('Комментарии')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('my_tasks')
                    ->label('Мои задачи')
                    ->trueLabel('Только мои')
                    ->falseLabel('Кроме моих')
                    ->queries(
                        true: fn (Builder $query) => $user?->id
                            ? $query->where('assignee_id', $user->id)
                            : $query->whereRaw('1 = 0'),
                        false: fn (Builder $query) => $user?->id
                            ? $query->where(function (Builder $inner) use ($user) {
                                $inner->whereNull('assignee_id')
                                    ->orWhere('assignee_id', '!=', $user->id);
                            })
                            : $query,
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        Task::STATUS_NEW => 'Новая',
                        Task::STATUS_IN_PROGRESS => 'В работе',
                        Task::STATUS_ON_HOLD => 'На паузе',
                        Task::STATUS_COMPLETED => 'Завершена',
                        Task::STATUS_CANCELLED => 'Отменена',
                    ]),

                SelectFilter::make('priority')
                    ->label('Приоритет')
                    ->options([
                        Task::PRIORITY_LOW => 'Низкий',
                        Task::PRIORITY_NORMAL => 'Обычный',
                        Task::PRIORITY_HIGH => 'Высокий',
                        Task::PRIORITY_URGENT => 'Критичный',
                    ]),

                TernaryFilter::make('overdue')
                    ->label('Просроченные')
                    ->trueLabel('Только просроченные')
                    ->falseLabel('Без просрочки')
                    ->queries(
                        true: fn (Builder $query) => $query->overdue(),
                        false: fn (Builder $query) => $query->where(function (Builder $inner) {
                            $inner->whereNull('due_at')
                                ->orWhere('due_at', '>=', now());
                        }),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordUrl(fn (Task $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [
            TaskCommentsRelationManager::class,
            TaskAttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('comments');
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (static::isMerchantUser($user)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $user->isSuperAdmin() && ! $user->hasAnyRole(['market-admin', 'market-maintenance'])) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $user->hasAnyRole(['market-admin', 'market-maintenance']);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $user->hasAnyRole(['market-admin', 'market-maintenance']);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id
            && $record->market_id === $user->market_id
            && $user->hasAnyRole(['market-admin', 'market-maintenance']);
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id
            && $record->market_id === $user->market_id
            && $user->hasRole('market-admin');
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $value = session('filament.admin.selected_market_id');

        return filled($value) ? (int) $value : null;
    }

    protected static function limitUsersToMarket(Builder $query, ?User $user): Builder
    {
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
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected static function isMerchantUser(User $user): bool
    {
        return $user->hasAnyRole(['merchant', 'merchant-user']);
    }
}
