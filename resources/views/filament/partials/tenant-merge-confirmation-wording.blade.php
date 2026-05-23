<script>
    (() => {
        const cleanText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

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
