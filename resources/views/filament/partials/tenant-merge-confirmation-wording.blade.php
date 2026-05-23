<script>
    (() => {
        const cleanText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const improveSuccessState = (modal) => {
            const result = modal.querySelector('[data-mrr-tenant-merge-result]');
            if (!(result instanceof HTMLElement) || !result.classList.contains('is-success')) {
                return;
            }

            const title = cleanText(result.querySelector('.mrr-tenant-merge-modal__result-title')?.textContent || '');
            if (!title.includes('Дубль слит')) {
                return;
            }

            result.querySelectorAll('.mrr-tenant-merge-modal__result-actions button').forEach((button) => {
                if (button instanceof HTMLElement && cleanText(button.textContent) === 'Обновить список') {
                    button.remove();
                }
            });

            if (modal.dataset.mrrTenantMergeAutoRefresh === '1') {
                return;
            }

            modal.dataset.mrrTenantMergeAutoRefresh = '1';

            const note = document.createElement('div');
            note.className = 'mrr-tenant-merge-modal__result-note';
            note.textContent = 'Список обновится автоматически…';
            result.appendChild(note);

            window.setTimeout(() => window.location.reload(), 1400);
        };

        const relabel = () => {
            const modal = document.getElementById('mrrTenantMergePreflightModal');
            if (!(modal instanceof HTMLElement)) {
                return;
            }

            modal.querySelectorAll('.mrr-tenant-merge-modal__result-actions button').forEach((button) => {
                if (!(button instanceof HTMLElement) || cleanText(button.textContent) !== 'Слить дубль') {
                    return;
                }

                button.textContent = 'Продолжить';
                button.classList.remove('mrr-tenant-merge-modal__button--danger');
                button.classList.add('mrr-tenant-merge-modal__button--primary');
                button.setAttribute('title', 'Перейти к финальному подтверждению. Данные пока не изменятся.');
            });

            improveSuccessState(modal);
        };

        const boot = () => {
            relabel();

            const modal = document.getElementById('mrrTenantMergePreflightModal');
            if (!(modal instanceof HTMLElement) || modal.dataset.mrrConfirmationWordingObserver === '1') {
                return;
            }

            modal.dataset.mrrConfirmationWordingObserver = '1';

            new MutationObserver(relabel).observe(modal, {
                childList: true,
                subtree: true,
                characterData: true,
            });
        };

        document.addEventListener('click', () => {
            window.setTimeout(boot, 0);
            window.setTimeout(boot, 120);
            window.setTimeout(boot, 500);
        }, true);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, { once: true });
        } else {
            boot();
        }
    })();
</script>
