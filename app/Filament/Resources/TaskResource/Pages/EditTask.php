<?php

# app/Filament/Resources/TaskResource/Pages/EditTask.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    /**
     * Заголовок формируем динамически, чтобы не дублировать.
     */
    protected static ?string $title = null;

    /**
     * Чтобы 2 колонки реально помещались.
     */
    protected Width|string|null $maxContentWidth = Width::Full;

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
        return 'Редактирование';
    }

    /**
     * Двухколоночная структура: слева форма, справа RelationManagers (Чат/Файлы).
     * Правая колонка — узкая + resize по горизонтали (если браузер поддерживает).
     */
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

        // Левая колонка должна уметь сжиматься, иначе flex “ломает” ресайз справа.
        if ($form && method_exists($form, 'extraAttributes')) {
            $form->extraAttributes([
                'class' => 'min-w-0',
            ]);
        }

        if ($relations) {
            if (method_exists($relations, 'grow')) {
                $relations->grow(false);
            }

            if (method_exists($relations, 'extraAttributes')) {
                $relations->extraAttributes([
                    // shrink-0 чтобы фиксированная ширина работала корректно
                    'class' => 'lg:shrink-0',
                    // чат/файлы в правой колонке + resize
                    'style' => implode(' ', [
                        'width: 360px;',
                        'min-width: 320px;',
                        'max-width: 520px;',
                        'resize: horizontal;',
                        'overflow: auto;',
                        'max-height: calc(100vh - 10rem);',
                    ]),
                ]);
            }
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
                'class' => 'gap-6',
            ]);
        }

        return $schema->components([$flex]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('accept')
                ->label('Принять')
                ->icon('heroicon-o-play')
                ->color('success')
                ->size(Size::Large)
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
                ->size(Size::Large)
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
                ->color('warning')
                ->size(Size::Large)
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
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->size(Size::Large)
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
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->size(Size::Large)
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
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user
                        && ($this->record instanceof Task)
                        && Gate::forUser($user)->allows('delete', $this->record);
                }),
        ];
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
}
