<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    public function getTitle(): string|Htmlable
    {
        $name = trim((string) ($this->record?->name ?? ''));

        return $name !== '' ? $name : 'Редактирование арендатора';
    }

    public function getBreadcrumb(): string
    {
        return 'Редактирование арендатора';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить арендатора'),
        ];
    }
}
