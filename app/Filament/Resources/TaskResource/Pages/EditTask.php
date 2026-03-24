<?php

# app/Filament/Resources/TaskResource/Pages/EditTask.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Filament\Resources\Pages\BaseEditRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs as SchemaTabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EditTask extends BaseEditRecord
{
    protected static string $resource = TaskResource::class;

    /**
     * Заголовок формируем динамически, чтобы не дублировать.
     */
    protected static ?string $title = null;

    /**
     * Чтобы 2 колонки реально помещались.
     */
    protected Width|string|null $maxContentWidth = null;

    public function getTitle(): string
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $resource = static::getResource();

        if (! filled($task->title)) {
            $task->title = (string) $resource::getModelLabel();
        }

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
        $modelLabel = (string) static::getResource()::getModelLabel();

        if ($modelLabel !== '') {
            return $modelLabel;
        }

        return 'Задача';
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $recordId = (int) $this->getRecord()->getKey();
        $currentTitle = trim($this->getTitle());

        if ($currentTitle !== '') {
            return [
                $resource::getUrl('index') => (string) $resource::getPluralModelLabel(),
                $currentTitle,
            ];
        }

        return [
            $resource::getUrl('index') => (string) $resource::getPluralModelLabel(),
            "Задача #{$recordId}",
        ];
    }

    public function getHeader(): ?View
    {
        $headerActions = array_values(array_filter(
            $this->getCachedHeaderActions(),
            fn (Actions\Action $action): bool => ! Str::startsWith($action->getName() ?? '', 'edit_'),
        ));

        return view('filament.resources.tasks.partials.edit-hero', [
            'actions' => $headerActions,
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'heroActions' => $this->getHeroActions(),
            'subheading' => null,
            'hero' => $this->getOverviewStripData(),
        ]);
    }

    /**
     * Двухколоночная структура: слева форма, справа RelationManagers (Чат/Файлы).
     * Правая колонка — узкая + resize по горизонтали (если браузер поддерживает).
     */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return false;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $description = is_string($data['description'] ?? null) ? (string) $data['description'] : '';
        [, $publicDescription] = $this->splitTechnicalDescription($description);

        if ($description !== '') {
            $data['description'] = $publicDescription;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $submittedDescription = is_string($data['description'] ?? null)
            ? trim((string) $data['description'])
            : '';

        $originalDescription = ($this->record instanceof Task) && is_string($this->record->description ?? null)
            ? (string) $this->record->description
            : '';

        [$technicalPrefix] = $this->splitTechnicalDescription($originalDescription);

        $data['description'] = $this->mergeTechnicalDescription($technicalPrefix, $submittedDescription);

        return $data;
    }

    public function content(Schema $schema): Schema
    {
        if (
            ! class_exists(Flex::class)
            || ! method_exists($this, 'getFormContentComponent')
            || ! method_exists($this, 'getRelationManagersContentComponent')
        ) {
            return parent::content($schema);
        }

        $form = $this->getFormContentComponent();
        $relations = $this->getRelationManagersContentComponent();

        if ($form && method_exists($form, 'grow')) {
            $form->grow();
        }

        if ($relations && method_exists($relations, 'grow')) {
            $relations->grow(false);
        }

        if ($relations && method_exists($relations, 'extraAttributes')) {
            $relations->extraAttributes([
                'class' => 'task-edit-sidebar lg:shrink-0',
            ]);
        }

        if ($form && method_exists($form, 'extraAttributes')) {
            $form->extraAttributes([
                'class' => 'min-w-0 task-edit-main task-edit-form',
            ]);
        }

        $flex = Flex::make([$form, $relations]);

        if (method_exists($flex, 'from')) {
            $flex->from('lg'); // до lg складываемся в один столбец
        }

        // В Filament v4 gap() — boolean (вкл/выкл). Размер задаём через class gap-*
        if (method_exists($flex, 'gap')) {
            $flex->gap(true);
        }

        if (method_exists($flex, 'extraAttributes')) {
            $flex->extraAttributes([
                'class' => 'task-edit-workspace gap-7',
            ]);
        }

        return $schema->components([$flex]);
    }

    public function getRelationManagersContentComponent(): Component
    {
        $component = parent::getRelationManagersContentComponent();

        if ($component instanceof SchemaTabs) {
            $component->contained(true);
        }

        return $component;
    }

    private function canUpdateCore(): bool
    {
        $user = auth()->user();

        return $user
            && ($this->record instanceof Task)
            && Gate::forUser($user)->allows('updateCore', $this->record);
    }

    private function updateTask(array $data, string $ability, string $successTitle): void
    {
        $user = auth()->user();

        if (! $user || ! ($this->record instanceof Task)) {
            return;
        }

        /** @var Task $task */
        $task = $this->record->fresh();

        if (! $task) {
            return;
        }

        if (! Gate::forUser($user)->allows($ability, $task)) {
            return;
        }

        $task->forceFill($data)->save();

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    private function statusOptionsForTask(Task $task): array
    {
        $user = auth()->user();

        $all = Task::statusOptions();

        if (! $user) {
            return $all;
        }

        if ($user->isSuperAdmin() || $user->hasRole('market-admin')) {
            return $all;
        }

        if ((int) $task->created_by_user_id === (int) $user->id && (string) $task->status === (string) Task::STATUS_NEW) {
            return array_intersect_key($all, array_flip([Task::STATUS_NEW, Task::STATUS_CANCELLED]));
        }

        $isParticipant = $task->participantEntries()
            ->where('user_id', (int) $user->id)
            ->whereIn('role', [Task::PARTICIPANT_ROLE_ASSIGNEE, Task::PARTICIPANT_ROLE_COEXECUTOR])
            ->exists();

        if ($isParticipant) {
            unset($all[Task::STATUS_CANCELLED]);
        }

        return $all;
    }

    private function formatHeroDescriptionPreview(?string $description): string
    {
        $description = is_string($description) ? trim($description) : '';

        if ($description === '') {
            return 'Добавить описание';
        }

        [, $publicDescription] = $this->splitTechnicalDescription($description);
        $display = trim($publicDescription !== '' ? $publicDescription : $description);

        return $display;
    }

    private function getEditableDescriptionValue(): string
    {
        if (! ($this->record instanceof Task)) {
            return '';
        }

        $description = is_string($this->record->description ?? null)
            ? (string) $this->record->description
            : '';

        [, $publicDescription] = $this->splitTechnicalDescription($description);

        return trim($publicDescription !== '' ? $publicDescription : $description);
    }

    protected function getOverviewStripData(): array
    {
        /** @var Task $task */
        $task = $this->getRecord();

        return [
            'title' => $task->title ?: 'Задача',
            'description' => $this->formatHeroDescriptionPreview($task->description),
            'status' => Task::STATUS_LABELS[$task->status] ?? (string) $task->status,
            'priority' => Task::PRIORITY_LABELS[$task->priority] ?? (string) $task->priority,
            'creator' => TaskResource::formatTaskUserDisplay($task->creator?->name),
            'assignee' => $task->assignee?->name
                ? TaskResource::formatTaskUserDisplay($task->assignee?->name)
                : 'Не назначен',
            'createdAt' => $task->created_at?->format('d.m.Y H:i') ?: '—',
            'dueAt' => $task->due_at?->format('d.m.Y H:i') ?: 'Без дедлайна',
            'source' => $task->source_label ?: 'Вручную',
            'canEditTitle' => $this->canUpdateCore(),
            'canEditDescription' => $this->canUpdateCore(),
            'canEditStatus' => $this->canUpdateStatus(),
            'canEditPriority' => $this->canUpdateCore(),
            'canEditDueAt' => $this->canUpdateCore(),
            'canEditAssignee' => $this->canUpdateCore(),
        ];
    }

    protected function getHeroActions(): array
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $actions = $this->getHeaderActions();

        return [
            'title' => $this->configureHeroAction(
                clone $actions[0],
                $task->title ?: 'Задача',
                'task-edit-hero__heading task-edit-hero__heading-button',
            ),
            'description' => $this->configureHeroAction(
                clone $actions[1],
                $this->formatHeroDescriptionPreview($task->description) ?: 'Добавить описание',
                'task-edit-hero__description-button',
            ),
            'status' => $this->configureHeroAction(
                clone $actions[2],
                Task::STATUS_LABELS[$task->status] ?? (string) $task->status,
                'task-edit-hero__chip task-edit-hero__chip--status task-edit-hero__chip--action',
                Size::Small,
            ),
            'priority' => $this->configureHeroAction(
                clone $actions[3],
                Task::PRIORITY_LABELS[$task->priority] ?? (string) $task->priority,
                'task-edit-hero__chip task-edit-hero__chip--action',
                Size::Small,
            ),
            'dueAt' => $this->configureHeroAction(
                clone $actions[4],
                $task->due_at?->format('d.m.Y H:i') ?: 'Без дедлайна',
                'task-edit-hero__value-button',
                Size::Small,
            ),
            'assignee' => $this->configureHeroAction(
                clone $actions[5],
                $task->assignee?->name
                    ? TaskResource::formatTaskUserDisplay($task->assignee?->name)
                    : 'Не назначен',
                'task-edit-hero__value-button',
                Size::Small,
            ),
        ];
    }

    private function configureHeroAction(
        Actions\Action $action,
        string $label,
        string $class,
        Size $size = Size::Small,
    ): Actions\Action {
        $action->visible(fn (): bool => true);
        $action->label($label);
        $action->size($size);
        $action->color('gray');
        $action->extraAttributes(['class' => $class]);

        return $action;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit_title')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--wide'])
                ->modalHeading('Редактировать название задачи')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('2xl')
                ->fillForm(function (): array {
                    return [
                        'title' => $this->record instanceof Task ? (string) $this->record->title : '',
                    ];
                })
                ->form([
                    Forms\Components\TextInput::make('title')
                        ->label('Название задачи')
                        ->required()
                        ->maxLength(255)
                        ->autofocus(),
                ])
                ->action(function (array $data): void {
                    $this->updateTask(
                        ['title' => trim((string) ($data['title'] ?? ''))],
                        'updateCore',
                        'Название задачи обновлено'
                    );
                }),

            Actions\Action::make('edit_description')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--wide'])
                ->modalHeading('Редактировать описание')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('2xl')
                ->fillForm(function (): array {
                    return [
                        'description' => $this->getEditableDescriptionValue(),
                    ];
                })
                ->form([
                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(10)
                        ->required()
                        ->placeholder('Кратко опишите, что нужно сделать.'),
                ])
                ->action(function (array $data): void {
                    $submittedDescription = trim((string) ($data['description'] ?? ''));
                    $originalDescription = ($this->record instanceof Task) && is_string($this->record->description ?? null)
                        ? (string) $this->record->description
                        : '';

                    [$technicalPrefix] = $this->splitTechnicalDescription($originalDescription);

                    $this->updateTask(
                        ['description' => $this->mergeTechnicalDescription($technicalPrefix, $submittedDescription)],
                        'updateCore',
                        'Описание обновлено'
                    );
                }),

            Actions\Action::make('edit_status')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--compact'])
                ->modalHeading('Редактировать статус')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('lg')
                ->fillForm(function (): array {
                    return [
                        'status' => $this->record instanceof Task ? (string) $this->record->status : Task::STATUS_NEW,
                    ];
                })
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('Статус задачи')
                        ->options(function (): array {
                            if (! ($this->record instanceof Task)) {
                                return Task::statusOptions();
                            }

                            return $this->statusOptionsForTask($this->record);
                        })
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $this->updateTask(
                        ['status' => (string) ($data['status'] ?? Task::STATUS_NEW)],
                        'updateStatus',
                        'Статус задачи обновлён'
                    );
                }),

            Actions\Action::make('edit_priority')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--compact'])
                ->modalHeading('Редактировать приоритет')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('lg')
                ->fillForm(function (): array {
                    return [
                        'priority' => $this->record instanceof Task ? (string) $this->record->priority : Task::PRIORITY_NORMAL,
                    ];
                })
                ->form([
                    Forms\Components\Select::make('priority')
                        ->label('Приоритет')
                        ->options(Task::priorityOptions())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $this->updateTask(
                        ['priority' => (string) ($data['priority'] ?? Task::PRIORITY_NORMAL)],
                        'updateCore',
                        'Приоритет обновлён'
                    );
                }),

            Actions\Action::make('edit_due_at')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--compact'])
                ->modalHeading('Редактировать дедлайн')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('lg')
                ->fillForm(function (): array {
                    return [
                        'due_at' => $this->record instanceof Task ? $this->record->due_at : null,
                    ];
                })
                ->form([
                    Forms\Components\DateTimePicker::make('due_at')
                        ->label('Дедлайн')
                        ->seconds(false)
                        ->placeholder('Без дедлайна'),
                ])
                ->action(function (array $data): void {
                    $this->updateTask(
                        ['due_at' => $data['due_at'] ?? null],
                        'updateCore',
                        'Дедлайн обновлён'
                    );
                }),

            Actions\Action::make('edit_assignee')
                ->visible(true)
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--compact'])
                ->modalHeading('Редактировать исполнителя')
                ->modalSubmitActionLabel('Сохранить')
                ->modalWidth('lg')
                ->fillForm(function (): array {
                    return [
                        'assignee_id' => $this->record instanceof Task ? $this->record->assignee_id : null,
                    ];
                })
                ->form([
                    Forms\Components\Select::make('assignee_id')
                        ->label('Исполнитель')
                        ->placeholder('Не назначен')
                        ->relationship('assignee', 'name', function (Builder $query): Builder {
                            return TaskResource::limitAssignableUsersToMarket($query, auth()->user());
                        })
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $this->updateTask(
                        ['assignee_id' => $data['assignee_id'] ?? null],
                        'updateCore',
                        'Исполнитель обновлён'
                    );
                }),

            Actions\Action::make('accept')
                ->label('Принять')
                ->icon('heroicon-o-play')
                ->color('gray')
                ->size(Size::Small)
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--accept'])
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user
                        && ($this->record instanceof Task)
                        && ((string) $this->record->status === (string) Task::STATUS_NEW)
                        && Gate::forUser($user)->allows('accept', $this->record);
                })
                ->action(function (): void {
                    $this->transitionTo(
                        toStatus: Task::STATUS_IN_PROGRESS,
                        allowedFrom: [Task::STATUS_NEW],
                        ability: 'accept',
                        successTitle: 'Задача принята в работу'
                    );
                }),

            Actions\Action::make('pause')
                ->label('Пауза')
                ->icon('heroicon-o-pause')
                ->color('gray')
                ->size(Size::Small)
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--pause'])
                ->visible(function (): bool {
                    return $this->canUpdateStatus()
                        && ($this->record instanceof Task)
                        && ((string) $this->record->status === (string) Task::STATUS_IN_PROGRESS);
                })
                ->action(function (): void {
                    $this->transitionTo(
                        toStatus: Task::STATUS_ON_HOLD,
                        allowedFrom: [Task::STATUS_IN_PROGRESS],
                        ability: 'updateStatus',
                        successTitle: 'Задача поставлена на паузу'
                    );
                }),

            Actions\Action::make('resume')
                ->label('Возобновить')
                ->icon('heroicon-o-play')
                ->color('gray')
                ->size(Size::Small)
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--resume'])
                ->visible(function (): bool {
                    return $this->canUpdateStatus()
                        && ($this->record instanceof Task)
                        && ((string) $this->record->status === (string) Task::STATUS_ON_HOLD);
                })
                ->action(function (): void {
                    $this->transitionTo(
                        toStatus: Task::STATUS_IN_PROGRESS,
                        allowedFrom: [Task::STATUS_ON_HOLD],
                        ability: 'updateStatus',
                        successTitle: 'Задача возобновлена'
                    );
                }),

            Actions\Action::make('complete')
                ->label('Завершить')
                ->icon('heroicon-o-stop')
                ->color('gray')
                ->size(Size::Small)
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--complete'])
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--confirm'])
                ->requiresConfirmation()
                ->modalHeading('Завершить задачу?')
                ->modalSubmitActionLabel('Завершить')
                ->visible(function (): bool {
                    if (! ($this->record instanceof Task)) {
                        return false;
                    }

                    return $this->canUpdateStatus()
                        && in_array((string) $this->record->status, [Task::STATUS_IN_PROGRESS, Task::STATUS_ON_HOLD], true);
                })
                ->action(function (): void {
                    $this->transitionTo(
                        toStatus: Task::STATUS_COMPLETED,
                        allowedFrom: [Task::STATUS_IN_PROGRESS, Task::STATUS_ON_HOLD],
                        ability: 'updateStatus',
                        successTitle: 'Задача завершена'
                    );
                }),

            Actions\Action::make('cancel')
                ->label('Отменить')
                ->icon('heroicon-o-no-symbol')
                ->color('gray')
                ->size(Size::Small)
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--cancel'])
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--confirm'])
                ->requiresConfirmation()
                ->modalHeading('Отменить задачу?')
                ->modalSubmitActionLabel('Отменить')
                ->visible(function (): bool {
                    if (! ($this->record instanceof Task)) {
                        return false;
                    }

                    return $this->canUpdateStatus()
                        && ! in_array((string) $this->record->status, Task::CLOSED_STATUSES, true);
                })
                ->action(function (): void {
                    $this->transitionTo(
                        toStatus: Task::STATUS_CANCELLED,
                        allowedFrom: [Task::STATUS_NEW, Task::STATUS_IN_PROGRESS, Task::STATUS_ON_HOLD],
                        ability: 'updateStatus',
                        successTitle: 'Задача отменена'
                    );
                }),

            Actions\DeleteAction::make()
                ->label('Удалить')
                ->icon('heroicon-o-trash')
                ->size(Size::Small)
                ->color('gray')
                ->extraAttributes(['class' => 'task-hero-action task-hero-action--delete'])
                ->extraModalWindowAttributes(['class' => 'task-modal task-modal--confirm'])
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user
                        && ($this->record instanceof Task)
                        && Gate::forUser($user)->allows('delete', $this->record);
                }),
        ];
    }

    protected function edit_titleAction(): Actions\Action
    {
        return $this->getHeaderActions()[0];
    }

    protected function edit_descriptionAction(): Actions\Action
    {
        return $this->getHeaderActions()[1];
    }

    protected function edit_statusAction(): Actions\Action
    {
        return $this->getHeaderActions()[2];
    }

    protected function edit_priorityAction(): Actions\Action
    {
        return $this->getHeaderActions()[3];
    }

    protected function edit_due_atAction(): Actions\Action
    {
        return $this->getHeaderActions()[4];
    }

    protected function edit_assigneeAction(): Actions\Action
    {
        return $this->getHeaderActions()[5];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! ($record instanceof Task)) {
            $record->fill($data);
            $record->save();

            return $record;
        }

        $data = $this->filterDisallowedFormData($data, $record);

        $record->fill($data);
        $record->save();

        return $record;
    }

    private function filterDisallowedFormData(array $data, Task $task): array
    {
        $user = auth()->user();

        unset($data['market_id'], $data['created_by_user_id']);

        if (! $user) {
            return [];
        }

        $canUpdateCore = Gate::forUser($user)->allows('updateCore', $task);
        $canUpdateStatus = Gate::forUser($user)->allows('updateStatus', $task);

        if (! $canUpdateCore) {
            unset(
                $data['title'],
                $data['description'],
                $data['priority'],
                $data['due_at'],
                $data['assignee_id']
            );
        }

        if (! $canUpdateStatus) {
            unset($data['status']);
        }

        return $data;
    }

    private function canUpdateStatus(): bool
    {
        $user = auth()->user();

        return $user
            && ($this->record instanceof Task)
            && Gate::forUser($user)->allows('updateStatus', $this->record);
    }

    private function transitionTo(string $toStatus, array $allowedFrom, string $ability, string $successTitle): void
    {
        $user = auth()->user();

        if (! $user || ! ($this->record instanceof Task)) {
            return;
        }

        /** @var Task $task */
        $task = $this->record->fresh();

        if (! $task) {
            return;
        }

        if (! Gate::forUser($user)->allows($ability, $task)) {
            return;
        }

        if (! in_array((string) $task->status, array_map('strval', $allowedFrom), true)) {
            return;
        }

        if ((string) $task->status === (string) $toStatus) {
            return;
        }

        $task->forceFill(['status' => $toStatus])->save();

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    /**
     * Keep generator metadata in storage, but keep it out of the editable textarea.
     *
     * @return array{0: string, 1: string}
     */
    private function splitTechnicalDescription(string $description): array
    {
        $lines = preg_split('/\R/u', $description) ?: [];

        $technical = [];
        $public = [];
        $collectTechnical = true;

        foreach ($lines as $line) {
            $normalized = trim($line);
            $isTechnicalLine = ($normalized === '')
                || str_starts_with($normalized, 'calendar_scenario=')
                || str_starts_with($normalized, 'Событие календаря:');

            if ($collectTechnical && $isTechnicalLine) {
                $technical[] = rtrim($line);
                continue;
            }

            $collectTechnical = false;
            $public[] = rtrim($line);
        }

        return [
            $this->joinTrimmedLines($technical),
            $this->joinTrimmedLines($public),
        ];
    }

    private function mergeTechnicalDescription(string $technicalPrefix, string $publicDescription): ?string
    {
        $technicalPrefix = trim($technicalPrefix);
        $publicDescription = trim($publicDescription);

        if ($technicalPrefix === '' && $publicDescription === '') {
            return null;
        }

        if ($technicalPrefix === '') {
            return $publicDescription;
        }

        if ($publicDescription === '') {
            return $technicalPrefix;
        }

        return $technicalPrefix . PHP_EOL . PHP_EOL . $publicDescription;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function joinTrimmedLines(array $lines): string
    {
        while (($lines !== []) && trim((string) reset($lines)) === '') {
            array_shift($lines);
        }

        while (($lines !== []) && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        return implode(PHP_EOL, $lines);
    }
}
