<style>
    .mrr-manual-tenant-switch__suggestions {
        max-height: 16rem;
        overflow-y: auto;
        align-items: flex-start;
        border-radius: 0.95rem;
        border: 1px solid rgba(37, 99, 235, 0.14);
        background: rgba(248, 250, 252, 0.92);
        padding: 0.55rem;
    }

    .dark .mrr-manual-tenant-switch__suggestions {
        border-color: rgba(96, 165, 250, 0.24);
        background: rgba(15, 23, 42, 0.72);
    }

    .mrr-manual-tenant-switch__suggestion {
        max-width: 100%;
        text-align: left;
        white-space: normal;
    }

    .mrr-manual-tenant-switch__suggestion.is-selected {
        border-color: rgba(22, 163, 74, 0.28);
        background: rgba(240, 253, 244, 0.96);
        color: #166534;
        box-shadow: inset 0 0 0 1px currentColor;
    }

    .dark .mrr-manual-tenant-switch__suggestion.is-selected {
        border-color: rgba(74, 222, 128, 0.28);
        background: rgba(22, 101, 52, 0.2);
        color: #bbf7d0;
    }
</style>

<script>
    (() => {
        const MAX_TENANT_PICKER_OPTIONS = 12;

        const normalize = (value) => String(value || '')
            .toLocaleLowerCase('ru-RU')
            .replaceAll('ё', 'е')
            .replace(/[^\p{L}\p{N}]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const parseTenantOptionsFromPage = () => {
            if (Array.isArray(window.__mrrTenantSwitchOptions)) {
                return window.__mrrTenantSwitchOptions;
            }

            for (const script of Array.from(document.scripts)) {
                const source = String(script.textContent || '');
                const match = source.match(/const\s+tenantSwitchOptions\s*=\s*(\[[\s\S]*?\]);\s*const\s+csrfToken/u);

                if (!match) {
                    continue;
                }

                try {
                    const parsed = JSON.parse(match[1]);
                    window.__mrrTenantSwitchOptions = Array.isArray(parsed) ? parsed : [];

                    return window.__mrrTenantSwitchOptions;
                } catch (error) {
                    console.warn('Unable to parse tenant switch options', error);
                }
            }

            return [];
        };

        const tenantOptions = () => parseTenantOptionsFromPage()
            .map((tenantOption) => {
                const id = Number(tenantOption?.id || 0);
                const name = String(tenantOption?.name || '').trim();

                if (!Number.isFinite(id) || id <= 0 || name === '') {
                    return null;
                }

                return {
                    id,
                    name,
                    normalizedName: normalize(name),
                };
            })
            .filter(Boolean);

        const pickElements = () => ({
            modal: document.getElementById('mrrManualTenantSwitchModal'),
            input: document.getElementById('mrrManualTenantSwitchTenantSearch'),
            hidden: document.getElementById('mrrManualTenantSwitchTenant'),
            hint: document.getElementById('mrrManualTenantSwitchTenantHint'),
            suggestions: document.getElementById('mrrManualTenantSwitchTenantSuggestions'),
            effectiveDate: document.getElementById('mrrManualTenantSwitchEffectiveDate'),
        });

        const optionMatchesQuery = (option, normalizedQuery) => {
            if (normalizedQuery === '') {
                return true;
            }

            if (option.normalizedName.includes(normalizedQuery)) {
                return true;
            }

            const queryTokens = normalizedQuery.split(' ').filter(Boolean);

            return queryTokens.length > 0 && queryTokens.every((token) => option.normalizedName.includes(token));
        };

        const selectedTenantName = (options, selectedId) => {
            const selected = options.find((option) => option.id === selectedId);

            return selected?.name || '';
        };

        const setSelectedTenant = (option, shouldFocusDate = false) => {
            const { input, hidden, hint, suggestions, effectiveDate } = pickElements();

            if (!(input instanceof HTMLInputElement) || !(hidden instanceof HTMLInputElement) || !option) {
                return;
            }

            hidden.value = String(option.id);
            input.value = option.name;

            if (hint instanceof HTMLElement) {
                hint.textContent = `Выбран арендатор: ${option.name}`;
            }

            if (suggestions instanceof HTMLElement) {
                suggestions.replaceChildren();
                suggestions.hidden = true;
            }

            if (shouldFocusDate && effectiveDate instanceof HTMLInputElement) {
                window.setTimeout(() => effectiveDate.focus(), 0);
            }
        };

        const renderTenantSuggestions = (query = '', options = {}) => {
            const { input, hidden, hint, suggestions } = pickElements();

            if (!(input instanceof HTMLInputElement) || !(hidden instanceof HTMLInputElement) || !(hint instanceof HTMLElement) || !(suggestions instanceof HTMLElement)) {
                return;
            }

            const allOptions = tenantOptions();
            const normalizedQuery = normalize(query);
            const selectedId = Number(hidden.value || 0);
            const selectedName = selectedTenantName(allOptions, selectedId);
            const exactSelected = selectedId > 0 && selectedName !== '' && normalize(input.value) === normalize(selectedName);

            if (!options.preserveSelection && !exactSelected) {
                hidden.value = '';
            }

            const matched = allOptions
                .filter((option) => optionMatchesQuery(option, normalizedQuery))
                .slice(0, MAX_TENANT_PICKER_OPTIONS);

            suggestions.replaceChildren();

            if (matched.length === 0) {
                suggestions.hidden = true;
                hint.textContent = normalizedQuery === ''
                    ? 'Список арендаторов не найден. Обновите страницу и попробуйте снова.'
                    : 'Совпадений не найдено. Уточните имя арендатора.';
                return;
            }

            matched.forEach((tenantOption) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'mrr-manual-tenant-switch__suggestion';
                button.textContent = `#${tenantOption.id} · ${tenantOption.name}`;

                if (tenantOption.id === selectedId) {
                    button.classList.add('is-selected');
                }

                button.addEventListener('click', () => setSelectedTenant(tenantOption, true));
                suggestions.appendChild(button);
            });

            suggestions.hidden = false;
            hint.textContent = normalizedQuery === ''
                ? `Выберите арендатора из списка или начните вводить имя. Показано ${matched.length} из ${allOptions.length}.`
                : `Найдено вариантов: ${matched.length}. Нажмите на нужного арендатора.`;
        };

        const syncPickerAfterModalOpen = () => {
            const { modal, input, hidden } = pickElements();

            if (!(modal instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(hidden instanceof HTMLInputElement)) {
                return;
            }

            if (modal.hidden || !modal.classList.contains('is-open')) {
                return;
            }

            const allOptions = tenantOptions();
            const selectedId = Number(hidden.value || 0);
            const selectedName = selectedTenantName(allOptions, selectedId);

            if (selectedName !== '') {
                input.value = selectedName;
            }

            renderTenantSuggestions(input.value, {
                preserveSelection: true,
            });
        };

        const install = () => {
            const { modal, input, suggestions } = pickElements();

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            if (input.dataset.mrrTenantPickerEnhanced === '1') {
                return;
            }

            input.dataset.mrrTenantPickerEnhanced = '1';

            input.addEventListener('focus', () => renderTenantSuggestions(input.value, {
                preserveSelection: true,
            }));

            input.addEventListener('input', () => renderTenantSuggestions(input.value));

            input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                const firstSuggestion = suggestions instanceof HTMLElement
                    ? suggestions.querySelector('.mrr-manual-tenant-switch__suggestion')
                    : null;

                if (firstSuggestion instanceof HTMLButtonElement) {
                    event.preventDefault();
                    firstSuggestion.click();
                }
            });

            if (modal instanceof HTMLElement) {
                const observer = new MutationObserver(() => {
                    window.setTimeout(syncPickerAfterModalOpen, 0);
                });

                observer.observe(modal, {
                    attributes: true,
                    attributeFilter: ['class', 'hidden', 'aria-hidden'],
                });
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', install, { once: true });
        } else {
            install();
        }

        window.setTimeout(install, 200);
    })();
</script>
