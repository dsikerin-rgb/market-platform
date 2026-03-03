<?php

declare(strict_types=1);

# app/Filament/Resources/IntegrationExchangeResource/Pages/EditIntegrationExchange.php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use App\Models\IntegrationExchange;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;
use Filament\Support\Enums\Width;

class EditIntegrationExchange extends BaseEditRecord
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Просмотр обмена интеграции';

    public function getBreadcrumb(): string
    {
        return 'Просмотр обмена';
    }

    /**
     * Делает страницу шире, чтобы payload/JSON читались комфортно.
     */
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * Для входящих обменов (1С) — только просмотр, без сохранения,
     * чтобы не “подтирать историю” руками.
     */
    protected function isReadOnly(): bool
    {
        /** @var IntegrationExchange $record */
        $record = $this->record;

        return $record->direction === IntegrationExchange::DIRECTION_IN;
    }

    protected function getHeaderActions(): array
    {
        /** @var IntegrationExchange $record */
        $record = $this->record;

        $actions = [];

        // Явный индикатор режима (эргономика: сразу видно, можно ли что-то менять).
        if ($this->isReadOnly()) {
            $actions[] = Actions\Action::make('readonly_hint')
                ->label('Только просмотр')
                ->color('gray')
                ->disabled()
                ->action(fn () => null);
        }

        // Удаление оставим только если разрешено ресурсом.
        if (static::getResource()::canDelete($record)) {
            $actions[] = Actions\DeleteAction::make()->label('Удалить');
        }

        return $actions;
    }

    protected function getFormActions(): array
    {
        if ($this->isReadOnly()) {
            return [];
        }

        return parent::getFormActions();
    }
}