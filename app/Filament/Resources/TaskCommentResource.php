<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskCommentResource\Pages;
use App\Models\TaskComment;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskCommentResource extends Resource
{
    protected static ?string $model = TaskComment::class;

    protected static ?string $modelLabel = 'Комментарий задачи';
    protected static ?string $pluralModelLabel = 'Комментарии задач';

    /**
     * ВАЖНО: убираем из левого меню.
     * Комментарии должны жить внутри задачи (RelationManager), а не отдельным пунктом навигации.
     * Доступ к ресурсу остаётся по URL.
     */
    protected static bool $shouldRegisterNavigation = false;

    // Оставляем метаданные (не влияют на меню при shouldRegisterNavigation=false)
    protected static ?string $navigationLabel = 'Комментарии задач';
    protected static \UnitEnum|string|null $navigationGroup = 'Задачи';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

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

        $formFields = [
            Forms\Components\Select::make('task_id')
                ->label('Задача')
                ->relationship('task', 'title', function (Builder $query) use ($user) {
                    if (! $user) {
                        return $query->whereRaw('1 = 0');
                    }

                    if ($user->isSuperAdmin()) {
                        $selectedMarketId = static::selectedMarketIdFromSession();

                        return filled($selectedMarketId)
                            ? $query->where('market_id', $selectedMarketId)
                            : $query;
                    }

                    if ($user->market_id) {
                        return $query->where('market_id', $user->market_id);
                    }

                    return $query->whereRaw('1 = 0');
                })
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('user_id')
                ->label('Автор')
                ->relationship('author', 'name', function (Builder $query) use ($user) {
                    if (! $user) {
                        return $query->whereRaw('1 = 0');
                    }

                    if ($user->isSuperAdmin()) {
                        $selectedMarketId = static::selectedMarketIdFromSession();

                        return filled($selectedMarketId)
                            ? $query->where('market_id', $selectedMarketId)
                            : $query;
                    }

                    if ($user->market_id) {
                        return $query->where('market_id', $user->market_id);
                    }

                    return $query->whereRaw('1 = 0');
                })
                ->required()
                ->searchable()
                ->preload(),

            Forms\Components\Textarea::make('body')
                ->label('Комментарий')
                ->rows(4)
                ->required(),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components($formFields),
            ]);
        }

        return $schema->components($formFields);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('task.market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('task.title')
                    ->label('Задача')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('author.name')
                    ->label('Автор')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('body')
                    ->label('Комментарий')
                    ->limit(50),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (TaskComment $record): ?string => static::canEdit($record)
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskComments::route('/'),
            'create' => Pages\CreateTaskComment::route('/create'),
            'edit' => Pages\EditTaskComment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('task', function (Builder $query) use ($user) {
            if ($user->isSuperAdmin()) {
                $selectedMarketId = static::selectedMarketIdFromSession();

                return filled($selectedMarketId)
                    ? $query->where('market_id', $selectedMarketId)
                    : $query;
            }

            if ($user->market_id) {
                return $query->where('market_id', $user->market_id);
            }

            return $query->whereRaw('1 = 0');
        });
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $task = $record->task;

        return $task && $user->market_id && $task->market_id === $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $task = $record->task;

        return $task && $user->market_id && $task->market_id === $user->market_id;
    }
}
