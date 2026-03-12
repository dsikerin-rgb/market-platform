<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use App\Models\Market;
use App\Services\Operations\MarketPeriodResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Журнал операций';

    public function getBreadcrumb(): string
    {
        return 'Журнал операций';
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\CreateAction::make()
                ->label('Новая операция'),
        ];

        if (class_exists(\Filament\Actions\Action::class)) {
            $actions[] = \Filament\Actions\Action::make('export_registry')
                ->label('Экспорт реестра')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => $this->exportUrl())
                ->openUrlInNewTab();
        }

        return $actions;
    }

    public function getSubheading(): ?string
    {
        return 'Служебный журнал локальных управленческих действий. Договоры, начисления, оплаты и долги ведутся в 1С.';
    }

    public function getEmptyStateHeading(): ?string
    {
        return 'Журнал пока пуст';
    }

    public function getEmptyStateDescription(): ?string
    {
        return 'Для выбранного рынка ещё не создавались управленческие операции. Этот раздел используется как служебный журнал и не заменяет договорный или финансовый контур 1С.';
    }

    private function exportUrl(): string
    {
        $marketId = OperationResource::resolveMarketId();
        $market = $marketId > 0 ? Market::query()->find($marketId) : null;
        $resolver = app(MarketPeriodResolver::class);

        $period = $market
            ? $resolver->resolveMarketPeriod($market, request()->query('period'))
            : $resolver->normalizePeriodInput(request()->query('period'), config('app.timezone', 'UTC'));

        $periodValue = $period?->toDateString() ?? now()->startOfMonth()->toDateString();

        return route('filament.admin.operations.export', [
            'period' => $periodValue,
        ]);
    }
}
