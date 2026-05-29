@php
    use App\Domain\Operations\SpaceReviewDecision;
    use App\Services\MarketMap\MapReviewResultsService;
    use Filament\Facades\Filament;

    $activeReviewResultsTab = in_array(request()->query('tab', 'review'), ['review', 'unconfirmed_links', 'data_quality', 'applied'], true)
        ? request()->query('tab', 'review')
        : 'review';

    $historicalGroupActions = [];

    if ($activeReviewResultsTab === 'review') {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $selectedMarketId = session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id')
            ?? Filament::auth()->user()?->market_id;

        if (filled($selectedMarketId)) {
            $spaceLabel = static function (array $row): string {
                $number = trim((string) ($row['number'] ?? ''));
                $displayName = trim((string) ($row['display_name'] ?? ''));
                $spaceId = (int) ($row['space_id'] ?? 0);

                if ($number !== '' && $displayName !== '' && $number !== $displayName) {
                    return $number . ' / ' . $displayName;
                }

                if ($number !== '') {
                    return $number;
                }

                if ($displayName !== '') {
                    return $displayName;
                }

                return '#' . $spaceId;
            };

            foreach (app(MapReviewResultsService::class)->needsAttention((int) $selectedMarketId, 50) as $row) {
                $diagnostics = is_array($row['diagnostics'] ?? null) ? $row['diagnostics'] : [];
                $reviewCase = is_array($diagnostics['review_case'] ?? null) ? $diagnostics['review_case'] : [];
                $candidateSpaces = is_array($diagnostics['candidate_spaces'] ?? null) ? $diagnostics['candidate_spaces'] : [];
                $caseType = (string) ($reviewCase['case_type'] ?? '');
                $recommendedAction = (string) ($reviewCase['recommended_action'] ?? '');
                $isHistoricalCase = $caseType === 'historical_group_structure'
                    && $recommendedAction === 'close_as_historical_composed_space';
                $isManualDuplicateCase = $caseType === 'duplicate_identity'
                    && $recommendedAction === 'resolve_duplicate'
                    && $candidateSpaces === [];

                if (! $isHistoricalCase && ! $isManualDuplicateCase) {
                    continue;
                }

                $spaceId = (int) ($row['space_id'] ?? 0);

                if ($spaceId <= 0) {
                    continue;
                }

                $historicalGroupActions[] = [
                    'space_id' => $spaceId,
                    'label' => $spaceLabel($row),
                    'reason' => trim((string) ($row['reason'] ?? '')),
                    'case_explanation' => $isHistoricalCase
                        ? trim((string) ($reviewCase['case_explanation'] ?? ''))
                        : 'Если это не дубль, а историческая составная карточка, закройте её без переноса связей: текущие части живут отдельно, финансовая история остаётся на этой карточке.',
                    'default_reason' => 'Историческое составное место: текущие части живут отдельно, финансовая история остаётся на этой карточке. Закрыто без переноса связей.',
                ];
            }
        }
    }
@endphp

@if ($historicalGroupActions !== [])
    <script>
        (() => {
            const historicalGroupActions = @json($historicalGroupActions);
            const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            const actionBySpace = new Map(
                Array.isArray(historicalGroupActions)
                    ? historicalGroupActions.map((action) => [Number(action.space_id || 0), action])
                    : []
            );

            const humanHistoricalError = (message) => {
                const text = String(message || '').trim();

                if (text.includes('Для этого решения нужен комментарий')) {
                    return 'Для закрытия как исторического составного места нужен комментарий.';
                }

                return text || 'Не удалось закрыть карточку как историческое составное место.';
            };

            const applyHistoricalGroupAction = async (action, button) => {
                const currentSpaceId = Number(action?.space_id || 0);

                if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0) {
                    window.alert('Не удалось определить текущую карточку ревизии.');
                    return;
                }

                const defaultReason = String(action?.reason || action?.default_reason || '').trim();
                const reason = window.prompt(
                    'Комментарий к закрытию исторического составного места',
                    defaultReason || 'Историческое составное место: текущие части живут отдельно, финансовая история остаётся на этой карточке. Закрыто без переноса связей.'
                );

                if (reason === null) {
                    return;
                }

                const cleanReason = String(reason || '').trim();

                if (!cleanReason) {
                    window.alert('Для закрытия нужен комментарий.');
                    return;
                }

                if (button instanceof HTMLButtonElement) {
                    button.disabled = true;
                    button.textContent = 'Закрываем...';
                }

                try {
                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: @json(SpaceReviewDecision::HISTORICAL_COMPOSED_SPACE_REVIEWED),
                            market_space_id: currentSpaceId,
                            reason: cleanReason,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        window.alert(humanHistoricalError(data?.message));

                        if (button instanceof HTMLButtonElement) {
                            button.disabled = false;
                            button.textContent = 'Закрыть как историческое составное место';
                        }

                        return;
                    }

                    window.location.reload();
                } catch (error) {
                    window.alert(humanHistoricalError(error?.message || error));

                    if (button instanceof HTMLButtonElement) {
                        button.disabled = false;
                        button.textContent = 'Закрыть как историческое составное место';
                    }
                }
            };

            const enhanceHistoricalGroupActions = () => {
                if (actionBySpace.size === 0) {
                    return;
                }

                historicalGroupActions.forEach((action) => {
                    const spaceId = Number(action?.space_id || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        return;
                    }

                    const launcher = document.querySelector(`[data-mrr-quick-review-launcher][data-mrr-space-id="${spaceId}"]`);
                    const card = launcher instanceof HTMLElement
                        ? launcher.closest('.mrr-needs-card')
                        : null;
                    const actions = card instanceof HTMLElement
                        ? card.querySelector('.mrr-card-actions')
                        : null;

                    if (!(actions instanceof HTMLElement) || actions.querySelector(`[data-mrr-historical-group-open][data-mrr-space-id="${spaceId}"]`)) {
                        return;
                    }

                    let group = actions.querySelector('.mrr-card-actions__group--primary');

                    if (!(group instanceof HTMLElement)) {
                        group = document.createElement('div');
                        group.className = 'mrr-card-actions__group mrr-card-actions__group--primary';

                        const groupLabel = document.createElement('div');
                        groupLabel.className = 'mrr-card-actions__label';
                        groupLabel.textContent = 'Решение';

                        const row = document.createElement('div');
                        row.className = 'mrr-card-actions__row';

                        group.append(groupLabel, row);
                        actions.prepend(group);
                    }

                    let row = group.querySelector('.mrr-card-actions__row');

                    if (!(row instanceof HTMLElement)) {
                        row = document.createElement('div');
                        row.className = 'mrr-card-actions__row';
                        group.appendChild(row);
                    }

                    const note = document.createElement('div');
                    note.className = 'mrr-quick-review__hint';
                    note.textContent = action.case_explanation || 'Карточка будет закрыта без переноса связей, группировки и финансовой истории.';

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'mrr-link mrr-link--button mrr-link--primary';
                    button.dataset.mrrHistoricalGroupOpen = '';
                    button.dataset.mrrSpaceId = String(spaceId);
                    button.textContent = 'Закрыть как историческое составное место';
                    button.addEventListener('click', (event) => {
                        event.preventDefault();
                        applyHistoricalGroupAction(action, button);
                    });

                    row.prepend(button);
                    group.appendChild(note);
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enhanceHistoricalGroupActions, { once: true });
            } else {
                enhanceHistoricalGroupActions();
            }
        })();
    </script>
@endif
