<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\Market;
use App\Services\Ai\AiReviewService;
use App\Services\MarketMap\MapReviewResultsService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MapReviewResults extends Page
{
    protected static ?string $title = 'Результаты ревизии';
    protected static ?string $navigationLabel = 'Результаты ревизии';
    protected static ?string $slug = 'map-review-results';

    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.map-review-results';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    protected function getViewData(): array
    {
        $marketId = $this->marketId();
        $market = $marketId ? Market::query()->find($marketId) : null;
        /** @var MapReviewResultsService $service */
        $service = app(MapReviewResultsService::class);

        $attentionTab = $this->attentionTab();
        $needsAttention = $marketId
            ? ($attentionTab === 'unconfirmed_links'
                ? $service->unconfirmedLinks($marketId, 50)
                : $service->needsAttention($marketId, 50))
            : [];
        $appliedChanges = $marketId ? $service->appliedChanges($marketId, 50) : [];

        $visibleNeedsAttentionRows = $this->buildNeedsAttentionRows($needsAttention, [], $attentionTab);

        $aiData = $marketId
            ? $this->buildAiSummaries($marketId, $this->selectVisibleAiBatch($visibleNeedsAttentionRows))
            : [
                'summaries' => [],
                'errors' => [],
                'meta' => [
                    'mode' => 'no_market',
                    'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                ],
            ];
        $needsAttentionRows = $this->buildNeedsAttentionRows($needsAttention, $aiData['summaries'], $attentionTab);

        return [
            'market' => $market,
            'marketName' => $market?->name ?? 'Выберите рынок',
            'hasSelectedMarket' => $market !== null,
            'progress' => $marketId ? $service->buildProgress($marketId) : [
                'total' => 0,
                'reviewed' => 0,
                'remaining' => 0,
                'percent' => 0,
                'counts' => [],
                'labels' => [],
            ],
            'needsAttention' => $needsAttentionRows,
            'attentionTab' => $attentionTab,
            'attentionReviewUrl' => request()->fullUrlWithQuery(['tab' => 'review']),
            'attentionUnconfirmedUrl' => request()->fullUrlWithQuery(['tab' => 'unconfirmed_links']),
            'appliedChanges' => array_map(
                fn (array $row): array => $row + [
                    'map_url' => $this->mapUrl((int) $row['space_id']),
                    'space_url' => $this->spaceUrl((int) $row['space_id']),
                ],
                $appliedChanges
            ),
            'aiSummaries' => $aiData['summaries'],
            'aiErrors' => $aiData['errors'],
            'aiMeta' => $aiData['meta'],
        ];
    }

    /**
     * Собрать AI reviews для первых 5 спорных мест.
     * Делегирует shared AiReviewService (policy, validation, caching).
     *
     * Quick cooldown: только на connectivity_fail (5 мин).
     * Policy/semantic fail НЕ включает cooldown — это единичный случай.
     *
     * @param  list<array{space_id:int}>  $needsAttention
     * @return array{
     *   summaries: array<int, array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null>,
     *   errors: array<int, 'connectivity'|'policy'|null>,
     *   meta: array{mode:string,limit:int}
     * }
     */
    protected function buildAiSummaries(int $marketId, array $needsAttention): array
    {
        if (empty($needsAttention)) {
            return [
                'summaries' => [],
                'errors' => [],
                'meta' => [
                    'mode' => 'empty',
                    'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                ],
            ];
        }

        try {
            $reviewService = app(AiReviewService::class);

            if (! $reviewService->isAvailable()) {
                return [
                    'summaries' => [],
                    'errors' => [],
                    'meta' => [
                        'mode' => 'disabled',
                        'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                    ],
                ];
            }

            // Quick cooldown: только если GigaChat недоступен на уровне сети/auth
            $downKey = 'gigachat_connectivity_down';
            if (Cache::get($downKey)) {
                return [
                    'summaries' => [],
                    'errors' => [],
                    'meta' => [
                        'mode' => 'connectivity_cooldown',
                        'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                    ],
                ];
            }

            // Лимит: максимум 5 первых кейсов за один рендер страницы
            $limited = array_slice($needsAttention, 0, AiReviewService::MAX_REVIEWS_PER_BATCH);

            $results = [];
            $errors = [];
            $connectivityFails = 0;

            foreach ($limited as $row) {
                $spaceId = (int) $row['space_id'];

                try {
                    $fetchResult = $reviewService->getReviewForSpace($spaceId, $marketId);
                    $results[$spaceId] = $fetchResult['review'] ?? null;
                    $errors[$spaceId] = $fetchResult['error_type'] ?? null;

                    // connectivity_fail -> candidate для cooldown
                    if (($fetchResult['error_type'] ?? null) === 'connectivity') {
                        $connectivityFails++;
                    }
                } catch (\Throwable $e) {
                    logger()->warning('AI review render fallback', [
                        'space_id' => $spaceId,
                        'market_id' => $marketId,
                        'message' => $e->getMessage(),
                    ]);

                    $results[$spaceId] = null;
                    $errors[$spaceId] = 'connectivity';
                }

                // policy_fail -> НЕ включаем в cooldown (единичный случай)
            }

            // Если все запросы — connectivity_fail — блокируем на 5 мин
            if ($connectivityFails === count($limited)) {
                Cache::put($downKey, true, now()->addMinutes(5));
            }

            return [
                'summaries' => $results,
                'errors' => $errors,
                'meta' => [
                    'mode' => 'ok',
                    'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                ],
            ];
        } catch (\Throwable $e) {
            logger()->warning('AI review page fallback', [
                'market_id' => $marketId,
                'message' => $e->getMessage(),
            ]);

            return [
                'summaries' => [],
                'errors' => [],
                'meta' => [
                    'mode' => 'page_error',
                    'limit' => AiReviewService::MAX_REVIEWS_PER_BATCH,
                ],
            ];
        }
    }

    protected function attentionTab(): string
    {
        $tab = (string) request()->query('tab', 'review');

        return in_array($tab, ['review', 'unconfirmed_links'], true) ? $tab : 'review';
    }

    /**
     * @param  list<array<string, mixed>>  $needsAttention
     * @param  array<int, array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null>  $aiSummaries
     * @return array<int, array<string, mixed>>
     */
    protected function buildNeedsAttentionRows(array $needsAttention, array $aiSummaries, string $attentionTab): array
    {
        $rows = [];

        foreach ($needsAttention as $index => $row) {
            $spaceId = (int) ($row['space_id'] ?? 0);
            $aiSummary = $aiSummaries[$spaceId] ?? null;
            $priority = $this->buildAiPriorityMeta($row, is_array($aiSummary) ? $aiSummary : null);
            $diagnostics = is_array($row['diagnostics'] ?? null) ? $row['diagnostics'] : [];
            $diagnostics['candidate_spaces'] = array_map(
                fn (array $candidate): array => $candidate + [
                    'space_url' => $this->spaceUrl((int) ($candidate['space_id'] ?? 0)),
                    'map_url' => $this->mapUrl((int) ($candidate['space_id'] ?? 0)),
                ],
                is_array($diagnostics['candidate_spaces'] ?? null) ? $diagnostics['candidate_spaces'] : []
            );
            $assessment = $this->buildAssessmentMeta($diagnostics);

            $rows[] = array_merge($row, [
                'map_url' => $this->mapUrl($spaceId),
                'space_url' => $this->spaceUrl($spaceId),
                'diagnostics' => $diagnostics,
                'assessment_label' => $assessment['label'],
                'assessment_tone' => $assessment['tone'],
                'assessment_rank' => $assessment['rank'],
                'priority_score' => $priority['priority_score'],
                'priority_label' => $priority['priority_label'],
                'priority_reason' => $priority['priority_reason'],
                'priority_is_high' => $priority['priority_score'] >= 85,
                '_priority_index' => $index,
            ]);
        }

        if ($attentionTab === 'unconfirmed_links') {
            usort($rows, static function (array $left, array $right): int {
                return ($left['assessment_rank'] <=> $right['assessment_rank'])
                    ?: ($right['priority_score'] <=> $left['priority_score'])
                    ?: ($left['_priority_index'] <=> $right['_priority_index']);
            });
        }

        return array_map(static function (array $row): array {
            unset($row['_priority_index']);
            unset($row['assessment_rank']);

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{space_id:int}>
     */
    protected function selectVisibleAiBatch(array $rows): array
    {
        $limitedRows = array_slice($rows, 0, AiReviewService::MAX_REVIEWS_PER_BATCH);

        return array_values(array_filter(array_map(
            static function (array $row): ?array {
                $spaceId = (int) ($row['space_id'] ?? 0);

                if ($spaceId <= 0) {
                    return null;
                }

                return ['space_id' => $spaceId];
            },
            $limitedRows
        )));
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array{label:string,tone:string,rank:int}
     */
    private function buildAssessmentMeta(array $diagnostics): array
    {
        if ((bool) ($diagnostics['has_stronger_candidate'] ?? false)) {
            return [
                'label' => 'Есть более сильный кандидат',
                'tone' => 'danger',
                'rank' => 10,
            ];
        }

        if ((bool) ($diagnostics['has_candidates'] ?? false)) {
            return [
                'label' => 'Текущее место не слабее',
                'tone' => 'warning',
                'rank' => 20,
            ];
        }

        return [
            'label' => 'Кандидатов не найдено',
            'tone' => 'neutral',
            'rank' => 30,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null  $aiSummary
     * @return array{priority_score:int, priority_label:string, priority_reason:string}
     */
    private function buildAiPriorityMeta(array $row, ?array $aiSummary): array
    {
        $reviewStatus = (string) ($row['review_status'] ?? '');
        $reviewStatusLabel = trim((string) ($row['review_status_label'] ?? ''));
        $decisionLabel = trim((string) ($row['decision_label'] ?? ''));
        $baseScores = [
            'conflict' => 88,
            'changed_tenant' => 78,
            'not_found' => 72,
            'unconfirmed_link' => 76,
        ];

        $priorityScore = $baseScores[$reviewStatus] ?? 60;
        $reasonParts = [];

        if ($reviewStatusLabel !== '') {
            $reasonParts[] = 'По статусу: ' . $reviewStatusLabel;
        } elseif ($decisionLabel !== '') {
            $reasonParts[] = 'По последнему решению: ' . $decisionLabel;
        } else {
            $reasonParts[] = 'По текущему состоянию места';
        }

        if ($aiSummary && filled($aiSummary['summary'] ?? null)) {
            $riskScore = (int) ($aiSummary['risk_score'] ?? 0);
            $confidence = (float) ($aiSummary['confidence'] ?? 0.0);
            $priorityScore += (int) round($riskScore * 2.5);
            $priorityScore += (int) round(max(0.0, min(1.0, $confidence)) * 8);

            $aiReason = trim((string) ($aiSummary['why_flagged'] ?? ''));
            if ($aiReason === '') {
                $aiReason = trim((string) ($aiSummary['summary'] ?? ''));
            }

            if ($aiReason !== '') {
                $reasonParts[] = 'AI отмечает: ' . $this->trimPriorityText($aiReason, 110);
            }
        } else {
            $reasonParts[] = 'AI-сводка недоступна, приоритет рассчитан только по статусу.';
        }

        $priorityScore = max(0, min(100, $priorityScore));

        return [
            'priority_score' => $priorityScore,
            'priority_label' => $this->priorityLabelForScore($priorityScore),
            'priority_reason' => $this->trimPriorityText(implode(' ', $reasonParts), 160),
        ];
    }

    private function priorityLabelForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Проверить в первую очередь',
            $score >= 65 => 'Повышенный приоритет',
            default => 'Обычный приоритет',
        };
    }

    private function trimPriorityText(string $text, int $limit = 110): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        return Str::limit($text, $limit);
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        return filled($value) ? (int) $value : null;
    }

    protected function marketId(): ?int
    {
        return static::selectedMarketIdFromSession()
            ?? Filament::auth()->user()?->market_id;
    }

    private function mapUrl(int $spaceId): string
    {
        return route('filament.admin.market-map', [
            'mode' => 'review',
            'market_space_id' => $spaceId,
            'return_url' => request()->fullUrl(),
        ]);
    }

    private function spaceUrl(int $spaceId): string
    {
        return MarketSpaceResource::getUrl('edit', ['record' => $spaceId]);
    }
}
