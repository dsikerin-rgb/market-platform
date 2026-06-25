<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class WorkProgress extends Page
{
    protected static ?string $title = 'Ход работ';

    protected static ?string $navigationLabel = 'Ход работ';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static UnitEnum|string|null $navigationGroup = null;

    protected static ?int $navigationSort = 155;

    protected static ?string $slug = 'work-progress';

    protected string $view = 'filament.pages.work-progress';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $allowedUserIds = array_map(
            static fn (mixed $value): int => (int) $value,
            (array) config('saas_progress.access.allowed_user_ids', []),
        );
        $allowedEmails = array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            (array) config('saas_progress.access.allowed_user_emails', []),
        );

        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        return in_array((int) $user->id, $allowedUserIds, true)
            || ($email !== '' && in_array($email, $allowedEmails, true));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'progress' => $this->progressData(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressData(): array
    {
        $data = config('saas_progress', []);
        $stages = $this->normalizeStages((array) ($data['stages'] ?? []));
        $totalWeight = max(1, array_sum(array_map(fn (array $stage): int => (int) $stage['weight'], $stages)));
        $weightedDone = 0.0;

        foreach ($stages as $stage) {
            $weightedDone += ((int) $stage['weight']) * ((float) $stage['percent'] / 100);
        }

        $overallPercent = (int) round(($weightedDone / $totalWeight) * 100);
        $currentStage = collect($stages)->first(fn (array $stage): bool => $stage['status'] === 'in_progress')
            ?? collect($stages)->first(fn (array $stage): bool => $stage['status'] === 'pending')
            ?? null;

        $items = collect($stages)->flatMap(fn (array $stage): array => $stage['items'])->all();

        return [
            'title' => (string) ($data['title'] ?? 'Ход работ'),
            'subtitle' => (string) ($data['subtitle'] ?? ''),
            'lastUpdatedAt' => (string) ($data['last_updated_at'] ?? ''),
            'currentFocus' => (string) ($data['current_focus'] ?? ''),
            'nextStep' => (string) ($data['next_step'] ?? ''),
            'releasePolicy' => (string) ($data['release_policy'] ?? ''),
            'overallPercent' => max(0, min(100, $overallPercent)),
            'currentStage' => $currentStage,
            'stages' => $stages,
            'risks' => (array) ($data['risks'] ?? []),
            'counts' => [
                'done' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'done')),
                'in_progress' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'in_progress')),
                'pending' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'pending')),
                'blocked' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'blocked')),
                'total' => count($items),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $stages
     * @return list<array<string, mixed>>
     */
    private function normalizeStages(array $stages): array
    {
        return collect($stages)
            ->filter(fn (mixed $stage): bool => is_array($stage))
            ->map(function (array $stage): array {
                $items = collect((array) ($stage['items'] ?? []))
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(function (array $item): array {
                        $status = $this->normalizeStatus((string) ($item['status'] ?? 'pending'));

                        return [
                            'title' => (string) ($item['title'] ?? ''),
                            'status' => $status,
                            'statusLabel' => $this->statusLabel($status),
                            'statusColor' => $this->statusColor($status),
                        ];
                    })
                    ->values()
                    ->all();

                $percent = $this->stagePercent($items);
                $status = $this->normalizeStatus((string) ($stage['status'] ?? 'pending'));

                return [
                    'key' => (string) ($stage['key'] ?? ''),
                    'title' => (string) ($stage['title'] ?? ''),
                    'weight' => max(1, (int) ($stage['weight'] ?? 1)),
                    'status' => $status,
                    'statusLabel' => $this->statusLabel($status),
                    'statusColor' => $this->statusColor($status),
                    'summary' => (string) ($stage['summary'] ?? ''),
                    'items' => $items,
                    'percent' => $percent,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function stagePercent(array $items): int
    {
        if ($items === []) {
            return 0;
        }

        $points = 0.0;

        foreach ($items as $item) {
            $points += match ($item['status']) {
                'done' => 1.0,
                'in_progress' => 0.5,
                default => 0.0,
            };
        }

        return (int) round(($points / count($items)) * 100);
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['done', 'in_progress', 'pending', 'blocked'], true)
            ? $status
            : 'pending';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'done' => 'Готово',
            'in_progress' => 'В работе',
            'blocked' => 'Блокер',
            default => 'Ожидает',
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'done' => 'success',
            'in_progress' => 'warning',
            'blocked' => 'danger',
            default => 'gray',
        };
    }
}
