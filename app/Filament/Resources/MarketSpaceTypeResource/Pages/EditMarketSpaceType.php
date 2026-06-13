<?php

namespace App\Filament\Resources\MarketSpaceTypeResource\Pages;

use App\Filament\Resources\MarketSpaceTypeResource;
use Filament\Actions;
use App\Filament\Resources\Pages\BaseEditRecord;
use Illuminate\Validation\ValidationException;

class EditMarketSpaceType extends BaseEditRecord
{
    protected static string $resource = MarketSpaceTypeResource::class;

    protected static ?string $title = 'Редактировать тип торгового места';

    public function getBreadcrumb(): string
    {
        return 'Редактировать тип торгового места';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Удалить'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $marketId = (int) ($data['market_id'] ?? $this->record?->market_id ?? 0);
        $name = trim((string) ($data['name_ru'] ?? ''));

        if ($marketId > 0 && $name !== '') {
            $duplicate = MarketSpaceTypeResource::findDuplicateByName(
                $marketId,
                $name,
                (int) $this->record->getKey(),
            );

            if ($duplicate !== null) {
                throw ValidationException::withMessages([
                    'name_ru' => 'Такой тип места уже есть в этом рынке.',
                ]);
            }
        }

        $data['name_ru'] = $name;

        return $data;
    }
}
