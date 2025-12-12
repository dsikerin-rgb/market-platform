<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // guard всегда web
        $data['guard_name'] = 'web';

        // На всякий случай: если вдруг name пустой — пусть будет явная ошибка на уровне БД/валидации
        $data['name'] = trim((string) ($data['name'] ?? ''));

        // Нормализация: если вдруг сюда попало "__custom" (не должно, но на всякий)
        if (($data['name'] ?? null) === '__custom') {
            $data['name'] = '';
        }

        return $data;
    }
}
