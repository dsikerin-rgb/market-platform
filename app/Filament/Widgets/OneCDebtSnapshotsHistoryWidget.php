<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCDebtSnapshotsHistoryWidget extends ChartWidget
{
    protected ?string $heading = 'История 1С-снимков задолженности';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }

    public function getDescription(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return null;
        }

        $marketId = $this->resolveMarketIdForWidget($user);
        if (! $marketId) {
            return null;
        }

        $market = Market::query()
            ->select(['id', 'name', 'timezone'])
            ->find($marketId);

        if (! $market) {
            return null;
        }

        $tz = $this->resolveTimezone($market->timezone);

        return 'Локация: ' . (string) $market->name . ' • TZ: ' . $tz . ' • Последние 12 импортов /api/1c/contract-debts';
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyChart('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->emptyChart('Выберите рынок');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);

        $rows = $this->loadRecentDebtImports($marketId);

        if ($rows->isEmpty()) {
            return $this->emptyChart('Нет импортов задолженности 1С');
        }

        return [
            'labels' => $rows->map(fn ($row): string => $this->formatSnapshotLabel($row, $tz))->all(),
            'datasets' => [
                [
                    'label' => 'Получено',
                    'data' => $rows->map(fn ($row): int => (int) ($row->received ?? 0))->all(),
                    'backgroundColor' => '#fbbf24',
                    'borderColor' => '#fbbf24',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Добавлено',
                    'data' => $rows->map(fn ($row): int => (int) ($row->inserted ?? 0))->all(),
                    'backgroundColor' => '#34d399',
                    'borderColor' => '#34d399',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Пропущено',
                    'data' => $rows->map(fn ($row): int => (int) ($row->skipped ?? 0))->all(),
                    'backgroundColor' => '#60a5fa',
                    'borderColor' => '#60a5fa',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => true,
            'aspectRatio' => 1.9,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 10,
                        'font' => ['size' => 11],
                    ],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'x' => [
                    'stacked' => false,
                    'ticks' => [
                        'font' => ['size' => 10],
                        'autoSkip' => true,
                        'maxRotation' => 0,
                        'minRotation' => 0,
                    ],
                    'grid' => ['display' => false],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['font' => ['size' => 10]],
                ],
            ],
        ];
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id")
                ?? session("filament_{$panelId}_market_id");
        }

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        if (filled($value)) {
            return (int) $value;
        }

        return $this->resolveDefaultMarketId();
    }

    private function resolveDefaultMarketId(): ?int
    {
        $marketId = Market::query()
            ->orderBy('id')
            ->value('id');

        return $marketId ? (int) $marketId : null;
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

    private function formatSnapshotLabel(object $row, string $tz): string
    {
        $value = $row->snapshot_at ?? $row->created_at ?? $row->calculated_at ?? null;

        try {
            if ($value !== null) {
                return CarbonImmutable::parse((string) $value)
                    ->setTimezone($tz)
                    ->format('d.m H:i');
            }
        } catch (\Throwable) {
            // ignore and use fallback
        }

        return 'Импорт';
    }

    private function loadRecentDebtImports(int $marketId)
    {
        $exchangeRows = $this->loadRecentDebtImportsFromExchanges($marketId);

        if ($exchangeRows->isNotEmpty()) {
            return $exchangeRows;
        }

        return $this->loadRecentDebtImportsFromOneCLogs($marketId);
    }

    private function loadRecentDebtImportsFromExchanges(int $marketId)
    {
        if (! Schema::hasTable('integration_exchanges')) {
            return collect();
        }

        try {
            $rows = DB::table('integration_exchanges')
                ->where('market_id', $marketId)
                ->where('direction', 'in')
                ->where('entity_type', 'contract_debts')
                ->where('status', 'ok')
                ->orderByDesc(DB::raw('COALESCE(finished_at, started_at, created_at)'))
                ->orderByDesc('id')
                ->limit(12)
                ->get([
                    'payload',
                    'finished_at',
                    'started_at',
                    'created_at',
                ]);
        } catch (\Throwable) {
            return collect();
        }

        return $rows
            ->reverse()
            ->values()
            ->map(function (object $row): object {
                $payload = $this->decodePayloadToArray($row->payload ?? null);

                return (object) [
                    'snapshot_at' => $row->finished_at ?? $row->started_at ?? $row->created_at ?? null,
                    'created_at' => $row->created_at ?? null,
                    'received' => $this->payloadInt($payload, 'received'),
                    'inserted' => $this->payloadInt($payload, 'inserted'),
                    'skipped' => $this->payloadInt($payload, 'skipped'),
                ];
            });
    }

    private function loadRecentDebtImportsFromOneCLogs(int $marketId)
    {
        if (! Schema::hasTable('one_c_import_logs')) {
            return collect();
        }

        try {
            $rows = DB::table('one_c_import_logs')
                ->where('market_id', $marketId)
                ->where('status', 'ok')
                ->where('endpoint', 'like', '%contract-debts%')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get([
                    'created_at',
                    'calculated_at',
                    'received',
                    'inserted',
                    'skipped',
                ]);
        } catch (\Throwable) {
            return collect();
        }

        return $rows->reverse()->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayloadToArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadInt(array $payload, string $key, int $default = 0): int
    {
        $value = Arr::get($payload, $key);

        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return $default;
    }

    private function emptyChart(string $label): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'label' => 'Получено',
                    'data' => [0],
                ],
            ],
        ];
    }
}
