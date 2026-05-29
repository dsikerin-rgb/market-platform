@php
    use App\Filament\Resources\MarketSpaceResource;
    use App\Filament\Widgets\MapReviewDataQualitySignalsWidget;
    use App\Services\MarketMap\MapReviewResultsService;
    use Filament\Facades\Filament;
    use Livewire\Livewire;

    $activeReviewResultsTab = in_array(request()->query('tab', 'review'), ['review', 'unconfirmed_links', 'data_quality', 'applied'], true)
        ? request()->query('tab', 'review')
        : 'review';

    $manualDuplicateActions = [];

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

                if (($reviewCase['case_type'] ?? null) !== 'duplicate_identity') {
                    continue;
                }

                if (($reviewCase['recommended_action'] ?? null) !== 'resolve_duplicate') {
                    continue;
                }

                if ($candidateSpaces !== []) {
                    continue;
                }

                $spaceId = (int) ($row['space_id'] ?? 0);

                if ($spaceId <= 0) {
                    continue;
                }

                $manualDuplicateActions[] = [
                    'space_id' => $spaceId,
                    'label' => $spaceLabel($row),
                    'reason' => trim((string) ($row['reason'] ?? '')),
                    'case_explanation' => trim((string) ($reviewCase['case_explanation'] ?? '')),
                    'space_url' => MarketSpaceResource::getUrl('edit', ['record' => $spaceId]),
                    'map_url' => route('filament.admin.market-map', [
                        'mode' => 'review',
                        'market_space_id' => $spaceId,
                        'return_url' => request()->fullUrl(),
                    ]),
                ];
            }
        }
    }
@endphp

@if ($activeReviewResultsTab === 'data_quality')
    <div id="mrrDataQualitySignalsSource" hidden>
        {!! Livewire::mount(MapReviewDataQualitySignalsWidget::class) !!}
    </div>

    @include('filament.partials.tenant-merge-preflight-exact-links')
    @include('filament.partials.tenant-merge-preflight-friendly-actions')
    @include('filament.partials.tenant-merge-confirmation-wording')
@endif

@if (in_array($activeReviewResultsTab, ['review', 'unconfirmed_links'], true))
    @include('filament.partials.map-review-card-tenant-context')
@endif

@if ($manualDuplicateActions !== [])
    <style>
        .mrr-manual-duplicate__summary {
            border-radius: 1rem;
            border: 1px solid rgba(37, 99, 235, 0.16);
            background: rgba(239, 246, 255, 0.94);
            padding: 0.8rem 0.9rem;
        }

        .dark .mrr-manual-duplicate__summary {
            border-color: rgba(96, 165, 250, 0.22);
            background: rgba(15, 23, 42, 0.56);
        }

        .mrr-manual-duplicate__summary-title {
            font-size: 0.86rem;
            font-weight: 800;
            color: #0f172a;
        }

        .dark .mrr-manual-duplicate__summary-title {
            color: #f8fafc;
        }

        .mrr-manual-duplicate__summary-copy {
            margin-top: 0.3rem;
            font-size: 0.82rem;
            line-height: 1.45;
            color: #475569;
        }

        .dark .mrr-manual-duplicate__summary-copy {
            color: #cbd5e1;
        }
    </style>

    <div class="mrr-clarify-modal" id="mrrManualDuplicateModal" data-mrr-manual-duplicate-modal hidden aria-hidden="true">
        <div class="mrr-clarify-modal__backdrop" data-mrr-manual-duplicate-close></div>
        <div class="mrr-clarify-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mrrManualDuplicateTitle">
            <button type="button" class="mrr-clarify-modal__close" data-mrr-manual-duplicate-close aria-label="Закрыть">×</button>
            <div>
                <div class="mrr-clarify-modal__eyebrow">Разбор дубля</div>
                <h3 class="mrr-clarify-modal__title" id="mrrManualDuplicateTitle">Выберите основное место</h3>
                <p class="mrr-clarify-modal__description" id="mrrManualDuplicateDescription">
                    Автоматический кандидат не найден. Укажите ID основного места, чтобы закрыть конфликт из этой карточки ревизии.
                </p>
            </div>

            <div class="mrr-manual-duplicate__summary">
                <div class="mrr-manual-duplicate__summary-title" id="mrrManualDuplicateSpaceLabel">—</div>
                <div class="mrr-manual-duplicate__summary-copy" id="mrrManualDuplicateCaseCopy">
                    Договоры, начисления и долги не переносятся автоматически. Backend повторно проверит выбранную пару мест.
                </div>
            </div>

            <div class="mrr-clarify-modal__field">
                <label class="mrr-clarify-modal__label" for="mrrManualDuplicateCanonicalId">ID основного места</label>
                <input
                    id="mrrManualDuplicateCanonicalId"
                    class="mrr-clarify-modal__input"
                    type="number"
                    min="1"
                    step="1"
                    inputmode="numeric"
                    placeholder="Например: 169"
                >
                <div class="mrr-quick-review__hint">Это место останется основным. Текущая карточка будет обработана как дубль.</div>
            </div>

            <div class="mrr-clarify-modal__field">
                <label class="mrr-clarify-modal__label" for="mrrManualDuplicateReason">Комментарий</label>
                <textarea
                    id="mrrManualDuplicateReason"
                    class="mrr-clarify-modal__input mrr-quick-review__field"
                    rows="3"
                    placeholder="Например: подтверждён дубль, основным оставить место #169"
                ></textarea>
            </div>

            <div class="mrr-clarify-modal__error" id="mrrManualDuplicateError" aria-live="polite"></div>

            <div class="mrr-clarify-modal__actions">
                <button type="button" class="mrr-clarify-modal__button" data-mrr-manual-duplicate-close>Отмена</button>
                <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-manual-duplicate-save>Применить разбор дубля</button>
            </div>
        </div>
    </div>
@endif

<script>
    (() => {
        const activeTab = @json($activeReviewResultsTab);
        const tabItems = [
            { key: 'review', label: 'Ревизионные решения', url: @json(request()->fullUrlWithQuery(['tab' => 'review'])) },
            { key: 'unconfirmed_links', label: 'Финансовая связь не подтверждена', url: @json(request()->fullUrlWithQuery(['tab' => 'unconfirmed_links'])) },
            { key: 'data_quality', label: 'Дубли арендаторов', url: @json(request()->fullUrlWithQuery(['tab' => 'data_quality'])) },
            { key: 'applied', label: 'Применено', url: @json(request()->fullUrlWithQuery(['tab' => 'applied'])) },
        ];

        const activeTabCopy = {
            review: {
                title: 'Нужно уточнить',
                copy: 'Места со спорным или незавершённым ревизионным результатом.',
            },
            unconfirmed_links: {
                title: 'Нужно уточнить',
                copy: 'Места на карте, где статус взят по арендатору, но финансовая связь с местом не подтверждена.',
            },
            data_quality: {
                title: 'Возможные дубли арендаторов',
                copy: 'Пары арендаторов, которые похожи друг на друга и могут быть заведены дважды. Проверьте пару и объедините карточки, если это один арендатор.',
            },
            applied: {
                title: 'Применено',
                copy: 'Безопасные изменения, уже прошедшие через SPACE_REVIEW.',
            },
        };

        const enhanceTabs = () => {
            const toggle = document.querySelector('.mrr-sort-toggle');
            const panels = Array.from(document.querySelectorAll('.aw-column > .aw-panel'));
            const needsPanel = panels[0] || null;
            const appliedPanel = panels[1] || null;

            if (!(toggle instanceof HTMLElement) || !(needsPanel instanceof HTMLElement)) {
                return;
            }

            const needsTitle = needsPanel.querySelector('.aw-panel-title');
            const needsCopy = needsPanel.querySelector('.aw-panel-copy');
            const needsBody = needsPanel.querySelector('.aw-panel-body');

            if (!(needsBody instanceof HTMLElement)) {
                return;
            }

            toggle.replaceChildren();

            tabItems.forEach((item) => {
                const link = document.createElement('a');
                link.className = 'mrr-sort-toggle__link';
                link.href = item.url;
                link.textContent = item.label;

                if (item.key === activeTab) {
                    link.classList.add('is-active');
                }

                toggle.appendChild(link);
            });

            const copy = activeTabCopy[activeTab] || activeTabCopy.review;

            if (needsTitle instanceof HTMLElement) {
                needsTitle.textContent = copy.title;
            }

            if (needsCopy instanceof HTMLElement) {
                needsCopy.textContent = copy.copy;
            }

            if (appliedPanel instanceof HTMLElement) {
                appliedPanel.hidden = true;
            }

            if (activeTab === 'data_quality') {
                const source = document.getElementById('mrrDataQualitySignalsSource');
                const dataQualitySection = source instanceof HTMLElement
                    ? source.querySelector('section') || source.firstElementChild
                    : null;

                if (dataQualitySection instanceof Element) {
                    dataQualitySection.querySelector('.aw-panel-head')?.remove();
                    needsBody.replaceChildren(dataQualitySection);
                }

                return;
            }

            if (activeTab === 'applied') {
                const appliedBody = appliedPanel instanceof HTMLElement
                    ? appliedPanel.querySelector('.aw-panel-body')
                    : null;

                if (appliedBody instanceof HTMLElement) {
                    needsBody.replaceChildren(...Array.from(appliedBody.childNodes));
                }

                return;
            }
        };

        const manualDuplicateActions = @json($manualDuplicateActions);

        const humanManualDuplicateError = (message) => {
            const text = String(message || '').trim();

            if (text.includes('Duplicate review candidate space is required')) {
                return 'Укажите ID основного места. Оно не должно совпадать с текущим местом.';
            }

            if (text.includes('Duplicate review candidate space was not found')) {
                return 'Основное место не найдено в текущем рынке.';
            }

            if (text.includes('Cannot resolve duplicate: no safe transfer links found')) {
                return 'Автоматически разобрать дубль нельзя: у места есть финансовая история, но нет безопасных связей для переноса. Зафиксируйте ручной сценарий или отправьте на ручную проверку.';
            }

            return text || 'Не удалось применить разбор дубля.';
        };

        const enhanceManualDuplicateActions = () => {
            if (!Array.isArray(manualDuplicateActions) || manualDuplicateActions.length === 0) {
                return;
            }

            const modal = document.getElementById('mrrManualDuplicateModal');
            const labelTarget = document.getElementById('mrrManualDuplicateSpaceLabel');
            const copyTarget = document.getElementById('mrrManualDuplicateCaseCopy');
            const canonicalInput = document.getElementById('mrrManualDuplicateCanonicalId');
            const reasonInput = document.getElementById('mrrManualDuplicateReason');
            const errorTarget = document.getElementById('mrrManualDuplicateError');
            const saveButton = modal?.querySelector('[data-mrr-manual-duplicate-save]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
            let activeAction = null;

            if (!(modal instanceof HTMLElement) || !(canonicalInput instanceof HTMLInputElement) || !(reasonInput instanceof HTMLTextAreaElement) || !(saveButton instanceof HTMLButtonElement)) {
                return;
            }

            const actionBySpace = new Map(manualDuplicateActions.map((action) => [Number(action.space_id || 0), action]));

            const openModal = (action) => {
                activeAction = action;
                canonicalInput.value = '';
                reasonInput.value = String(action.reason || '').trim();

                if (labelTarget instanceof HTMLElement) {
                    labelTarget.textContent = action.label
                        ? `Текущее место: #${action.space_id} · ${action.label}`
                        : `Текущее место: #${action.space_id}`;
                }

                if (copyTarget instanceof HTMLElement) {
                    copyTarget.textContent = action.case_explanation || 'Выберите основное место дубля. Backend повторно проверит выбранную пару мест.';
                }

                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = '';
                }

                saveButton.removeAttribute('disabled');
                saveButton.textContent = 'Применить разбор дубля';
                modal.hidden = false;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                window.setTimeout(() => canonicalInput.focus(), 0);
            };

            const closeModal = () => {
                activeAction = null;
                modal.classList.remove('is-open');
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                canonicalInput.value = '';
                reasonInput.value = '';
                saveButton.removeAttribute('disabled');
                saveButton.textContent = 'Применить разбор дубля';

                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = '';
                }
            };

            const applyManualDuplicate = async () => {
                const currentSpaceId = Number(activeAction?.space_id || 0);
                const canonicalSpaceId = Number(canonicalInput.value || 0);
                const reason = String(reasonInput.value || '').trim();

                if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0) {
                    if (errorTarget instanceof HTMLElement) {
                        errorTarget.textContent = 'Не удалось определить текущую карточку ревизии.';
                    }
                    return;
                }

                if (!Number.isFinite(canonicalSpaceId) || canonicalSpaceId <= 0 || canonicalSpaceId === currentSpaceId) {
                    if (errorTarget instanceof HTMLElement) {
                        errorTarget.textContent = 'Укажите ID другого основного места.';
                    }
                    canonicalInput.focus();
                    return;
                }

                saveButton.setAttribute('disabled', 'disabled');
                saveButton.textContent = 'Применяем...';

                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = '';
                }

                const response = await fetch(reviewDecisionUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        decision: 'duplicate_space_needs_resolution',
                        market_space_id: currentSpaceId,
                        candidate_market_space_id: canonicalSpaceId,
                        reason: reason || 'Основное место дубля выбрано вручную на странице ревизии.',
                    }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data?.ok) {
                    saveButton.removeAttribute('disabled');
                    saveButton.textContent = 'Применить разбор дубля';

                    if (errorTarget instanceof HTMLElement) {
                        errorTarget.textContent = humanManualDuplicateError(data?.message);
                    }

                    return;
                }

                window.location.reload();
            };

            manualDuplicateActions.forEach((action) => {
                const spaceId = Number(action.space_id || 0);

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

                if (!(actions instanceof HTMLElement) || actions.querySelector(`[data-mrr-manual-duplicate-open][data-mrr-space-id="${spaceId}"]`)) {
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

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'mrr-link mrr-link--button mrr-link--primary';
                button.dataset.mrrManualDuplicateOpen = '';
                button.dataset.mrrSpaceId = String(spaceId);
                button.textContent = 'Разобрать дубль';
                row.prepend(button);
            });

            document.addEventListener('click', (event) => {
                const openButton = event.target instanceof Element
                    ? event.target.closest('[data-mrr-manual-duplicate-open]')
                    : null;

                if (openButton instanceof HTMLElement) {
                    event.preventDefault();
                    const action = actionBySpace.get(Number(openButton.dataset.mrrSpaceId || 0));

                    if (action) {
                        openModal(action);
                    }

                    return;
                }

                if (!(event.target instanceof Element)) {
                    return;
                }

                if (event.target.hasAttribute('data-mrr-manual-duplicate-close')) {
                    event.preventDefault();
                    closeModal();
                    return;
                }

                if (event.target.hasAttribute('data-mrr-manual-duplicate-save')) {
                    event.preventDefault();
                    applyManualDuplicate().catch((errorInstance) => {
                        saveButton.removeAttribute('disabled');
                        saveButton.textContent = 'Применить разбор дубля';

                        if (errorTarget instanceof HTMLElement) {
                            errorTarget.textContent = humanManualDuplicateError(errorInstance?.message || errorInstance);
                        }
                    });
                }
            });

            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    event.preventDefault();
                    closeModal();
                }
            });
        };

        const runEnhancements = () => {
            enhanceTabs();
            enhanceManualDuplicateActions();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runEnhancements, { once: true });
        } else {
            runEnhancements();
        }
    })();
</script>
