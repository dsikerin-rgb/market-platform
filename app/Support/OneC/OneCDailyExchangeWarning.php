<?php

declare(strict_types=1);

namespace App\Support\OneC;

use App\Filament\Resources\IntegrationExchangeResource;

class OneCDailyExchangeWarning
{
    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     instruction: string,
     *     checked_at: string,
     *     window_label: string,
     *     action_url: string,
     *     modal_key: string,
     *     issues: list<array<string, mixed>>
     * }|null
     */
    public function build(int $marketId, ?string $timezone = null): ?array
    {
        if ($marketId <= 0) {
            return null;
        }

        $report = app(OneCDailyExchangeHealthReport::class)->build($marketId, $timezone);

        if ($report['ok']) {
            return null;
        }

        $issues = $report['issues'];

        return [
            'title' => 'Пропущена ежедневная выгрузка 1С',
            'description' => 'Система не получила полный ежедневный набор данных из 1С. Данные на дашборде, карте задолженности и в отчетах могут быть устаревшими.',
            'instruction' => 'Проверьте запуск run_prod.bat на сервере 1С и журнал обменов.',
            'checked_at' => $report['checked_at']->format('d.m.Y H:i'),
            'window_label' => (string) $report['window_hours'] . ' ч',
            'action_url' => IntegrationExchangeResource::getUrl('index'),
            'modal_key' => $this->modalKey($marketId, $issues),
            'issues' => $issues,
        ];
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function modalKey(int $marketId, array $issues): string
    {
        $signature = array_map(static function (array $issue): array {
            return [
                'entity_type' => (string) ($issue['entity_type'] ?? ''),
                'status' => (string) ($issue['status'] ?? ''),
                'required_success_count' => (int) ($issue['required_success_count'] ?? 0),
                'recent_success_count' => (int) ($issue['recent_success_count'] ?? 0),
                'latest_ok_label' => (string) ($issue['latest_ok_label'] ?? ''),
                'latest_error_label' => (string) ($issue['latest_error_label'] ?? ''),
            ];
        }, $issues);

        return 'one-c-daily-exchange-warning:' . $marketId . ':' . substr(
            hash('sha256', (string) json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            0,
            16,
        );
    }
}
