<?php
# app/Filament/Resources/TaskResource/Pages/ViewTask.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

if (class_exists(\Filament\Infolists\Infolist::class)) {
    class ViewTask extends ViewRecord
    {
        protected static string $resource = TaskResource::class;

        /**
         * Заголовком страницы делаем название задачи (как в Битрикс24).
         */
        protected static ?string $title = null;

        /**
         * Нужно, чтобы 2 колонки реально помещались (как в Битрикс24).
         */
        protected Width|string|null $maxContentWidth = 'full';

        public function getTitle(): string
        {
            /** @var Task $task */
            $task = $this->getRecord();

            return filled($task->title) ? (string) $task->title : 'Задача';
        }

        public function getSubheading(): ?string
        {
            /** @var Task $task */
            $task = $this->getRecord();

            $status = Task::STATUS_LABELS[$task->status] ?? $task->status;

            return 'ID ' . (int) $task->getKey() . (filled($status) ? (' • ' . (string) $status) : '');
        }

        public function getBreadcrumb(): string
        {
            return 'Просмотр';
        }

        /**
         * Включаем вкладки RelationManagers в правой колонке (чат/файлы).
         */
        public function hasCombinedRelationManagerTabsWithContent(): bool
        {
            return true;
        }

        /**
         * На некоторых версиях Filament это нужно явно.
         *
         * @return array<class-string>
         */
        protected function getRelationManagers(): array
        {
            return TaskResource::getRelations();
        }

        /**
         * Двухколоночная структура: слева Infolist, справа RelationManagers (комментарии/файлы).
         * Правая колонка — узкая + resize по горизонтали.
         */
        public function content(Schema $schema): Schema
        {
            if (
                ! class_exists(Flex::class)
                || ! method_exists($this, 'getInfolistContentComponent')
                || ! method_exists($this, 'getRelationManagersContentComponent')
            ) {
                return parent::content($schema);
            }

            $left = $this->getInfolistContentComponent();
            $right = $this->getRelationManagersContentComponent();

            // Левая колонка должна уметь сжиматься (иначе flex “ломает” ресайз справа).
            if ($left && method_exists($left, 'extraAttributes')) {
                $left->extraAttributes([
                    'class' => 'min-w-0',
                ]);
            }

            // Правая колонка: уже + ресайз.
            if ($right) {
                if (method_exists($right, 'grow')) {
                    $right->grow(false);
                }

                if (method_exists($right, 'extraAttributes')) {
                    $right->extraAttributes([
                        'class' => 'lg:shrink-0',
                        'style' => 'width: 320px; min-width: 300px; max-width: 520px; resize: horizontal; overflow: auto;',
                    ]);
                }
            }

            $flex = Flex::make([$left, $right]);

            if (method_exists($flex, 'from')) {
                $flex->from('lg'); // до lg складываемся в один столбец
            }

            // В этой версии Filament gap() — boolean (вкл/выкл), размер задаём классом.
            if (method_exists($flex, 'gap')) {
                $flex->gap();
            }

            if (method_exists($flex, 'extraAttributes')) {
                $flex->extraAttributes([
                    'class' => 'gap-6',
                ]);
            }

            return $schema->components([$flex]);
        }

        protected function getHeaderActions(): array
        {
            /** @var Task $record */
            $record = $this->getRecord();

            $actions = [];

            // Primary: Edit (icon-only)
            if (TaskResource::canEdit($record)) {
                $edit = Actions\EditAction::make()
                    ->label('') // icon-only
                    ->icon('heroicon-o-pencil-square');

                if (method_exists($edit, 'iconButton')) {
                    $edit->iconButton();
                }
                if (method_exists($edit, 'tooltip')) {
                    $edit->tooltip('Редактировать');
                }

                $actions[] = $edit;
            }

            // Secondary: "..." menu
            if (class_exists(\Filament\Actions\ActionGroup::class)) {
                $more = Actions\ActionGroup::make([
                    Actions\Action::make('open_in_new_tab')
                        ->label('Открыть в новой вкладке')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (): string => TaskResource::getUrl('view', ['record' => $record]))
                        ->openUrlInNewTab(),

                    Actions\Action::make('edit_if_allowed')
                        ->label('Редактировать')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => TaskResource::canEdit($record))
                        ->url(fn (): string => TaskResource::getUrl('edit', ['record' => $record])),
                ])
                    ->label('') // icon-only
                    ->icon('heroicon-o-ellipsis-horizontal');

                if (method_exists($more, 'iconButton')) {
                    $more->iconButton();
                }
                if (method_exists($more, 'tooltip')) {
                    $more->tooltip('Ещё');
                }

                $actions[] = $more;
            }

            return $actions;
        }

        public function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
        {
            /** @var Task $task */
            $task = $this->getRecord();

            // --- Участники: один сбор + один запрос к users ---
            $participantIdsByRole = $task->participantEntries()
                ->select(['user_id', 'role'])
                ->get()
                ->groupBy('role')
                ->map(static fn ($items): array => $items
                    ->pluck('user_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all()
                )
                ->all();

            $allParticipantIds = [];
            foreach ($participantIdsByRole as $ids) {
                foreach ($ids as $id) {
                    $allParticipantIds[(int) $id] = (int) $id;
                }
            }

            $usersById = empty($allParticipantIds)
                ? collect()
                : User::query()
                    ->whereIn('id', array_values($allParticipantIds))
                    ->get(['id', 'name'])
                    ->keyBy('id');

            $formatUsersByRole = static function (string $role) use ($participantIdsByRole, $usersById): string {
                $ids = $participantIdsByRole[$role] ?? [];

                if (empty($ids)) {
                    return '—';
                }

                $labels = [];
                foreach ($ids as $id) {
                    $id = (int) $id;
                    $labels[] = $usersById->get($id)?->name ?: "Пользователь #{$id}";
                }

                return implode(', ', $labels);
            };

            $TextEntry = \Filament\Infolists\Components\TextEntry::class;
            $Section = \Filament\Infolists\Components\Section::class;

            $Tabs = class_exists(\Filament\Infolists\Components\Tabs::class) && class_exists(\Filament\Infolists\Components\Tabs\Tab::class)
                ? \Filament\Infolists\Components\Tabs::class
                : null;

            $Tab = class_exists(\Filament\Infolists\Components\Tabs\Tab::class)
                ? \Filament\Infolists\Components\Tabs\Tab::class
                : null;

            $statusEntry = $TextEntry::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state): string => Task::STATUS_LABELS[$state] ?? (string) $state);

            if (method_exists($statusEntry, 'badge')) {
                $statusEntry->badge();
            }

            $priorityEntry = $TextEntry::make('priority')
                ->label('Приоритет')
                ->formatStateUsing(fn (?string $state): string => Task::PRIORITY_LABELS[$state] ?? (string) $state);

            if (method_exists($priorityEntry, 'badge')) {
                $priorityEntry->badge();
            }

            $summary = $Section::make('Сводка')
                ->schema([
                    $TextEntry::make('created_by_user_id')
                        ->label('Постановщик')
                        ->formatStateUsing(function ($state, Task $record): string {
                            $name = $record->creator?->name;

                            if (filled($name)) {
                                return (string) $name;
                            }

                            return filled($state) ? ('Пользователь #' . (int) $state) : '—';
                        }),

                    $TextEntry::make('assignee.name')
                        ->label('Исполнитель')
                        ->default('—'),

                    $statusEntry,

                    $TextEntry::make('due_at')
                        ->label('Крайний срок')
                        ->dateTime('d.m.Y H:i')
                        ->default('Без срока'),

                    $TextEntry::make('created_at')
                        ->label('Дата создания')
                        ->dateTime('d.m.Y H:i')
                        ->default('—'),

                    $TextEntry::make('id')
                        ->label('ID')
                        ->formatStateUsing(fn ($state): string => filled($state) ? (string) (int) $state : '—'),
                ])
                ->columns(2);

            if ($Tabs !== null && $Tab !== null) {
                $details = $Tabs::make('Детали')->tabs([
                    $Tab::make('Описание')->schema([
                        $TextEntry::make('description')
                            ->label('Описание')
                            ->columnSpanFull()
                            ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '—'),

                        $TextEntry::make('source_label')
                            ->label('Источник')
                            ->visible(fn (Task $record): bool => filled($record->source_type) && filled($record->source_id))
                            ->default('—'),
                    ])->columns(1),

                    $Tab::make('Участники')->schema([
                        $TextEntry::make('coexecutors_list')
                            ->label('Соисполнители')
                            ->formatStateUsing(fn ($state, Task $record): string => $formatUsersByRole(Task::PARTICIPANT_ROLE_COEXECUTOR)),

                        $TextEntry::make('observers_list')
                            ->label('Наблюдатели')
                            ->formatStateUsing(fn ($state, Task $record): string => $formatUsersByRole(Task::PARTICIPANT_ROLE_OBSERVER)),
                    ])->columns(2),

                    $Tab::make('Параметры')->schema([
                        $priorityEntry,

                        $TextEntry::make('updated_at')
                            ->label('Обновлено')
                            ->dateTime('d.m.Y H:i')
                            ->default('—'),
                    ])->columns(2),
                ]);
            } else {
                $details = $Section::make('Детали')
                    ->schema([
                        $TextEntry::make('description')
                            ->label('Описание')
                            ->columnSpanFull()
                            ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '—'),

                        $TextEntry::make('coexecutors_list')
                            ->label('Соисполнители')
                            ->formatStateUsing(fn ($state, Task $record): string => $formatUsersByRole(Task::PARTICIPANT_ROLE_COEXECUTOR)),

                        $TextEntry::make('observers_list')
                            ->label('Наблюдатели')
                            ->formatStateUsing(fn ($state, Task $record): string => $formatUsersByRole(Task::PARTICIPANT_ROLE_OBSERVER)),
                    ])
                    ->columns(2);
            }

            return $infolist->schema([
                $summary,
                $details,
            ]);
        }
    }
} else {
    class ViewTask extends ViewRecord
    {
        protected static string $resource = TaskResource::class;
    }
}
