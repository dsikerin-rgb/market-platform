<script>
    (() => {
        const cleanText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const relabel = () => {
            const modal = document.getElementById('mrrTenantMergePreflightModal');
            if (!(modal instanceof HTMLElement) || !modal.classList.contains('is-open')) {
                return;
            }

            modal.querySelectorAll('.mrr-tenant-merge-modal__result-actions button').forEach((button) => {
                if (!(button instanceof HTMLElement) || cleanText(button.textContent) !== 'Слить дубль') {
                    return;
                }

                button.textContent = 'Продолжить';
                button.classList.remove('mrr-tenant-merge-modal__button--danger');
                button.classList.add('mrr-tenant-merge-modal__button--primary');
            });
        };

        document.addEventListener('click', () => {
            window.setTimeout(relabel, 0);
            window.setTimeout(relabel, 80);
        }, true);
    })();
</script>
