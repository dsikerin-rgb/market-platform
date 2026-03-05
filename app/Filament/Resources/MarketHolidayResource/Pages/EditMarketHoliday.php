<?php

declare(strict_types=1);

namespace App\Filament\Resources\MarketHolidayResource\Pages;

use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Artisan;

class EditMarketHoliday extends BaseEditRecord
{
    protected static string $resource = MarketHolidayResource::class;

    public function getTitle(): string
    {
        $title = trim((string) ($this->record?->title ?? ''));

        return $title !== ''
            ? $title
            : (string) parent::getTitle();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        array_unshift(
            $actions,
            Action::make('regenerateCalendarTasks')
                ->label('Обновить сценарные задачи')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Пересобрать задачи по событию?')
                ->modalDescription('Команда обновит/создаст задачи сценариев без дублей только для даты этого события.')
                ->action(function (): void {
                    $record = $this->record;

                    $date = $record->starts_at?->toDateString();
                    if (! $date) {
                        Notification::make()
                            ->title('Не удалось определить дату события')
                            ->danger()
                            ->send();

                        return;
                    }

                    Artisan::call('market:calendar:generate-tasks', [
                        '--market_id' => (int) $record->market_id,
                        '--from' => $date,
                        '--to' => $date,
                    ]);

                    Notification::make()
                        ->title('Сценарные задачи обновлены')
                        ->success()
                        ->send();
                })
        );

        return $actions;
    }
}
