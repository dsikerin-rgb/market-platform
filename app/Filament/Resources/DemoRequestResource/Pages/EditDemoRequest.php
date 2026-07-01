<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequestResource\Pages;

use App\Filament\Resources\DemoRequestResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use App\Models\DemoRequest;
use Illuminate\Support\Carbon;

class EditDemoRequest extends BaseEditRecord
{
    protected static string $resource = DemoRequestResource::class;

    protected static ?string $title = 'Обработка заявки на демо';

    public function getBreadcrumb(): string
    {
        return 'Обработка заявки';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? DemoRequest::STATUS_NEW) !== DemoRequest::STATUS_NEW && blank($data['processed_at'] ?? null)) {
            $data['processed_at'] = Carbon::now();
        }

        if (($data['status'] ?? DemoRequest::STATUS_NEW) === DemoRequest::STATUS_NEW) {
            $data['processed_at'] = null;
        }

        return $data;
    }
}
