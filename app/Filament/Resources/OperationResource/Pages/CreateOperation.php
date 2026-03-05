<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CreateOperation extends BaseCreateRecord
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Создать операцию';

    protected ?string $returnUrl = null;

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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Сохранить операцию');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Сохранить и создать следующую');
    }
}
