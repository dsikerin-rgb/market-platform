<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOperation extends CreateRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Создать операцию';

    protected ?string $returnUrl = null;

    public function getBreadcrumb(): string
    {
        return 'Создать';
    }

    public function mount(): void
    {
        parent::mount();

        $this->returnUrl = request()->query('return_url');
        if (is_string($this->returnUrl) && $this->returnUrl !== '') {
            session(['operations.return_url' => $this->returnUrl]);
        }
    }

    protected function getRedirectUrl(): string
    {
        $returnUrl = $this->returnUrl ?: session('operations.return_url');

        return is_string($returnUrl) && $returnUrl !== ''
            ? $returnUrl
            : $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Операция создана')
            ->success()
            ->send();
    }
}
