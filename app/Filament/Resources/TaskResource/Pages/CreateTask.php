<?php

# app/Filament/Resources/TaskResource/Pages/CreateTask.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Resources\Pages\CreateRecord;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected static ?string $title = 'Создать задачу';

    // Убираем кнопку "Create & create another"
    protected static bool $canCreateAnother = false;

    public function getBreadcrumb(): string
    {
        return 'Создать задачу';
    }

    // После создания — сразу в список задач
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Если "Статус" скрыт на create — всё равно гарантируем дефолт
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! array_key_exists('status', $data) || blank($data['status'])) {
            $data['status'] = $this->resolveDefaultStatusValue();
        }

        return $data;
    }

    private function resolveDefaultStatusValue(): string
    {
        // 1) Если есть enum App\Enums\TaskStatus — попробуем найти case NEW (без учёта регистра)
        $enum = 'App\\Enums\\TaskStatus';

        if (enum_exists($enum)) {
            foreach (call_user_func([$enum, 'cases']) as $case) {
                if (strcasecmp($case->name, 'NEW') === 0) {
                    return $case instanceof \BackedEnum
                        ? (string) $case->value
                        : (string) $case->name;
                }
            }
        }

        // 2) Фоллбэк — строго по константе модели (чтобы не промахнуться с реальным значением)
        return Task::STATUS_NEW;
    }
}
