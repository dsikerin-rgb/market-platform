<?php
# app/Filament/Widgets/RecentTenantRequestsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\TenantRequest;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentTenantRequestsWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Открытые обращения арендаторов';

    protected int|string|array $columnSpan = 'full';

    protected int $recordsPerPage = 10;

    private string $marketTimezone = 'UTC';

    private ?string $emptyStateNote = null;

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        if (! $user) {
            $this->emptyStateNote = 'Нет пользователя';

            return TenantRequest::query()->whereRaw('1 = 0');
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? $this->resolveSelectedMarketId()
            : (int) ($user->market_id ?: 0);

        if ($marketId <= 0) {
            $this->emptyStateNote = $isSuperAdmin ? 'Выбери рынок' : 'Нет привязки к рынку';

            return TenantRequest::query()->whereRaw('1 = 0');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $this->marketTimezone = $this->resolveTimezone($market?->timezone);
        $this->emptyStateNote = 'Только открытые (TZ: ' . $this->marketTimezone . ')';

        // Открытые обращения: полезнее, чем "последние"
        // Сортировка: urgent/high сверху, внутри — самые старые (created_at asc)
        return TenantRequest::query()
            ->where('market_id', $marketId)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->orderByRaw(
                "CASE priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                 END"
            )
            ->orderBy('created_at', 'asc');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('tenant.name')
                ->label('Арендатор')
                ->sortable()
                ->searchable(),

            TextColumn::make('subject')
                ->label('Тема')
                ->wrap()
                ->searchable(),

            TextColumn::make('priority')
                ->label('Приоритет')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'low' => 'Низкий',
                    'normal' => 'Обычный',
                    'high' => 'Высокий',
                    'urgent' => 'Критичный',
                    default => $state,
                })
                ->sortable(),

            TextColumn::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'new' => 'Новое',
                    'in_progress' => 'В работе',
                    'resolved' => 'Решено',
                    'closed' => 'Закрыто',
                    default => $state,
                })
                ->sortable(),

            TextColumn::make('created_at')
                ->label('Создано')
                ->formatStateUsing(function ($state): ?string {
                    if (! $state) {
                        return null;
                    }

                    return CarbonImmutable::parse($state)
                        ->setTimezone($this->marketTimezone ?: 'UTC')
                        ->format('d.m.Y H:i');
                })
                ->sortable(),

            TextColumn::make('days_open')
                ->label('Дней в работе')
                ->state(function (TenantRequest $record): ?int {
                    if (! $record->created_at) {
                        return null;
                    }

                    $tz = $this->marketTimezone ?: 'UTC';

                    $today = CarbonImmutable::now($tz)->startOfDay();
                    $created = CarbonImmutable::parse($record->created_at)->setTimezone($tz)->startOfDay();

                    // Положительное число дней, сколько заявка "в работе"
                    return max($created->diffInDays($today), 0);
                }),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return 'Нет данных';
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return $this->emptyStateNote;
    }

    private function resolveSelectedMarketId(): int
    {
        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament_{$panelId}_market_id");
        }

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return (int) ($value ?: 0);
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }
}
