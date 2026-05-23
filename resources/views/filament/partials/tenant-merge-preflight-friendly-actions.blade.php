<style>
    .mrr-tenant-merge-modal.is-friendly .mrr-tenant-merge-modal__commands {
        display: none;
    }

    .mrr-tenant-merge-modal.is-friendly [data-mrr-tenant-merge-copy="execute"] {
        display: none;
    }

    .mrr-tenant-merge-modal__result {
        display: none;
        margin-top: 1rem;
        border-radius: 1rem;
        border: 1px solid rgba(37, 99, 235, 0.18);
        background: rgba(239, 246, 255, 0.86);
        padding: 0.85rem 0.95rem;
        color: #1e3a8a;
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .mrr-tenant-merge-modal__result.is-open {
        display: block;
    }

    .mrr-tenant-merge-modal__result.is-error {
        border-color: rgba(220, 38, 38, 0.22);
        background: rgba(254, 242, 242, 0.9);
        color: #991b1b;
    }

    .dark .mrr-tenant-merge-modal__result {
        border-color: rgba(96, 165, 250, 0.22);
        background: rgba(30, 64, 175, 0.18);
        color: #bfdbfe;
    }

    .dark .mrr-tenant-merge-modal__result.is-error {
        border-color: rgba(248, 113, 113, 0.26);
        background: rgba(127, 29, 29, 0.22);
        color: #fecaca;
    }

    .mrr-tenant-merge-modal__result-title {
        margin: 0 0 0.5rem;
        font-weight: 850;
        color: inherit;
    }

    .mrr-tenant-merge-modal__result-list {
        display: grid;
        gap: 0.28rem;
        margin: 0.5rem 0 0;
        padding: 0;
        list-style: none;
    }

    .mrr-tenant-merge-modal__result-list li {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        border-radius: 0.65rem;
        background: rgba(255, 255, 255, 0.58);
        padding: 0.42rem 0.55rem;
    }

    .dark .mrr-tenant-merge-modal__result-list li {
        background: rgba(15, 23, 42, 0.34);
    }

    .mrr-tenant-merge-modal__result-note {
        margin-top: 0.65rem;
        color: inherit;
        opacity: 0.88;
    }
</style>

<script>
    (() => {
        const preflightUrl = @json(route('filament.admin.tenant-merge.preflight'));
        const csrfToken = @json(csrf_token());

        const clean = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const parseTenantId = (text) => {
            const match = clean(text).match(/^#(\d+)\s*·/u);

            return match ? Number(match[1]) : 0;
        };

        const ensureResultBox = (modal) => {
            let result = modal.querySelector('[data-mrr-tenant-merge-result]');

            if (result instanceof HTMLElement) {
                return result;
            }

            result = document.createElement('div');
            result.className = 'mrr-tenant-merge-modal__result';
            result.setAttribute('data-mrr-tenant-merge-result', '1');

            const warning = modal.querySelector('.mrr-tenant-merge-modal__warning');
            if (warning instanceof HTMLElement) {
                warning.insertAdjacentElement('afterend', result);
            } else {
                modal.querySelector('.mrr-tenant-merge-modal__dialog')?.appendChild(result);
            }

            return result;
        };

        const resetCheckButton = (button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            button.textContent = 'Проверить безопасно';
            button.removeAttribute('disabled');
            button.removeAttribute('aria-disabled');
        };

        const setLoading = (button, loading) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            if (loading) {
                button.textContent = 'Проверяю…';
                button.setAttribute('disabled', 'disabled');
                button.setAttribute('aria-disabled', 'true');
                return;
            }

            resetCheckButton(button);
        };

        const setCompleted = (button, isError = false) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            button.textContent = isError ? 'Проверить ещё раз' : 'Проверить ещё раз';
            button.removeAttribute('disabled');
            button.setAttribute('aria-disabled', 'false');
            button.setAttribute('title', isError
                ? 'Проверка не прошла. Нажмите, чтобы повторить безопасную проверку.'
                : 'Проверка уже выполнена. Нажмите, чтобы повторить безопасную проверку.');
        };

        const renderResult = (modal, data, isError = false) => {
            const result = ensureResultBox(modal);
            result.classList.add('is-open');
            result.classList.toggle('is-error', isError);
            result.replaceChildren();

            const title = document.createElement('div');
            title.className = 'mrr-tenant-merge-modal__result-title';
            title.textContent = data?.message || (isError ? 'Проверка не прошла.' : 'Проверка прошла.');
            result.appendChild(title);

            const summary = data?.summary || null;

            if (summary && Array.isArray(summary.non_zero_transfers) && summary.non_zero_transfers.length > 0) {
                const list = document.createElement('ul');
                list.className = 'mrr-tenant-merge-modal__result-list';

                summary.non_zero_transfers.forEach((item) => {
                    const row = document.createElement('li');
                    const label = document.createElement('span');
                    const count = document.createElement('strong');

                    label.textContent = item.label || item.key || 'связи';
                    count.textContent = String(item.count || 0);

                    row.append(label, count);
                    list.appendChild(row);
                });

                result.appendChild(list);
            } else if (summary && Number(summary.total_references || 0) === 0) {
                const note = document.createElement('div');
                note.className = 'mrr-tenant-merge-modal__result-note';
                note.textContent = 'Связей для переноса не найдено.';
                result.appendChild(note);
            }

            if (summary) {
                const notes = [];

                notes.push(`Alias для будущих импортов 1С: ${Number(summary.alias_count || 0)}`);
                notes.push(summary.showcase_action || 'действий с витриной нет');

                if (Number(summary.accrual_conflict_count || 0) > 0) {
                    notes.push(`Конфликты начислений: ${Number(summary.accrual_conflict_count || 0)}`);
                } else {
                    notes.push('Конфликтов начислений нет');
                }

                const note = document.createElement('div');
                note.className = 'mrr-tenant-merge-modal__result-note';
                note.textContent = notes.join(' · ');
                result.appendChild(note);
            }

            const next = document.createElement('div');
            next.className = 'mrr-tenant-merge-modal__result-note';
            next.textContent = isError
                ? 'Слияние заблокировано до исправления причины.'
                : 'Данные пока не изменены. Реальное слияние добавим отдельным защищённым шагом.';
            result.appendChild(next);
            result.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        };

        const runPreflight = async (modal, button) => {
            const canonicalId = parseTenantId(document.getElementById('mrrTenantMergeCanonicalName')?.textContent || '');
            const sourceId = parseTenantId(document.getElementById('mrrTenantMergeSourceName')?.textContent || '');

            if (canonicalId <= 0 || sourceId <= 0 || canonicalId === sourceId) {
                renderResult(modal, { message: 'Не удалось определить пару арендаторов. Закройте окно и откройте карточку заново.' }, true);
                setCompleted(button, true);
                return;
            }

            setLoading(button, true);

            try {
                const response = await fetch(preflightUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        source_tenant_id: sourceId,
                        canonical_tenant_id: canonicalId,
                    }),
                });

                const data = await response.json().catch(() => ({}));
                const isError = !response.ok || data?.ok === false;

                renderResult(modal, data, isError);
                setCompleted(button, isError);
            } catch (error) {
                renderResult(modal, { message: 'Не удалось выполнить проверку. Попробуйте обновить страницу.' }, true);
                setCompleted(button, true);
            }
        };

        const improveModal = () => {
            const modal = document.getElementById('mrrTenantMergePreflightModal');

            if (!(modal instanceof HTMLElement)) {
                return;
            }

            modal.classList.add('is-friendly');

            const title = document.getElementById('mrrTenantMergePreflightTitle');
            if (title instanceof HTMLElement) {
                title.textContent = 'Разобрать дубль арендатора';
            }

            const copy = modal.querySelector('.mrr-tenant-merge-modal__copy');
            if (copy instanceof HTMLElement) {
                copy.textContent = 'Сначала выполните безопасную проверку. Она покажет, что будет перенесено, но ничего не изменит.';
            }

            const warning = modal.querySelector('.mrr-tenant-merge-modal__warning');
            if (warning instanceof HTMLElement) {
                warning.textContent = 'Реальное слияние из UI пока выключено. После проверки система только покажет понятный отчёт.';
            }

            const result = modal.querySelector('[data-mrr-tenant-merge-result]');
            if (result instanceof HTMLElement) {
                result.classList.remove('is-open', 'is-error');
                result.replaceChildren();
            }

            const checkButton = modal.querySelector('[data-mrr-tenant-merge-copy="dry-run"]');
            resetCheckButton(checkButton);

            if (checkButton instanceof HTMLElement && checkButton.dataset.mrrFriendlyBound !== '1') {
                checkButton.dataset.mrrFriendlyBound = '1';
                checkButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    runPreflight(modal, checkButton);
                }, true);
            }
        };

        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            if (event.target.closest('button.mrr-quality-signal__link--primary')) {
                window.setTimeout(improveModal, 0);
                window.setTimeout(improveModal, 80);
            }
        }, true);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', improveModal, { once: true });
        } else {
            improveModal();
        }
    })();
</script>
