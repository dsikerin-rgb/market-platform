<?php
# app/Filament/Widgets/ExpiringContractsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\TenantContract;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseTableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringContractsWidget extends BaseTableWidget
{
    use InteractsWithPageFilters;

    /**
     * Важно: при смене pageFilters (отчётного месяца) Livewire пересобирает страницу.
     * Lazy TableWidget может попасть в состояние, когда $table ещё не инициализирован,
     * и Filament падает с:
     * "Typed property Filament\Widgets\TableWidget::$table must not be accessed before initialization".
     *
     * Поэтому отключаем lazy для этого виджета — он должен строить таблицу сразу.
     */
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Окончания договоров в выбранном месяце';

    protected int|string|array $columnSpan = 'full';

    private string $marketTimezone = 'UTC';

    private ?string $emptyStateNote = null;

    // В Filament\TableWidget этот метод public — должен совпадать уровень доступа.
    public function getTableRecordsPerPage(): int
    {
        return 10;
    }

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        if (! $user) {
            $this->emptyStateNote = 'Нет пользователя';

            return TenantContract::query()->whereRaw('1 = 0');
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? $this->resolveSelectedMarketId()
            : (int) ($user->market_id ?: 0);

        if ($marketId <= 0) {
            $this->emptyStateNote = $isSuperAdmin ? 'Выбери рынок' : 'Нет привязки к рынку';

            return TenantContract::query()->whereRaw('1 = 0');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $this->marketTimezone = $this->resolveTimezone($market?->timezone);

        [, $monthStartTz, $monthEndTz, $periodLabel] = $this->resolveMonthRange($this->marketTimezone);

        // ends_at — DATE, поэтому работаем по date-границам (без UTC-конверсий).
        $monthStartDate = $monthStartTz->toDateString();
        $monthEndDate = $monthEndTz->toDateString();

        $this->emptyStateNote = 'Период: ' . $periodLabel;

        $query = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', $monthStartDate)
            ->where('ends_at', '<', $monthEndDate)
            ->orderBy('ends_at');

        // Обычно полезно фокусироваться на “живых” договорах.
        $query->whereIn('status', ['active', 'paused']);

        return $query;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('tenant.name')
                ->label('Арендатор')
                ->sortable()
                ->searchable(),

            TextColumn::make('number')
                ->label('Номер договора')
                ->sortable()
                ->searchable(),

            TextColumn::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state) => match ($state) {
                    'draft' => 'Черновик',
                    'active' => 'Активен',
                    'paused' => 'Приостановлен',
                    'terminated' => 'Расторгнут',
                    'archived' => 'Архив',
                    default => $state,
                }),

            TextColumn::make('starts_at')
                ->label('Дата начала')
                ->formatStateUsing(function ($state): ?string {
                    if (! $state) {
                        return null;
                    }

                    return CarbonImmutable::parse($state)
                        ->setTimezone($this->marketTimezone ?: 'UTC')
                        ->format('d.m.Y');
                }),

            TextColumn::make('ends_at')
                ->label('Дата окончания')
                ->formatStateUsing(function ($state): ?string {
                    if (! $state) {
                        return null;
                    }

                    return CarbonImmutable::parse($state)
                        ->setTimezone($this->marketTimezone ?: 'UTC')
                        ->format('d.m.Y');
                })
                ->sortable(),

            TextColumn::make('days_left')
                ->label('Осталось дней')
                ->state(function (TenantContract $record): ?int {
                    if (! $record->ends_at) {
                        return null;
                    }

                    $tz = $this->marketTimezone ?: 'UTC';

                    $today = CarbonImmutable::now($tz)->startOfDay();
                    $ends = CarbonImmutable::parse($record->ends_at)->setTimezone($tz)->startOfDay();

                    return $today->diffInDays($ends, false);
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

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable,3:string}
     */
    private function resolveMonthRange(string $tz): array
    {
        $raw = null;

        if (is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        $monthYm = is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw)
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end = $start->addMonth();

        $label = $start->format('m.Y') . ' (TZ: ' . $tz . ')';

        return [$monthYm, $start, $end, $label];
    }
}