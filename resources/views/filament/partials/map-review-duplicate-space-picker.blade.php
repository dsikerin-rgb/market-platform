<style>
    .mrr-duplicate-space-picker {
        display: grid;
        gap: 0.65rem;
    }

    .mrr-duplicate-space-picker__search {
        display: grid;
        gap: 0.35rem;
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

<script>
    (() => {
        const endpoint = @json(route('filament.admin.map-review-results.duplicate-space-search'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

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

        const enhance = () => {
            const modal = document.getElementById('mrrManualDuplicateModal');
            const canonicalInput = document.getElementById('mrrManualDuplicateCanonicalId');
            const saveButton = modal?.querySelector('[data-mrr-manual-duplicate-save]');
            const errorTarget = document.getElementById('mrrManualDuplicateError');

            if (!(modal instanceof HTMLElement) || !(canonicalInput instanceof HTMLInputElement) || !(saveButton instanceof HTMLButtonElement)) {
                return;
            }

            if (modal.dataset.mrrDuplicateSearchEnhanced === '1') {
                return;
            }

            modal.dataset.mrrDuplicateSearchEnhanced = '1';

            const field = canonicalInput.closest('.mrr-clarify-modal__field');
            const label = field?.querySelector('label');
            const hint = field?.querySelector('.mrr-quick-review__hint');

            canonicalInput.type = 'hidden';
            canonicalInput.removeAttribute('placeholder');
            canonicalInput.removeAttribute('min');
            canonicalInput.removeAttribute('step');
            canonicalInput.removeAttribute('inputmode');

            if (label instanceof HTMLElement) {
                label.textContent = 'Найти основное место';
            }

            if (hint instanceof HTMLElement) {
                hint.textContent = 'Введите номер, название, код, арендатора или ID. ID будет подставлен автоматически после выбора карточки.';
            }

            const picker = document.createElement('div');
            picker.className = 'mrr-duplicate-space-picker';
            picker.innerHTML = `
                <div class="mrr-duplicate-space-picker__search">
                    <input
                        id="mrrDuplicateSpaceSearch"
                        class="mrr-clarify-modal__input"
                        type="search"
                        autocomplete="off"
                        placeholder="Например: ОС8, Марянина, П60у/1 или 169"
                    >
                </div>
                <div class="mrr-duplicate-space-picker__selected" id="mrrDuplicateSpaceSelected" hidden>
                    <div class="mrr-duplicate-space-picker__selected-title"></div>
                    <div class="mrr-duplicate-space-picker__selected-meta"></div>
                </div>
                <div class="mrr-duplicate-space-picker__results" id="mrrDuplicateSpaceResults">
                    <div class="mrr-duplicate-space-picker__empty">Начните вводить номер, название или арендатора основного места.</div>
                </div>
            `;

            canonicalInput.insertAdjacentElement('afterend', picker);

            const searchInput = picker.querySelector('#mrrDuplicateSpaceSearch');
            const resultsTarget = picker.querySelector('#mrrDuplicateSpaceResults');
            const selectedTarget = picker.querySelector('#mrrDuplicateSpaceSelected');
            const selectedTitle = selectedTarget?.querySelector('.mrr-duplicate-space-picker__selected-title');
            const selectedMeta = selectedTarget?.querySelector('.mrr-duplicate-space-picker__selected-meta');
            let searchTimer = null;
            let activeController = null;

            const setError = (message) => {
                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = message || '';
                }
            };

            const setSelected = (space) => {
                canonicalInput.value = String(Number(space?.id || 0));

                if (selectedTarget instanceof HTMLElement && selectedTitle instanceof HTMLElement && selectedMeta instanceof HTMLElement) {
                    selectedTitle.textContent = `Выбрано основное место: ${formatSpaceTitle(space)}`;
                    selectedMeta.textContent = formatSpaceMeta(space);
                    selectedTarget.hidden = false;
                }

                saveButton.removeAttribute('disabled');
                setError('');
            };

            const resetPicker = () => {
                canonicalInput.value = '';
                saveButton.setAttribute('disabled', 'disabled');
                setError('');

                if (searchInput instanceof HTMLInputElement) {
                    searchInput.value = '';
                }

                if (selectedTarget instanceof HTMLElement) {
                    selectedTarget.hidden = true;
                }

                if (resultsTarget instanceof HTMLElement) {
                    resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Начните вводить номер, название или арендатора основного места.</div>';
                }
            };

            const renderResults = (items) => {
                if (!(resultsTarget instanceof HTMLElement)) {
                    return;
                }

                if (!Array.isArray(items) || items.length === 0) {
                    resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Подходящие места не найдены. Уточните запрос.</div>';
                    return;
                }

                resultsTarget.replaceChildren();

                items.forEach((space) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'mrr-duplicate-space-picker__option';
                    button.dataset.mrrDuplicateSpaceSelect = String(Number(space?.id || 0));
                    button.innerHTML = `
                        <div class="mrr-duplicate-space-picker__option-title"></div>
                        <div class="mrr-duplicate-space-picker__option-meta"></div>
                    `;

                    const title = button.querySelector('.mrr-duplicate-space-picker__option-title');
                    const meta = button.querySelector('.mrr-duplicate-space-picker__option-meta');

                    if (title instanceof HTMLElement) {
                        title.textContent = formatSpaceTitle(space);
                    }

                    if (meta instanceof HTMLElement) {
                        meta.textContent = formatSpaceMeta(space);
                    }

                    button.addEventListener('click', () => {
                        setSelected(space);
                    });

                    resultsTarget.appendChild(button);
                });
            };

            const runSearch = async () => {
                if (!(searchInput instanceof HTMLInputElement) || !(resultsTarget instanceof HTMLElement)) {
                    return;
                }

                const query = searchInput.value.trim();
                canonicalInput.value = '';
                saveButton.setAttribute('disabled', 'disabled');

                if (selectedTarget instanceof HTMLElement) {
                    selectedTarget.hidden = true;
                }

                if (query.length < 2) {
                    resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Введите минимум 2 символа для поиска.</div>';
                    return;
                }

                if (activeController) {
                    activeController.abort();
                }

                activeController = new AbortController();
                resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Ищем подходящие места...</div>';

                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '10');
                url.searchParams.set('current_space_id', String(modal.dataset.mrrCurrentDuplicateSpaceId || '0'));

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        signal: activeController.signal,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Не удалось выполнить поиск. Попробуйте ещё раз.</div>';
                        return;
                    }

                    renderResults(data.items || []);
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }

                    resultsTarget.innerHTML = '<div class="mrr-duplicate-space-picker__empty">Не удалось выполнить поиск. Попробуйте ещё раз.</div>';
                }
            };

            if (searchInput instanceof HTMLInputElement) {
                searchInput.addEventListener('input', () => {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(runSearch, 250);
                });
            }

            document.addEventListener('click', (event) => {
                const openButton = event.target instanceof Element
                    ? event.target.closest('[data-mrr-manual-duplicate-open]')
                    : null;

                if (openButton instanceof HTMLElement) {
                    modal.dataset.mrrCurrentDuplicateSpaceId = String(openButton.dataset.mrrSpaceId || '0');
                    window.setTimeout(() => {
                        resetPicker();
                        searchInput?.focus();
                    }, 0);
                }

                if (event.target instanceof Element && event.target.hasAttribute('data-mrr-manual-duplicate-close')) {
                    window.setTimeout(resetPicker, 0);
                }
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhance, { once: true });
        } else {
            enhance();
        }
    })();
</script>
