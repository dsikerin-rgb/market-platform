<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequestResource\Pages;

use App\Filament\Resources\DemoRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoRequests extends ListRecords
{
    protected static string $resource = DemoRequestResource::class;

    protected static ?string $title = 'Заявки на демо';

    public function getBreadcrumb(): string
    {
        return 'Заявки на демо';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
