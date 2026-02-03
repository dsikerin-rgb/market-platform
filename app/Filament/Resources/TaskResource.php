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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Задача';
    protected static ?string $pluralModelLabel = 'Задачи';

    protected static ?string $navigationLabel = 'Задачи';

    // В Filament v4 base type: UnitEnum|string|null
    protected static \UnitEnum|string|null $navigationGroup = 'Оперативная работа';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    /**
     * Чтобы Filament (где поддерживается) использовал название задачи как заголовок записи.
     */
    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $isSuperAdmin = (bool) $user && $user->isSuperAdmin();
        $isMarketAdmin = (bool) $user && $user->hasRole('market-admin');
        $isSuperOrMarketAdmin = $isSuperAdmin || $isMarketAdmin;

        $isCreator = static function (?Task $record) use ($user): bool {
            return (bool) $record
                && (bool) $user
                && (int) $record->created_by_user_id === (int) $user->id;
        };

        $isAssignee = static function (?Task $record) use ($user): bool {
            return (bool) $record
                && (bool) $user
                && (int) $record->assignee_id === (int) $user->id;
        };

        $isCoexecutor = static function (?Task $record) use ($user): bool {
            if (! $record || ! $record->exists || ! $user) {
                return false;
            }

            return $record->participantEntries()
                ->where('user_id', (int) $user->id)
                ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
                ->exists();
        };

        $isNew = static function (?Task $record): bool {
            return (bool) $record && (string) $record->status === (string) Task::STATUS_NEW;
        };

        $canUpdateCore = static function (?Task $record, string $operation) use ($isSuperOrMarketAdmin, $isCreator, $isNew): bool {
            if ($operation === 'create') {
                return true;
            }

            if ($isSuperOrMarketAdmin) {
                return true;
            }

            return $isCreator($record) && $isNew($record);
        };

        $canUpdateStatus = static function (?Task $record, string $operation) use ($isSuperOrMarketAdmin, $isCreator, $isAssignee, $isCoexecutor, $isNew): bool {
            if ($operation === 'create') {
                return false;
            }

            if ($isSuperOrMarketAdmin) {
                return true;
            }

            if ($isAssignee($record) || $isCoexecutor($record)) {
                return true;
            }

            return $isCreator($record) && $isNew($record);
        };

        $canManageParticipants = static function (?Task $record, string $operation) use ($isSuperOrMarketAdmin, $isCreator, $isNew): bool {
            if ($operation === 'create') {
                return true;
            }

            if ($isSuperOrMarketAdmin) {
                return true;
            }

            return $isCreator($record) && $isNew($record);
        };

        $canManageObservers = static function (?Task $record, string $operation) use ($isSuperOrMarketAdmin, $isCreator, $isAssignee, $isCoexecutor): bool {
            if ($operation === 'create') {
                return true;
            }

            if ($isSuperOrMarketAdmin) {
                return true;
            }

            return $isCreator($record) || $isAssignee($record) || $isCoexecutor($record);
        };

        /**
         * Inline label (лейбл слева). Безопасно для разных версий Filament.
         */
        $inline = static function ($component, bool $enabled = true) {
            if ($enabled && is_object($component) && method_exists($component, 'inlineLabel')) {
                $component->inlineLabel();
            }

            return $component;
        };

        $readonlyText = static function (
            string $name,
            string $label,
            \Closure $content,
            array|int|string|null $columnSpan = null,
            ?\Closure $visible = null,
        ) use ($inline) {
            $p = Forms\Components\Placeholder::make($name)
                ->label($label)
                ->content($content);

            // В "Сводке" хотим единый стиль с лейблом слева
            $inline($p, true);

            if ($columnSpan !== null) {
                if (method_exists($p, 'columnSpan') && $columnSpan !== 'full') {
                    $p->columnSpan($columnSpan);
                }
                if ($columnSpan === 'full' && method_exists($p, 'columnSpanFull')) {
                    $p->columnSpanFull();
                }
            }

            if ($visible) {
                $p->visible($visible);
            }

            return $p;
        };

        $readonlyMultiline = static function (
            string $name,
            string $label,
            \Closure $content,
            array|int|string|null $columnSpan = null,
            ?\Closure $visible = null,
        ) use ($readonlyText) {
            return $readonlyText(
                $name,
                $label,
                function (?Task $record) use ($content): HtmlString {
                    $text = (string) $content($record);
                    $text = trim($text);

                    if ($text === '') {
                        $text = '—';
                    }

                    return new HtmlString(nl2br(e($text)));
                },
                $columnSpan,
                $visible,
            );
        };

        // -------------------------
        // Рынок
        // -------------------------
        $marketComponents = [];

        if ($user && $user->isSuperAdmin()) {
            $marketComponents[] = $readonlyText(
                'market_context',
                'Рынок',
                function (?Task $record) use ($selectedMarketId): string {
                    $marketId = $record?->market_id ?: (filled($selectedMarketId) ? (int) $selectedMarketId : null);

                    return static::resolveMarketName($marketId) ?: '—';
                },
                'full',
                fn (?Task $record, string $operation): bool => $operation === 'edit' || filled($selectedMarketId),
            );

            $marketComponents[] = Forms\Components\Hidden::make('market_id')
                ->default(function (?Task $record) use ($selectedMarketId) {
                    if ($record?->exists) {
                        return (int) $record->market_id;
                    }

                    return filled($selectedMarketId) ? (int) $selectedMarketId : null;
                })
                ->dehydrated(true)
                ->visible(fn (?Task $record, string $operation): bool => $operation === 'edit' || filled($selectedMarketId));

            $marketSelect = Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->placeholder('Выберите рынок')
                ->relationship('market', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
                ->native(false)
                ->helperText('Если выбрать рынок в панели — здесь он подставится автоматически.')
                ->visible(fn (string $operation): bool => $operation === 'create' && blank($selectedMarketId))
                ->columnSpanFull()
                ->dehydrated(true);

            // В create тоже можно оставить inline (не критично), но он будет "как в сводке" и аккуратнее
            $marketComponents[] = $inline($marketSelect, true);
        } else {
            $marketComponents[] = Forms\Components\Hidden::make('market_id')
                ->default(fn (?Task $record) => ($record?->exists ? $record->market_id : null) ?: $user?->market_id)
                ->dehydrated(true);
        }

        // -------------------------
        // Постановщик (read-only)
        // -------------------------
        $creatorDisplay = $readonlyText(
            'creator_display',
            'Постановщик',
            function (?Task $record) use ($user): string {
                if ($record?->exists) {
                    $name = $record->creator?->name;
                    if (filled($name)) {
                        return (string) $name;
                    }

                    return filled($record->created_by_user_id)
                        ? ('Пользователь #' . (int) $record->created_by_user_id)
                        : '—';
                }

                return $user?->name ?: '—';
            },
            'full'
        );

        // -------------------------
        // CORE: title / description
        // -------------------------
        $titleEditable = $inline(
            Forms\Components\TextInput::make('title')
                ->label('Название задачи')
                ->placeholder('Например: Проверить холодильник в павильоне 12')
                ->required()
                ->maxLength(255)
                ->columnSpanFull()
                ->visible(fn (?Task $record, string $operation): bool => $canUpdateCore($record, $operation))
        );

        $titleReadonly = $readonlyText(
            'title_readonly',
            'Название задачи',
            fn (?Task $record): string => $record?->title ?: '—',
            'full',
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateCore($record, $operation),
        );

        $descriptionEditable = $inline(
            Forms\Components\Textarea::make('description')
                ->label('Описание')
                ->placeholder('Коротко опиши, что нужно сделать. Если важно — добавь детали и критерии готовности.')
                ->rows(6)
                ->autosize()
                ->columnSpanFull()
                ->visible(fn (?Task $record, string $operation): bool => $canUpdateCore($record, $operation))
        );

        $descriptionReadonly = $readonlyMultiline(
            'description_readonly',
            'Описание',
            fn (?Task $record): string => filled($record?->description) ? (string) $record->description : '—',
            'full',
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateCore($record, $operation),
        );

        $createdByHidden = Forms\Components\Hidden::make('created_by_user_id')
            ->default(fn () => $user?->id)
            ->dehydrated(fn (string $operation): bool => $operation === 'create');

        // -------------------------
        // STATUS
        // -------------------------
        $statusHiddenOnCreate = Forms\Components\Hidden::make('status')
            ->default(Task::STATUS_NEW)
            ->visible(fn (string $operation): bool => $operation === 'create')
            ->dehydrated(fn (string $operation): bool => $operation === 'create');

        $statusEditableOnEdit = $inline(
            Forms\Components\Select::make('status')
                ->label('Статус задачи')
                ->options(function (?Task $record) use ($isSuperOrMarketAdmin, $isCreator, $isAssignee, $isCoexecutor, $isNew): array {
                    $all = Task::statusOptions();

                    if ($isSuperOrMarketAdmin) {
                        return $all;
                    }

                    if (! $record) {
                        return $all;
                    }

                    if ($isCreator($record) && $isNew($record)) {
                        return array_intersect_key($all, array_flip([Task::STATUS_NEW, Task::STATUS_CANCELLED]));
                    }

                    if ($isAssignee($record) || $isCoexecutor($record)) {
                        unset($all[Task::STATUS_CANCELLED]);

                        return $all;
                    }

                    return $all;
                })
                ->required()
                ->native(false)
                ->visible(fn (?Task $record, string $operation): bool => $operation === 'edit' && $canUpdateStatus($record, $operation))
                ->columnSpan([
                    'default' => 12,
                    'lg' => 6,
                ])
        );

        $statusReadonlyOnEdit = $readonlyText(
            'status_readonly',
            'Статус задачи',
            fn (?Task $record): string => $record ? (Task::STATUS_LABELS[$record->status] ?? (string) $record->status) : '—',
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateStatus($record, $operation),
        );

        // -------------------------
        // Параметры
        // -------------------------
        $priorityEditable = $inline(
            Forms\Components\Select::make('priority')
                ->label('Приоритет')
                ->options(Task::priorityOptions())
                ->default(Task::PRIORITY_NORMAL)
                ->required()
                ->native(false)
                ->columnSpan([
                    'default' => 12,
                    'lg' => 6,
                ])
                ->visible(fn (?Task $record, string $operation): bool => $canUpdateCore($record, $operation))
        );

        $priorityReadonly = $readonlyText(
            'priority_readonly',
            'Приоритет',
            fn (?Task $record): string => $record ? (Task::PRIORITY_LABELS[$record->priority] ?? (string) $record->priority) : '—',
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateCore($record, $operation),
        );

        $dueAtEditable = $inline(
            Forms\Components\DateTimePicker::make('due_at')
                ->label('Дедлайн')
                ->seconds(false)
                ->helperText('Можно оставить пустым и назначить позже.')
                ->columnSpan([
                    'default' => 12,
                    'lg' => 6,
                ])
                ->visible(fn (?Task $record, string $operation): bool => $canUpdateCore($record, $operation))
        );

        $dueAtReadonly = $readonlyText(
            'due_at_readonly',
            'Дедлайн',
            fn (?Task $record): string => $record?->due_at ? $record->due_at->format('d.m.Y H:i') : '—',
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateCore($record, $operation),
        );

        $assigneeEditable = $inline(
            Forms\Components\Select::make('assignee_id')
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
                ->columnSpan([
                    'default' => 12,
                    'lg' => 6,
                ])
                ->visible(fn (?Task $record, string $operation): bool => $canUpdateCore($record, $operation))
        );

        $assigneeReadonly = $readonlyText(
            'assignee_readonly',
            'Исполнитель',
            fn (?Task $record): string => $record?->assignee?->name ?: '—',
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canUpdateCore($record, $operation),
        );

        // -------------------------
        // Участники (ВАЖНО: без inlineLabel — чтобы не было "наезда")
        // -------------------------
        $coexecutorsField = $inline(
            Forms\Components\Select::make('coexecutor_user_ids')
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
                ])
                ->visible(fn (?Task $record, string $operation): bool => $canManageParticipants($record, $operation)),
            false
        );

        if (method_exists($coexecutorsField, 'getOptionLabelsUsing')) {
            $coexecutorsField->getOptionLabelsUsing(function (array $values): array {
                return User::query()
                    ->whereIn('id', array_map('intval', $values))
                    ->pluck('name', 'id')
                    ->toArray();
            });
        }

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
                if (! $record || ! $record->exists) {
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
                if (! $record || ! $record->exists) {
                    return;
                }

                $observers = (array) $get('observer_user_ids');
                $coexecutors = (array) $state;

                static::syncParticipantsByRole($record, $observers, $coexecutors);
            });
        }

        $coexecutorsReadonly = $readonlyText(
            'coexecutors_readonly',
            'Соисполнители',
            fn (?Task $record): string => static::formatUsersByRole($record, Task::PARTICIPANT_ROLE_COEXECUTOR),
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canManageParticipants($record, $operation),
        );

        $observersField = $inline(
            Forms\Components\Select::make('observer_user_ids')
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
                ])
                ->visible(fn (?Task $record, string $operation): bool => $canManageObservers($record, $operation)),
            false
        );

        if (method_exists($observersField, 'getOptionLabelsUsing')) {
            $observersField->getOptionLabelsUsing(function (array $values): array {
                return User::query()
                    ->whereIn('id', array_map('intval', $values))
                    ->pluck('name', 'id')
                    ->toArray();
            });
        }

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
                if (! $record || ! $record->exists) {
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
                if (! $record || ! $record->exists) {
                    return;
                }

                $observers = (array) $state;
                $coexecutors = (array) $get('coexecutor_user_ids');

                static::syncParticipantsByRole($record, $observers, $coexecutors);
            });
        }

        $observersReadonly = $readonlyText(
            'observers_readonly',
            'Наблюдатели',
            fn (?Task $record): string => static::formatUsersByRole($record, Task::PARTICIPANT_ROLE_OBSERVER),
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit' && ! $canManageObservers($record, $operation),
        );

        $sourceLabel = $readonlyText(
            'source_label',
            'Источник',
            fn (?Task $record): string => $record?->source_label ?? '—',
            'full',
            fn (?Task $record): bool => (bool) $record && filled($record->source_type) && filled($record->source_id),
        );

        // -------------------------
        // EDIT: Дата создания
        // -------------------------
        $createdAt = $readonlyText(
            'created_at_display',
            'Дата создания',
            fn (?Task $record): string => $record?->created_at ? $record->created_at->format('d.m.Y H:i') : '—',
            [
                'default' => 12,
                'lg' => 6,
            ],
            fn (?Task $record, string $operation): bool => $operation === 'edit',
        );

        // -------------------------
        // CREATE: Wizard
        // -------------------------
        $createWizard = Wizard::make([
            Step::make('Основное')
                ->description('Что нужно сделать')
                ->schema([
                    Section::make('Данные задачи')
                        ->schema([
                            ...$marketComponents,

                            $creatorDisplay,

                            $titleEditable,
                            $titleReadonly,

                            $descriptionEditable,
                            $descriptionReadonly,

                            $createdByHidden,
                        ])
                        ->columns(12)
                        ->extraAttributes(['class' => 'max-w-6xl mx-auto']),
                ]),

            Step::make('Назначение')
                ->description('Приоритет, сроки и исполнитель')
                ->schema([
                    Section::make('Параметры')
                        ->schema([
                            $statusHiddenOnCreate,

                            $statusEditableOnEdit,
                            $statusReadonlyOnEdit,

                            $priorityEditable,
                            $priorityReadonly,

                            $dueAtEditable,
                            $dueAtReadonly,

                            $assigneeEditable,
                            $assigneeReadonly,
                        ])
                        ->columns(12)
                        ->extraAttributes(['class' => 'max-w-6xl mx-auto']),
                ]),

            Step::make('Участники')
                ->description('Соисполнители и наблюдатели')
                ->schema([
                    Section::make('Участники')
                        ->schema([
                            $coexecutorsField,
                            $coexecutorsReadonly,

                            $observersField,
                            $observersReadonly,

                            $sourceLabel,
                        ])
                        ->columns(12)
                        ->extraAttributes(['class' => 'max-w-6xl mx-auto']),
                ]),
        ])->skippable(false);

        if (method_exists($createWizard, 'visible')) {
            $createWizard->visible(fn (string $operation): bool => $operation === 'create');
        }
        if (method_exists($createWizard, 'columnSpanFull')) {
            $createWizard->columnSpanFull();
        }

        // -------------------------
        // EDIT: Сводка + вкладки
        // -------------------------
        $editSummary = Section::make('Сводка')
            ->schema([
                ...$marketComponents,

                $creatorDisplay,

                $createdAt,

                $titleEditable,
                $titleReadonly,

                $descriptionEditable,
                $descriptionReadonly,

                $statusHiddenOnCreate,

                $statusEditableOnEdit,
                $statusReadonlyOnEdit,

                $priorityEditable,
                $priorityReadonly,

                $dueAtEditable,
                $dueAtReadonly,

                $assigneeEditable,
                $assigneeReadonly,
            ])
            ->columns(12);

        // CSS-хук: применяем твои правки inline-label только к "Сводке"
        if (method_exists($editSummary, 'extraAttributes')) {
            $editSummary->extraAttributes([
                'class' => 'task-summary-compact',
            ]);
        }

        if (method_exists($editSummary, 'columnSpanFull')) {
            $editSummary->columnSpanFull();
        }

        $tabsComponent = null;

        $TabsClass = null;
        $TabClass = null;

        if (class_exists(\Filament\Schemas\Components\Tabs::class) && class_exists(\Filament\Schemas\Components\Tabs\Tab::class)) {
            $TabsClass = \Filament\Schemas\Components\Tabs::class;
            $TabClass = \Filament\Schemas\Components\Tabs\Tab::class;
        } elseif (class_exists(\Filament\Forms\Components\Tabs::class) && class_exists(\Filament\Forms\Components\Tabs\Tab::class)) {
            $TabsClass = \Filament\Forms\Components\Tabs::class;
            $TabClass = \Filament\Forms\Components\Tabs\Tab::class;
        }

        if ($TabsClass && $TabClass) {
            $filesHint = Forms\Components\Placeholder::make('files_tab_hint')
                ->label('')
                ->content('Файлы подключаются через вкладку Relation Manager “Файлы” (ниже страницы). Следующим шагом перенесём их в эту зону, если потребуется.')
                ->columnSpanFull();

            $checklistHint = Forms\Components\Placeholder::make('checklist_tab_hint')
                ->label('')
                ->content('Чек-лист добавим следующим модулем (пункты + отметки выполнения).')
                ->columnSpanFull();

            $historyHint = Forms\Components\Placeholder::make('history_tab_hint')
                ->label('')
                ->content('Историю/ленту событий добавим следующим шагом (принятие/завершение/смена статуса/изменения участников).')
                ->columnSpanFull();

            $participantsGrid = static::makeGrid(12, [
                $coexecutorsField,
                $coexecutorsReadonly,

                $observersField,
                $observersReadonly,

                $sourceLabel,
            ]);

            if (method_exists($participantsGrid, 'columnSpanFull')) {
                $participantsGrid->columnSpanFull();
            }

            $makeTab = static function (string $label, array $tabSchema) use ($TabClass) {
                $tab = $TabClass::make($label);

                if (method_exists($tab, 'schema')) {
                    $tab->schema($tabSchema);
                } elseif (method_exists($tab, 'components')) {
                    $tab->components($tabSchema);
                }

                return $tab;
            };

            $tabsComponent = $TabsClass::make('task_tabs');

            if (method_exists($tabsComponent, 'tabs')) {
                $tabsComponent->tabs([
                    $makeTab('Файлы', [$filesHint]),
                    $makeTab('Чек-лист', [$checklistHint]),
                    $makeTab('Участники', [$participantsGrid]),
                    $makeTab('История', [$historyHint]),
                ]);
            }

            if (method_exists($tabsComponent, 'persistTabInQueryString')) {
                $tabsComponent->persistTabInQueryString();
            }

            if (method_exists($tabsComponent, 'columnSpanFull')) {
                $tabsComponent->columnSpanFull();
            }
        }

        if (! $tabsComponent) {
            $tabsComponent = Section::make('Участники')
                ->schema([
                    $coexecutorsField,
                    $coexecutorsReadonly,

                    $observersField,
                    $observersReadonly,

                    $sourceLabel,
                ])
                ->columns(12);

            if (method_exists($tabsComponent, 'columnSpanFull')) {
                $tabsComponent->columnSpanFull();
            }
        }

        $editGrid = static::makeGrid(12, [
            $editSummary,
            $tabsComponent,
        ]);

        if (method_exists($editGrid, 'visible')) {
            $editGrid->visible(fn (string $operation): bool => $operation === 'edit');
        }
        if (method_exists($editGrid, 'columnSpanFull')) {
            $editGrid->columnSpanFull();
        }

        return $schema->components([
            $createWizard,
            $editGrid,
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $marketColumn = TextColumn::make('market.name')
            ->label('Рынок')
            ->sortable()
            ->searchable()
            ->visible(fn (): bool => (bool) $user && $user->isSuperAdmin());

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

        $createdAtColumn = TextColumn::make('created_at')
            ->label('Создано')
            ->sortable();

        if (method_exists($createdAtColumn, 'dateTime')) {
            $createdAtColumn->dateTime('d.m.Y H:i');
        } else {
            $createdAtColumn->formatStateUsing(fn ($state): string => $state ? $state->format('d.m.Y H:i') : '—');
        }

        if (method_exists($createdAtColumn, 'tooltip')) {
            $createdAtColumn->tooltip(fn ($state): ?string => $state ? $state->diffForHumans() : null);
        }

        $creatorColumn = TextColumn::make('created_by_user_id')
            ->label('Постановщик')
            ->sortable()
            ->formatStateUsing(function ($state, Task $record): string {
                $name = $record->creator?->name;

                if (filled($name)) {
                    return (string) $name;
                }

                return filled($state) ? ('Пользователь #' . (int) $state) : '—';
            });

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
                $createdAtColumn,
                $creatorColumn,
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
                        true: fn (Builder $query) => Filament::auth()->id()
                            ? $query->where('assignee_id', (int) Filament::auth()->id())
                            : $query->whereRaw('1 = 0'),
                        false: fn (Builder $query) => Filament::auth()->id()
                            ? $query->where(function (Builder $inner) {
                                $userId = (int) Filament::auth()->id();
                                $inner->whereNull('assignee_id')
                                    ->orWhere('assignee_id', '!=', $userId);
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
            ->recordUrl(function (Task $record): ?string {
                if (static::canEdit($record)) {
                    return static::getUrl('edit', ['record' => $record]);
                }

                if (static::canView($record)) {
                    return static::getUrl('view', ['record' => $record]);
                }

                return null;
            });

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()
                ->label('')
                ->icon('heroicon-o-pencil-square')
                ->tooltip('Открыть')
                ->iconButton()
                ->visible(fn (Task $record): bool => static::canEdit($record));
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('')
                ->icon('heroicon-o-pencil-square')
                ->tooltip('Открыть')
                ->iconButton()
                ->visible(fn (Task $record): bool => static::canEdit($record));
        }

        if (class_exists(\Filament\Actions\ViewAction::class)) {
            $actions[] = \Filament\Actions\ViewAction::make()
                ->label('')
                ->icon('heroicon-o-eye')
                ->tooltip('Просмотр')
                ->iconButton()
                ->visible(fn (Task $record): bool => static::canView($record) && ! static::canEdit($record));
        } elseif (class_exists(\Filament\Tables\Actions\ViewAction::class)) {
            $actions[] = \Filament\Tables\Actions\ViewAction::make()
                ->label('')
                ->icon('heroicon-o-eye')
                ->tooltip('Просмотр')
                ->iconButton()
                ->visible(fn (Task $record): bool => static::canView($record) && ! static::canEdit($record));
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->requiresConfirmation()
                ->iconButton()
                ->visible(fn (): bool => (bool) $user && ($user->isSuperAdmin() || $user->hasRole('market-admin')));
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->requiresConfirmation()
                ->iconButton()
                ->visible(fn (): bool => (bool) $user && ($user->isSuperAdmin() || $user->hasRole('market-admin')));
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [
            TaskAttachmentsRelationManager::class,
            TaskCommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
    return [
        'index' => Pages\ListTasks::route('/'),
        'create' => Pages\CreateTask::route('/create'),

             // ВАЖНО: /calendar должен быть ДО /{record}
              'calendar' => Pages\TaskCalendar::route('/calendar'),

             'view' => Pages\ViewTask::route('/{record}'),
             'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['creator', 'assignee'])
            ->withCount('comments')
            ->workOrder();

        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (static::isMerchantUser($user)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
        }

        if (! $user->market_id) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('market-admin')) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query
            ->where('market_id', (int) $user->market_id)
            ->where(function (Builder $inner) use ($user) {
                $inner->where('created_by_user_id', (int) $user->id)
                    ->orWhere('assignee_id', (int) $user->id)
                    ->orWhereHas('participants', fn (Builder $q) => $q->whereKey((int) $user->id));
            });
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

        return (bool) $user->market_id;
    }

    public static function canView($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user) || ! ($record instanceof Task)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->market_id || (int) $record->market_id !== (int) $user->market_id) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        if ((int) $record->created_by_user_id === (int) $user->id) {
            return true;
        }

        if ((int) $record->assignee_id === (int) $user->id) {
            return true;
        }

        return $record->participantEntries()
            ->where('user_id', (int) $user->id)
            ->exists();
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

        return (bool) $user->market_id;
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user) || ! ($record instanceof Task)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->market_id || (int) $record->market_id !== (int) $user->market_id) {
            return false;
        }

        if ($user->hasRole('market-admin')) {
            return true;
        }

        if ((int) $record->created_by_user_id === (int) $user->id) {
            return true;
        }

        if ((int) $record->assignee_id === (int) $user->id) {
            return true;
        }

        if ($record->participantEntries()
            ->where('user_id', (int) $user->id)
            ->where('role', Task::PARTICIPANT_ROLE_OBSERVER)
            ->exists()
        ) {
            return false;
        }

        return $record->participantEntries()
            ->where('user_id', (int) $user->id)
            ->where('role', Task::PARTICIPANT_ROLE_COEXECUTOR)
            ->exists();
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || static::isMerchantUser($user) || ! ($record instanceof Task)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id
            && (int) $record->market_id === (int) $user->market_id
            && $user->hasRole('market-admin');
    }

    /**
     * Super-admin selection: делаем чтение максимально совместимым (разные версии Filament/панелей).
     */
    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session("filament.{$panelId}.market_id")
            ?? session('filament.admin.selected_market_id')
            ?? session('filament.admin.market_id')
            ?? session('selected_market_id');

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

    protected static function formatUsersByRole(?Task $record, string $role): string
    {
        if (! $record || ! $record->exists) {
            return '—';
        }

        $ids = $record->participantEntries()
            ->where('role', $role)
            ->pluck('user_id')
            ->all();

        if (empty($ids)) {
            return '—';
        }

        $users = User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name']);

        $byId = $users->keyBy('id');

        $labels = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $labels[] = $byId->get($id)?->name ?: "Пользователь #{$id}";
        }

        return implode(', ', $labels);
    }

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

    /**
     * Filament v4: Filament\Schemas\Components\Grid
     * Filament v3: Filament\Forms\Components\Grid
     */
    protected static function resolveGridClass(): string
    {
        if (class_exists(\Filament\Schemas\Components\Grid::class)) {
            return \Filament\Schemas\Components\Grid::class;
        }

        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            return \Filament\Forms\Components\Grid::class;
        }

        throw new \RuntimeException('Filament Grid class not found for this version.');
    }

    protected static function makeGrid(int $columns, array $schema): object
    {
        $gridClass = static::resolveGridClass();

        $grid = $gridClass::make($columns);

        if (method_exists($grid, 'schema')) {
            $grid->schema($schema);
        } elseif (method_exists($grid, 'components')) {
            $grid->components($schema);
        }

        return $grid;
    }
}
