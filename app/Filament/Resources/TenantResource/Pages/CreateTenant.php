<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\Concerns\InteractsWithTenantCabinet;

class CreateTenant extends BaseCreateRecord
{
    use InteractsWithTenantCabinet;

    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Создать арендатора';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->cabinetPayload = $this->pullCabinetPayloadFromForm($data);
        $this->validateCabinetPayload($this->cabinetPayload, null);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncCabinetPayload($this->record, $this->cabinetPayload);
    }
}