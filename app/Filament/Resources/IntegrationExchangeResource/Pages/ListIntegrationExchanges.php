<?php

declare(strict_types=1);

# app/Filament/Resources/IntegrationExchangeResource/Pages/ListIntegrationExchanges.php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use App\Models\IntegrationExchange;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ListIntegrationExchanges extends ListRecords
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Обмены интеграций';

    public function getBreadcrumb(): string
    {
        return 'Обмены интеграций';
    }

    protected function getHeaderActions(): array
    {
        $user = Filament::auth()->user();

        // Создание — только super-admin (остальные пусть видят журнал)
        if (! $user || ! $user->isSuperAdmin()) {
            return [];
        }

        return [
            Actions\CreateAction::make()
                ->label('Создать обмен'),
        ];
    }

    /**
     * Делаем страницу “операторской”: фильтры по типу/статусу/направлению + по умолчанию показываем свежие.
     */
    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery()
            ->orderByDesc('started_at');

        if (request()->boolean('recent_errors')) {
            $since = now()->subDays(7);

            $query->where('status', IntegrationExchange::STATUS_ERROR)
                ->where(function (Builder $builder) use ($since): void {
                    $builder->where('finished_at', '>=', $since)
                        ->orWhere('created_at', '>=', $since);
                });
        }

        return $query;
    }

    protected function getTableFilters(): array
    {
        return [
            SelectFilter::make('entity_type')
                ->label('Сущность')
                ->options([
                    'contract_debts' => 'Долги (1С)',
                ])
                ->placeholder('Все'),

            SelectFilter::make('direction')
                ->label('Направление')
                ->options([
                    IntegrationExchange::DIRECTION_IN => 'IN',
                    IntegrationExchange::DIRECTION_OUT => 'OUT',
                ])
                ->placeholder('Все'),

            SelectFilter::make('status')
                ->label('Статус')
                ->options([
                    IntegrationExchange::STATUS_IN_PROGRESS => 'В работе',
                    IntegrationExchange::STATUS_OK => 'OK',
                    IntegrationExchange::STATUS_ERROR => 'ERROR',
                ])
                ->placeholder('Все'),
        ];
    }

    /**
     * Для совместимости: payload иногда может быть строкой/массивом — тут не используем, но оставим хелпер при надобности.
     */
    private static function payloadGet(mixed $payload, string $key, mixed $default = null): mixed
    {
        if (! is_array($payload)) {
            return $default;
        }

        return Arr::get($payload, $key, $default);
    }
}
