<style>
    .mrr-retire-canonical-picker {
        display: grid;
        gap: 0.55rem;
    }

    .mrr-retire-canonical-picker__results {
        display: grid;
        gap: 0.5rem;
        max-height: 15rem;
        overflow: auto;
    }

    .mrr-retire-canonical-picker__empty,
    .mrr-retire-canonical-picker__selected {
        border-radius: 0.85rem;
        border: 1px solid rgba(148, 163, 184, 0.28);
        padding: 0.7rem 0.8rem;
        font-size: 0.82rem;
        line-height: 1.45;
        color: #64748b;
    }

    .dark .mrr-retire-canonical-picker__empty,
    .dark .mrr-retire-canonical-picker__selected {
        border-color: rgba(148, 163, 184, 0.22);
        color: #cbd5e1;
    }

    .mrr-retire-canonical-picker__option {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.26);
        border-radius: 0.95rem;
        background: rgba(255, 255, 255, 0.92);
        padding: 0.72rem 0.82rem;
        text-align: left;
        transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
    }

    .mrr-retire-canonical-picker__option:hover,
    .mrr-retire-canonical-picker__option:focus-visible {
        border-color: rgba(37, 99, 235, 0.46);
        box-shadow: 0 14px 28px rgba(37, 99, 235, 0.12);
        outline: none;
        transform: translateY(-1px);
    }

    .dark .mrr-retire-canonical-picker__option {
        border-color: rgba(148, 163, 184, 0.22);
        background: rgba(15, 23, 42, 0.72);
    }

    .mrr-retire-canonical-picker__option-title,
    .mrr-retire-canonical-picker__selected-title {
        font-size: 0.9rem;
        font-weight: 800;
        color: #0f172a;
    }

    .dark .mrr-retire-canonical-picker__option-title,
    .dark .mrr-retire-canonical-picker__selected-title {
        color: #f8fafc;
    }

    .mrr-retire-canonical-picker__option-meta,
    .mrr-retire-canonical-picker__selected-meta {
        margin-top: 0.25rem;
        font-size: 0.78rem;
        line-height: 1.35;
        color: #64748b;
    }

    .dark .mrr-retire-canonical-picker__option-meta,
    .dark .mrr-retire-canonical-picker__selected-meta {
        color: #cbd5e1;
    }
</style>

<script>
    (() => {
        const duplicateSearchUrl = @json(route('filament.admin.map-review-results.duplicate-space-search'));
        const modal = document.getElementById('mrrMergeRetireModal');
        const canonicalInput = document.getElementById('mrrMergeRetireCanonicalId');
        const errorTarget = document.getElementById('mrrMergeRetireError');
        const saveButton = modal?.querySelector('[data-mrr-merge-retire-save]');

        if (!(modal instanceof HTMLElement) || !(canonicalInput instanceof HTMLInputElement)) {
            return;
        }

        let lastOpenButton = null;
        let searchTimer = null;
        let activeSearchController = null;
        let lastPreparedSpaceId = 0;

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

        const createPickerMessage = (message) => {
            const node = document.createElement('div');
            node.className = 'mrr-retire-canonical-picker__empty';
            node.textContent = message;

            return node;
        };

        const installPicker = () => {
            if (document.getElementById('mrrMergeRetireCanonicalSearch')) {
                return;
            }

            const field = canonicalInput.closest('.mrr-clarify-modal__field');

            if (!(field instanceof HTMLElement)) {
                return;
            }

            const label = field.querySelector('label');
            if (label instanceof HTMLElement) {
                label.textContent = 'Найти основное место';
            }

            canonicalInput.type = 'hidden';
            canonicalInput.setAttribute('aria-hidden', 'true');
            canonicalInput.tabIndex = -1;

            const searchInput = document.createElement('input');
            searchInput.id = 'mrrMergeRetireCanonicalSearch';
            searchInput.className = 'mrr-clarify-modal__input';
            searchInput.type = 'search';
            searchInput.autocomplete = 'off';
            searchInput.placeholder = 'Например: П60у/1, Динисламова или 42';

            const hint = document.createElement('div');
            hint.className = 'mrr-quick-review__hint';
            hint.textContent = 'Выберите основное место из списка. Старое место станет архивным, начисления останутся на нём как история.';

            const picker = document.createElement('div');
            picker.className = 'mrr-retire-canonical-picker';

            const selected = document.createElement('div');
            selected.id = 'mrrMergeRetireCanonicalSelected';
            selected.className = 'mrr-retire-canonical-picker__selected';
            selected.hidden = true;

            const selectedTitle = document.createElement('div');
            selectedTitle.className = 'mrr-retire-canonical-picker__selected-title';

            const selectedMeta = document.createElement('div');
            selectedMeta.className = 'mrr-retire-canonical-picker__selected-meta';

            selected.append(selectedTitle, selectedMeta);

            const results = document.createElement('div');
            results.id = 'mrrMergeRetireCanonicalResults';
            results.className = 'mrr-retire-canonical-picker__results';
            results.appendChild(createPickerMessage('Начните вводить номер, название или арендатора основного места.'));

            picker.append(selected, results);
            field.append(searchInput, hint, picker);

            const setMessage = (message) => {
                results.replaceChildren(createPickerMessage(message));
            };

            const selectSpace = (space) => {
                const selectedId = Number(space?.id || 0);

                if (!Number.isFinite(selectedId) || selectedId <= 0) {
                    return;
                }

                canonicalInput.value = String(selectedId);
                searchInput.value = formatSpaceTitle(space);
                selectedTitle.textContent = `Выбрано основное место: ${formatSpaceTitle(space)}`;
                selectedMeta.textContent = formatSpaceMeta(space);
                selected.hidden = false;

                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.removeAttribute('disabled');
                }

                if (errorTarget instanceof HTMLElement) {
                    errorTarget.textContent = '';
                }
            };

            const renderResults = (items) => {
                if (!Array.isArray(items) || items.length === 0) {
                    setMessage('Подходящие места не найдены. Уточните запрос.');
                    return;
                }

                results.replaceChildren();

                items.forEach((space) => {
                    const button = document.createElement('button');
                    const title = document.createElement('div');
                    const meta = document.createElement('div');

                    button.type = 'button';
                    button.className = 'mrr-retire-canonical-picker__option';
                    title.className = 'mrr-retire-canonical-picker__option-title';
                    meta.className = 'mrr-retire-canonical-picker__option-meta';
                    title.textContent = formatSpaceTitle(space);
                    meta.textContent = formatSpaceMeta(space);
                    button.append(title, meta);
                    button.addEventListener('click', () => selectSpace(space));
                    results.appendChild(button);
                });
            };

            const runSearch = async () => {
                const query = searchInput.value.trim();

                canonicalInput.value = '';
                selected.hidden = true;

                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.setAttribute('disabled', 'disabled');
                }

                if (query.length < 2) {
                    setMessage('Введите минимум 2 символа для поиска.');
                    return;
                }

                if (activeSearchController) {
                    activeSearchController.abort();
                }

                activeSearchController = new AbortController();
                setMessage('Ищем подходящие места...');

                const currentSpaceId = Number(lastOpenButton?.dataset?.mrrSpaceId || 0);
                const url = new URL(duplicateSearchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '10');
                url.searchParams.set('current_space_id', String(currentSpaceId || '0'));

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                        },
                        signal: activeSearchController.signal,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        setMessage('Не удалось выполнить поиск. Попробуйте ещё раз.');
                        return;
                    }

                    renderResults(data.items || []);
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        return;
                    }

                    setMessage('Не удалось выполнить поиск. Попробуйте ещё раз.');
                }
            };

            searchInput.addEventListener('input', () => {
                canonicalInput.value = '';
                selected.hidden = true;

                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.setAttribute('disabled', 'disabled');
                }

                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(runSearch, 250);
            });

            searchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && Number(canonicalInput.value || 0) <= 0) {
                    event.preventDefault();
                }
            });
        };

        const prepareOpenedModal = () => {
            installPicker();

            if (!modal.classList.contains('is-open') || !(lastOpenButton instanceof HTMLElement)) {
                return;
            }

            const searchInput = document.getElementById('mrrMergeRetireCanonicalSearch');
            const results = document.getElementById('mrrMergeRetireCanonicalResults');
            const selected = document.getElementById('mrrMergeRetireCanonicalSelected');
            const currentSpaceId = Number(lastOpenButton.dataset.mrrSpaceId || 0);

            if (!(searchInput instanceof HTMLInputElement) || !(results instanceof HTMLElement)) {
                return;
            }

            if (currentSpaceId > 0 && currentSpaceId === lastPreparedSpaceId) {
                return;
            }

            lastPreparedSpaceId = currentSpaceId;
            canonicalInput.value = '';
            searchInput.value = String(lastOpenButton.dataset.mrrSpaceLabel || '').trim();

            if (selected instanceof HTMLElement) {
                selected.hidden = true;
            }

            if (saveButton instanceof HTMLButtonElement) {
                saveButton.setAttribute('disabled', 'disabled');
            }

            results.replaceChildren(createPickerMessage('Ищем похожее основное место...'));

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => {
                const event = new Event('input', { bubbles: true });
                searchInput.dispatchEvent(event);
                searchInput.focus();
            }, 120);
        };

        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('[data-mrr-merge-retire-open]')
                : null;

            if (button instanceof HTMLElement) {
                lastOpenButton = button;
                lastPreparedSpaceId = 0;
                window.setTimeout(prepareOpenedModal, 0);
                window.setTimeout(prepareOpenedModal, 80);
            }
        }, true);

        const observer = new MutationObserver(() => prepareOpenedModal());
        observer.observe(modal, {
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-hidden'],
        });

        installPicker();
    })();
</script>
