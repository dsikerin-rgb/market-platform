<?php

namespace App\Filament\Resources\OperationResource\Pages;

use App\Filament\Resources\OperationResource;
use App\Models\Market;
use App\Services\Operations\MarketPeriodResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    protected static ?string $title = 'Операции';

    public function getBreadcrumb(): string
    {
        return 'Операции';
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\CreateAction::make()
                ->label('Создать операцию'),
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
