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

        .mrr-duplicate-space-picker {
            display: grid;
            gap: 0.65rem;
        }

        .mrr-duplicate-space-picker__results {
            display: grid;
            gap: 0.5rem;
            max-height: 16rem;
            overflow: auto;
        }

        .mrr-duplicate-space-picker__empty,
        .mrr-duplicate-space-picker__selected {
            border-radius: 0.85rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            padding: 0.75rem 0.85rem;
            font-size: 0.82rem;
            line-height: 1.45;
            color: #64748b;
        }

        .dark .mrr-duplicate-space-picker__empty,
        .dark .mrr-duplicate-space-picker__selected {
            border-color: rgba(148, 163, 184, 0.22);
            color: #cbd5e1;
        }

        .mrr-duplicate-space-picker__option {
            width: 100%;
            border: 1px solid rgba(148, 163, 184, 0.26);
            border-radius: 0.95rem;
            background: rgba(255, 255, 255, 0.92);
            padding: 0.75rem 0.85rem;
            text-align: left;
            transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
        }

        .mrr-duplicate-space-picker__option:hover,
        .mrr-duplicate-space-picker__option:focus-visible {
            border-color: rgba(37, 99, 235, 0.46);
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.12);
            outline: none;
            transform: translateY(-1px);
        }

        .dark .mrr-duplicate-space-picker__option {
            border-color: rgba(148, 163, 184, 0.22);
            background: rgba(15, 23, 42, 0.72);
        }

        .mrr-duplicate-space-picker__option-title,
        .mrr-duplicate-space-picker__selected-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
        }

        .dark .mrr-duplicate-space-picker__option-title,
        .dark .mrr-duplicate-space-picker__selected-title {
            color: #f8fafc;
        }

        .mrr-duplicate-space-picker__option-meta,
        .mrr-duplicate-space-picker__selected-meta {
            margin-top: 0.25rem;
            font-size: 0.78rem;
            line-height: 1.35;
            color: #64748b;
        }

        .dark .mrr-duplicate-space-picker__option-meta,
        .dark .mrr-duplicate-space-picker__selected-meta {
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
                    Автоматический кандидат не найден. Найдите и выберите основное место, чтобы закрыть конфликт из этой карточки ревизии.
                </p>
            </div>

            <div class="mrr-manual-duplicate__summary">
                <div class="mrr-manual-duplicate__summary-title" id="mrrManualDuplicateSpaceLabel">—</div>
                <div class="mrr-manual-duplicate__summary-copy" id="mrrManualDuplicateCaseCopy">
                    Договоры, начисления и долги не переносятся автоматически. Backend повторно проверит выбранную пару мест.
                </div>
            </div>

            <div class="mrr-clarify-modal__field">
                <label class="mrr-clarify-modal__label" for="mrrManualDuplicateCanonicalSearch">Найти основное место</label>
                <input
                    id="mrrManualDuplicateCanonicalSearch"
                    class="mrr-clarify-modal__input"
                    type="search"
                    autocomplete="off"
                    placeholder="Например: ОС8, Марянина, П60у/1 или 169"
                >
                <input id="mrrManualDuplicateCanonicalId" type="hidden">
                <div class="mrr-quick-review__hint">Введите номер, название, код, арендатора или ID. Это место останется основным.</div>
                <div class="mrr-duplicate-space-picker">
                    <div class="mrr-duplicate-space-picker__selected" id="mrrManualDuplicateSelectedSpace" hidden>
                        <div class="mrr-duplicate-space-picker__selected-title"></div>
                        <div class="mrr-duplicate-space-picker__selected-meta"></div>
                    </div>
                    <div class="mrr-duplicate-space-picker__results" id="mrrManualDuplicateSearchResults"></div>
                </div>
            </div>

            <div class="mrr-clarify-modal__field">
                <label class="mrr-clarify-modal__label" for="mrrManualDuplicateReason">Комментарий</label>
                <textarea
                    id="mrrManualDuplicateReason"
                    class="mrr-clarify-modal__input mrr-quick-review__field"
                    rows="3"
                    placeholder="Например: подтверждён дубль, основным оставить место ОС8 8, 9, 10"
                ></textarea>
            </div>

            <div class="mrr-clarify-modal__error" id="mrrManualDuplicateError" aria-live="polite"></div>

            <div class="mrr-clarify-modal__actions">
                <button type="button" class="mrr-clarify-modal__button" data-mrr-manual-duplicate-close>Отмена</button>
                <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-manual-duplicate-save disabled>Применить разбор дубля</button>
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
                return 'Выберите основное место из результатов поиска. Оно не должно совпадать с текущим местом.';
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
            const canonicalSearchInput = document.getElementById('mrrManualDuplicateCanonicalSearch');
            const selectedTarget = document.getElementById('mrrManualDuplicateSelectedSpace');
            const selectedTitle = selectedTarget?.querySelector('.mrr-duplicate-space-picker__selected-title');
            const selectedMeta = selectedTarget?.querySelector('.mrr-duplicate-space-picker__selected-meta');
            const searchResultsTarget = document.getElementById('mrrManualDuplicateSearchResults');
            const reasonInput = document.getElementById('mrrManualDuplicateReason');
            const errorTarget = document.getElementById('mrrManualDuplicateError');
            const saveButton = modal?.querySelector('[data-mrr-manual-duplicate-save]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
            const duplicateSearchUrl = @json(route('filament.admin.map-review-results.duplicate-space-search'));
            let activeAction = null;
            let activeSearchController = null;
            let searchTimer = null;

            if (
                !(modal instanceof HTMLElement)
                || !(canonicalInput instanceof HTMLInputElement)
                || !(canonicalSearchInput instanceof HTMLInputElement)
                || !(reasonInput instanceof HTMLTextAreaElement)
                || !(searchResultsTarget instanceof HTMLElement)
                || !(saveButton instanceof HTMLButtonElement)
            ) {
                return;
            }

            const actionBySpace = new Map(manualDuplicateActions.map((action) => [Number(action.space_id || 0), action]));

            const setError = (message) => {
                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = message || '';
                }
            };

            const createPickerMessage = (message) => {
                const node = document.createElement('div');
                node.className = 'mrr-duplicate-space-picker__empty';
                node.textContent = message;

                return node;
            };

            const setPickerMessage = (message) => {
                searchResultsTarget.replaceChildren(createPickerMessage(message));
            };

            const formatSpaceTitle = (space) => {
                const id = Number(space?.id || 0);
                const number = String(space?.number || '').trim();
                const displayName = String(space?.display_name || '').trim();

                if (number && displayName && number !== displayName) {
                    return `#${id} · ${number} · ${displayName}`;
                }

                if (number) {
                    return `#${id} · ${number}`;
                }

                if (displayName) {
                    return `#${id} · ${displayName}`;
                }

                return `#${id}`;
            };

            const formatSpaceMeta = (space) => {
                const parts = [];
                const tenantName = String(space?.tenant?.name || '').trim();
                const code = String(space?.code || '').trim();
                const status = String(space?.status || '').trim();
                const role = String(space?.space_group_role || '').trim();

                if (tenantName) {
                    parts.push(`Арендатор: ${tenantName}`);
                }

                if (code) {
                    parts.push(`Код: ${code}`);
                }

                if (status) {
                    parts.push(`Статус: ${status}`);
                }

                if (role && role !== 'none') {
                    parts.push(`Роль группы: ${role}`);
                }

                return parts.join(' · ') || 'Без арендатора и дополнительных признаков';
            };

            const resetDuplicatePicker = () => {
                canonicalInput.value = '';
                canonicalSearchInput.value = '';
                saveButton.setAttribute('disabled', 'disabled');

                if (selectedTarget instanceof HTMLElement) {
                    selectedTarget.hidden = true;
                }

                setPickerMessage('Начните вводить номер, название или арендатора основного места.');
            };

            const selectCanonicalSpace = (space) => {
                const selectedId = Number(space?.id || 0);

                if (!Number.isFinite(selectedId) || selectedId <= 0) {
                    return;
                }

                canonicalInput.value = String(selectedId);
                canonicalSearchInput.value = formatSpaceTitle(space);

                if (selectedTarget instanceof HTMLElement && selectedTitle instanceof HTMLElement && selectedMeta instanceof HTMLElement) {
                    selectedTitle.textContent = `Выбрано основное место: ${formatSpaceTitle(space)}`;
                    selectedMeta.textContent = formatSpaceMeta(space);
                    selectedTarget.hidden = false;
                }

                saveButton.removeAttribute('disabled');
                setError('');
            };

            const renderSearchResults = (items) => {
                if (!Array.isArray(items) || items.length === 0) {
                    setPickerMessage('Подходящие места не найдены. Уточните запрос.');
                    return;
                }

                searchResultsTarget.replaceChildren();

                items.forEach((space) => {
                    const button = document.createElement('button');
                    const title = document.createElement('div');
                    const meta = document.createElement('div');

                    button.type = 'button';
                    button.className = 'mrr-duplicate-space-picker__option';
                    title.className = 'mrr-duplicate-space-picker__option-title';
                    meta.className = 'mrr-duplicate-space-picker__option-meta';
                    title.textContent = formatSpaceTitle(space);
                    meta.textContent = formatSpaceMeta(space);
                    button.append(title, meta);
                    button.addEventListener('click', () => selectCanonicalSpace(space));
                    searchResultsTarget.appendChild(button);
                });
            };

            const runCanonicalSearch = async () => {
                const query = canonicalSearchInput.value.trim();

                canonicalInput.value = '';
                saveButton.setAttribute('disabled', 'disabled');

                if (selectedTarget instanceof HTMLElement) {
                    selectedTarget.hidden = true;
                }

                if (query.length < 2) {
                    setPickerMessage('Введите минимум 2 символа для поиска.');
                    return;
                }

                if (activeSearchController) {
                    activeSearchController.abort();
                }

                activeSearchController = new AbortController();
                setPickerMessage('Ищем подходящие места...');

                const url = new URL(duplicateSearchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '10');
                url.searchParams.set('current_space_id', String(activeAction?.space_id || '0'));

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        signal: activeSearchController.signal,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        setPickerMessage('Не удалось выполнить поиск. Попробуйте ещё раз.');
                        return;
                    }

                    renderSearchResults(data.items || []);
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }

                    setPickerMessage('Не удалось выполнить поиск. Попробуйте ещё раз.');
                }
            };

            canonicalSearchInput.addEventListener('input', () => {
                canonicalInput.value = '';
                saveButton.setAttribute('disabled', 'disabled');

                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(runCanonicalSearch, 250);
            });

            const openModal = (action) => {
                activeAction = action;
                resetDuplicatePicker();
                reasonInput.value = String(action.reason || '').trim();

                if (labelTarget instanceof HTMLElement) {
                    labelTarget.textContent = action.label
                        ? `Текущее место: #${action.space_id} · ${action.label}`
                        : `Текущее место: #${action.space_id}`;
                }

                if (copyTarget instanceof HTMLElement) {
                    copyTarget.textContent = action.case_explanation || 'Выберите основное место дубля. Backend повторно проверит выбранную пару мест.';
                }

                setError('');
                saveButton.textContent = 'Применить разбор дубля';
                modal.hidden = false;
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                window.setTimeout(() => canonicalSearchInput.focus(), 0);
            };

            const closeModal = () => {
                activeAction = null;
                modal.classList.remove('is-open');
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                resetDuplicatePicker();
                reasonInput.value = '';
                saveButton.textContent = 'Применить разбор дубля';
                setError('');
            };

            const applyManualDuplicate = async () => {
                const currentSpaceId = Number(activeAction?.space_id || 0);
                const canonicalSpaceId = Number(canonicalInput.value || 0);
                const reason = String(reasonInput.value || '').trim();

                if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0) {
                    setError('Не удалось определить текущую карточку ревизии.');
                    return;
                }

                if (!Number.isFinite(canonicalSpaceId) || canonicalSpaceId <= 0 || canonicalSpaceId === currentSpaceId) {
                    setError('Выберите другое основное место из результатов поиска.');
                    canonicalSearchInput.focus();
                    return;
                }

                saveButton.setAttribute('disabled', 'disabled');
                saveButton.textContent = 'Применяем...';
                setError('');

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
                    if (Number(canonicalInput.value || 0) > 0) {
                        saveButton.removeAttribute('disabled');
                    }
                    saveButton.textContent = 'Применить разбор дубля';
                    setError(humanManualDuplicateError(data?.message));
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
                        if (Number(canonicalInput.value || 0) > 0) {
                            saveButton.removeAttribute('disabled');
                        }
                        saveButton.textContent = 'Применить разбор дубля';
                        setError(humanManualDuplicateError(errorInstance?.message || errorInstance));
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
