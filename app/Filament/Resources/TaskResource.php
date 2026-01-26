<?php

# app/Filament/Resources/TaskResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TaskResource\Pages;
use App\Filament\Resources\TaskResource\RelationManagers\TaskAttachmentsRelationManager;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use App\Models\Market;
use App\Models\Task;
use App\Models\TaskParticipant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Задача';

    protected static ?string $pluralModelLabel = 'Задачи';

    protected static ?string $navigationLabel = 'Задачи';

    protected static \UnitEnum|string|null $navigationGroup = 'Оперативная работа';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        /**
         * Правило рынка:
         * - Рынок видит только super-admin
         * - Create:
         *   - если выбран рынок в панели -> placeholder + hidden market_id
         *   - иначе -> Select market_id
         * - Edit:
         *   - рынок всегда read-only placeholder + hidden market_id (нельзя переносить задачу)
         * - не super-admin -> только hidden market_id
         */
        $marketComponents = [];

        if ($user && $user->isSuperAdmin()) {
            $marketComponents[] = Forms\Components\Placeholder::make('market_context')
                ->label('Рынок')
                ->content(function (?Task $record) use ($selectedMarketId): string {
                    $marketId = $record?->market_id ?: (filled($selectedMarketId) ? (int) $selectedMarketId : null);

                    return static::resolveMarketName($marketId) ?: '—';
                })
                ->helperText('Контекст задачи. На редактировании рынок нельзя менять.')
                ->visible(fn (?Task $record): bool => (bool) $record || filled($selectedMarketId))
                ->columnSpanFull();

            $marketComponents[] = Forms\Components\Hidden::make('market_id')
                ->default(function (?Task $record) use ($selectedMarketId) {
                    if ($record) {
                        return (int) $record->market_id;
                    }

                    return filled($selectedMarketId) ? (int) $selectedMarketId : null;
                })
                ->dehydrated(true)
                ->visible(fn (?Task $record): bool => (bool) $record || filled($selectedMarketId));

            $marketComponents[] = Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->placeholder('Выберите рынок')
                ->relationship('market', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
                ->native(false)
                ->helperText('Если выбрать рынок в панели — здесь он подставится автоматически.')
                ->visible(fn (?Task $record): bool => ! $record && blank($selectedMarketId))
                ->columnSpanFull()
                ->dehydrated(true);
        } else {
            $marketComponents[] = Forms\Components\Hidden::make('market_id')
                ->default(fn (?Task $record) => $record?->market_id ?: $user?->market_id)
                ->dehydrated(true);
        }

        // -------------------------
        // Основные поля
        // -------------------------
        $titleField = Forms\Components\TextInput::make('title')
            ->label('Название задачи')
            ->placeholder('Например: Проверить холодильник в павильоне 12')
            ->required()
            ->maxLength(255)
            ->columnSpanFull();

        $descriptionField = Forms\Components\Textarea::make('description')
            ->label('Описание')
            ->placeholder('Коротко опиши, что нужно сделать. Если важно — добавь детали и критерии готовности.')
            ->rows(6)
            ->autosize()
            ->columnSpanFull();

        // Создателя выставляем только при создании
        $createdByHidden = Forms\Components\Hidden::make('created_by_user_id')
            ->default(fn () => $user?->id)
            ->dehydrated(fn (?Task $record): bool => ! (bool) $record);

        /**
         * Статус:
         * - при создании НЕ спрашиваем (ставим STATUS_NEW скрытым полем)
         * - на редактировании показываем Select
         */
        $statusHiddenOnCreate = Forms\Components\Hidden::make('status')
            ->default(Task::STATUS_NEW)
            ->visible(fn (?Task $record): bool => ! (bool) $record)
            ->dehydrated(fn (?Task $record): bool => ! (bool) $record);

        $statusFieldOnEdit = Forms\Components\Select::make('status')
            ->label('Статус задачи')
            ->options(Task::statusOptions())
            ->required()
            ->native(false)
            ->visible(fn (?Task $record): bool => (bool) $record)
            ->columnSpan(fn (?Task $record): array => [
                'default' => 12,
                'lg' => 6,
            ]);

        // -------------------------
        // Параметры
        // -------------------------
        $priorityField = Forms\Components\Select::make('priority')
            ->label('Приоритет')
            ->options(Task::priorityOptions())
            ->default(Task::PRIORITY_NORMAL)
            ->required()
            ->native(false)
            ->columnSpan([
                'default' => 12,
                'lg' => 6,
            ]);

        $dueAtField = Forms\Components\DateTimePicker::make('due_at')
            ->label('Дедлайн')
            ->seconds(false)
            ->helperText('Можно оставить пустым и назначить позже.')
            ->columnSpan([
                'default' => 12,
                'lg' => 6,
            ]);

        // На create — можно шире, на edit — в пару к дедлайну (чтобы не растягивать форму)
        $assigneeField = Forms\Components\Select::make('assignee_id')
            ->label('Исполнитель')
            ->placeholder('Назначить исполнителя')
            ->relationship('assignee', 'name', function (Builder $query) use ($user) {
                return static::limitUsersToMarket($query, $user);
            })
            ->searchable()
            ->preload()
            ->nullable()
            ->native(false)
            ->helperText('Исполнитель получит уведомление при назначении.')
            ->columnSpan(fn (?Task $record): array => [
                'default' => 12,
                'lg' => $record ? 6 : 12,
            ]);

        // -------------------------
        // Участники: coexecutor / observer
        // -------------------------
        $coexecutorsField = Forms\Components\Select::make('coexecutor_user_ids')
            ->label('Соисполнители')
            ->placeholder('Добавить соисполнителей')
            ->relationship('participants', 'name', function (Builder $query) use ($user) {
                return static::limitUsersToMarket($query, $user);
            })
            ->multiple()
            ->searchable()
            ->preload()
            ->native(false)
            ->helperText('Соисполнители участвуют в выполнении. Если пользователь выбран и тут, и в наблюдателях — он станет соисполнителем.')
            ->dehydrated(false)
            ->columnSpan([
                'default' => 12,
                'lg' => 6,
            ]);

        if (method_exists($coexecutorsField, 'reactive')) {
            $coexecutorsField->reactive();
        }

        if (method_exists($coexecutorsField, 'afterStateUpdated')) {
            $coexecutorsField->afterStateUpdated(function ($state, Set $set, Get $get): void {
                $co = static::normalizeIds((array) $state);
                $obs = static::normalizeIds((array) $get('observer_user_ids'));

                if ($co) {
                    $obs = array_values(array_diff($obs, $co));
                    $set('observer_user_ids', $obs);
                }
            });
        }

        if (method_exists($coexecutorsField, 'afterStateHydrated')) {
            $coexecutorsField->afterStateHydrated(function (Forms\Components\Select $component, ?Task $record): void {
                if (! $record) {
                    $component->state([]);

                    return;
                }

                $ids = $record->participantEntries()
                    ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
                    ->pluck('user_id')
                    ->all();

                $component->state($ids);
            });
        }

        if (method_exists($coexecutorsField, 'saveRelationshipsUsing')) {
            $coexecutorsField->saveRelationshipsUsing(function (?Task $record, $state, Get $get): void {
                if (! $record) {
                    return;
                }

                $observers = (array) $get('observer_user_ids');
                $coexecutors = (array) $state;

                static::syncParticipantsByRole($record, $observers, $coexecutors);
            });
        }

        $observersField = Forms\Components\Select::make('observer_user_ids')
            ->label('Наблюдатели')
            ->placeholder('Добавить наблюдателей')
            ->relationship('participants', 'name', function (Builder $query) use ($user) {
                return static::limitUsersToMarket($query, $user);
            })
            ->multiple()
            ->searchable()
            ->preload()
            ->native(false)
            ->helperText('Наблюдатели видят задачу и получают уведомления, но не считаются исполнителями.')
            ->dehydrated(false)
            ->columnSpan([
                'default' => 12,
                'lg' => 6,
            ]);

        if (method_exists($observersField, 'reactive')) {
            $observersField->reactive();
        }

        if (method_exists($observersField, 'afterStateUpdated')) {
            $observersField->afterStateUpdated(function ($state, Set $set, Get $get): void {
                $obs = static::normalizeIds((array) $state);
                $co = static::normalizeIds((array) $get('coexecutor_user_ids'));

                if ($co) {
                    $obs = array_values(array_diff($obs, $co));
                    $set('observer_user_ids', $obs);
                }
            });
        }

        if (method_exists($observersField, 'afterStateHydrated')) {
            $observersField->afterStateHydrated(function (Forms\Components\Select $component, ?Task $record): void {
                if (! $record) {
                    $component->state([]);

                    return;
                }

                $ids = $record->participantEntries()
                    ->where('role', Task::PARTICIPANT_ROLE_OBSERVER)
                    ->pluck('user_id')
                    ->all();

                $component->state($ids);
            });
        }

        if (method_exists($observersField, 'saveRelationshipsUsing')) {
            $observersField->saveRelationshipsUsing(function (?Task $record, $state, Get $get): void {
                if (! $record) {
                    return;
                }

                $observers = (array) $state;
                $coexecutors = (array) $get('coexecutor_user_ids');

                static::syncParticipantsByRole($record, $observers, $coexecutors);
            });
        }

        $sourceLabel = Forms\Components\Placeholder::make('source_label')
            ->label('Источник')
            ->content(fn (?Task $record): string => $record?->source_label ?? '—')
            ->visible(fn (?Task $record): bool => (bool) $record && filled($record->source_type) && filled($record->source_id))
            ->columnSpanFull();

        /**
         * Wizard:
         * - На create статус не спрашиваем (Hidden статус = NEW)
         * - На edit статус доступен
         *
         * Дизайн:
         * - Каждый шаг ограничен по ширине: max-w-5xl mx-auto
         * - На lg — логичные 2 колонки для “коротких” полей
         */
        return $schema->components([
            Wizard::make([
                Step::make('Основное')
                    ->description('Что нужно сделать')
                    ->schema([
                        Section::make('Данные задачи')
                            ->schema([
                                ...$marketComponents,
                                $titleField,
                                $descriptionField,
                                $createdByHidden,
                            ])
                            ->columns(12)
                            ->extraAttributes(['class' => 'max-w-5xl mx-auto']),
                    ]),

                Step::make('Назначение')
                    ->description('Приоритет, сроки и исполнитель')
                    ->schema([
                        Section::make('Параметры')
                            ->schema([
                                $statusHiddenOnCreate,
                                $statusFieldOnEdit,

                                $priorityField,
                                $dueAtField,
                                $assigneeField,
                            ])
                            ->columns(12)
                            ->extraAttributes(['class' => 'max-w-5xl mx-auto']),
                    ]),

                Step::make('Участники')
                    ->description('Соисполнители и наблюдатели')
                    ->schema([
                        Section::make('Участники')
                            ->schema([
                                $coexecutorsField,
                                $observersField,
                                $sourceLabel,
                            ])
                            ->columns(12)
                            ->extraAttributes(['class' => 'max-w-5xl mx-auto']),
                    ]),
            ])
                ->skippable(false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $marketColumn = TextColumn::make('market.name')
            ->label('Рынок')
            ->sortable()
            ->searchable()
            ->visible(fn () => (bool) $user && $user->isSuperAdmin());

        $marketColumn = static::toggleable($marketColumn, true);

        $titleColumn = TextColumn::make('title')
            ->label('Название')
            ->sortable()
            ->searchable();

        if (method_exists($titleColumn, 'wrap')) {
            $titleColumn->wrap();
        }

        if (method_exists($titleColumn, 'description')) {
            $titleColumn->description(function (Task $record): ?string {
                if (blank($record->description)) {
                    return null;
                }

                return Str::limit((string) $record->description, 90);
            });
        }

        $statusColumn = TextColumn::make('status')
            ->label('Статус')
            ->formatStateUsing(fn (?string $state): string => Task::STATUS_LABELS[$state] ?? (string) $state)
            ->sortable();

        if (method_exists($statusColumn, 'badge')) {
            $statusColumn->badge();
        }

        if (method_exists($statusColumn, 'color')) {
            $statusColumn->color(fn (?string $state): string => match ($state) {
                Task::STATUS_NEW => 'gray',
                Task::STATUS_IN_PROGRESS => 'warning',
                Task::STATUS_ON_HOLD => 'gray',
                Task::STATUS_COMPLETED => 'success',
                Task::STATUS_CANCELLED => 'danger',
                default => 'gray',
            });
        }

        $priorityColumn = TextColumn::make('priority')
            ->label('Приоритет')
            ->formatStateUsing(fn (?string $state): string => Task::PRIORITY_LABELS[$state] ?? (string) $state)
            ->sortable();

        if (method_exists($priorityColumn, 'badge')) {
            $priorityColumn->badge();
        }

        if (method_exists($priorityColumn, 'color')) {
            $priorityColumn->color(fn (?string $state): string => match ($state) {
                Task::PRIORITY_LOW => 'gray',
                Task::PRIORITY_NORMAL => 'gray',
                Task::PRIORITY_HIGH => 'warning',
                Task::PRIORITY_URGENT => 'danger',
                default => 'gray',
            });
        }

        $assigneeColumn = TextColumn::make('assignee.name')
            ->label('Исполнитель')
            ->sortable()
            ->searchable();

        $dueColumn = TextColumn::make('due_at')
            ->label('Дедлайн')
            ->formatStateUsing(fn ($state): string => $state ? $state->format('d.m.Y H:i') : '—')
            ->sortable();

        if (method_exists($dueColumn, 'tooltip')) {
            $dueColumn->tooltip(fn ($state): ?string => $state ? $state->diffForHumans() : null);
        }

        if (method_exists($dueColumn, 'color')) {
            $dueColumn->color(function ($state, ?Task $record = null): string {
                if (! $state) {
                    return 'gray';
                }

                if ($record && in_array($record->status, Task::CLOSED_STATUSES, true)) {
                    return 'gray';
                }

                if ($state->isPast()) {
                    return 'danger';
                }

                if ($state->diffInHours(now(), false) <= 24) {
                    return 'warning';
                }

                return 'primary';
            });
        }

        $sourceColumn = TextColumn::make('source_label')
            ->label('Источник');

        $sourceColumn = static::toggleable($sourceColumn, true);

        $commentsColumn = TextColumn::make('comments_count')
            ->label('Комм.')
            ->sortable();

        if (method_exists($commentsColumn, 'alignCenter')) {
            $commentsColumn->alignCenter();
        }

        if (method_exists($commentsColumn, 'tooltip')) {
            $commentsColumn->tooltip('Количество комментариев');
        }

        $table = $table
            ->columns([
                $marketColumn,
                $titleColumn,
                $statusColumn,
                $priorityColumn,
                $assigneeColumn,
                $dueColumn,
                $sourceColumn,
                $commentsColumn,
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
                    ->options(Task::statusOptions()),

                SelectFilter::make('priority')
                    ->label('Приоритет')
                    ->options(Task::priorityOptions()),

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
                ->requiresConfirmation()
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->requiresConfirmation()
                ->iconButton();
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
        $query = parent::getEloquentQuery()
            ->withCount('comments')
            ->workOrder();

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

        return (bool) $user->market_id && $user->hasAnyRole(['market-admin', 'market-maintenance']);
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

        return (bool) $user->market_id && $user->hasAnyRole(['market-admin', 'market-maintenance']);
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
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session("filament.{$panelId}.selected_market_id");

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    protected static function resolveMarketName(?int $marketId): ?string
    {
        if (! filled($marketId)) {
            return null;
        }

        return Market::query()->whereKey((int) $marketId)->value('name');
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

    /**
     * Синхронизация участников по ролям (task_participants.role) без конфликтов unique(task_id,user_id).
     * Правило конфликтов: если пользователь выбран в обоих списках — роль становится coexecutor.
     *
     * @param  array<int, mixed>  $observerIds
     * @param  array<int, mixed>  $coexecutorIds
     */
    protected static function syncParticipantsByRole(Task $task, array $observerIds, array $coexecutorIds): void
    {
        $observerIds = static::normalizeIds($observerIds);
        $coexecutorIds = static::normalizeIds($coexecutorIds);

        if ($coexecutorIds) {
            $observerIds = array_values(array_diff($observerIds, $coexecutorIds));
        }

        $desired = [];

        foreach ($observerIds as $id) {
            $desired[$id] = Task::PARTICIPANT_ROLE_OBSERVER;
        }

        foreach ($coexecutorIds as $id) {
            $desired[$id] = Task::PARTICIPANT_ROLE_COEXECUTOR;
        }

        $existing = TaskParticipant::query()
            ->where('task_id', $task->id)
            ->pluck('role', 'user_id')
            ->all();

        $existingIds = array_map('intval', array_keys($existing));
        $desiredIds = array_map('intval', array_keys($desired));

        $toDelete = array_values(array_diff($existingIds, $desiredIds));

        if (! empty($toDelete)) {
            TaskParticipant::query()
                ->where('task_id', $task->id)
                ->whereIn('user_id', $toDelete)
                ->delete();
        }

        $now = now();

        foreach ($desired as $userId => $role) {
            $userId = (int) $userId;

            if (array_key_exists($userId, $existing)) {
                if ((string) $existing[$userId] !== (string) $role) {
                    TaskParticipant::query()
                        ->where('task_id', $task->id)
                        ->where('user_id', $userId)
                        ->update([
                            'role' => $role,
                            'updated_at' => $now,
                        ]);
                }

                continue;
            }

            TaskParticipant::query()->insert([
                'task_id' => $task->id,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    protected static function normalizeIds(array $ids): array
    {
        $out = [];

        foreach ($ids as $value) {
            if (is_numeric($value)) {
                $out[] = (int) $value;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    protected static function toggleable(object $column, bool $hiddenByDefault = false): object
    {
        if (! method_exists($column, 'toggleable')) {
            return $column;
        }

        try {
            return $column->toggleable(isToggledHiddenByDefault: $hiddenByDefault);
        } catch (\Throwable) {
            return $column->toggleable($hiddenByDefault);
        }
    }
}
