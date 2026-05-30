<style>
    .mrr-needs-card__tenant-context {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-top: 0.18rem;
    }

    .mrr-needs-card__tenant-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border-radius: 999px;
        border: 1px solid rgba(37, 99, 235, 0.14);
        background: rgba(239, 246, 255, 0.72);
        padding: 0.22rem 0.52rem;
        color: #1e3a8a;
        font-size: 0.73rem;
        line-height: 1.25;
    }

    .mrr-needs-card__tenant-pill strong {
        font-weight: 800;
    }

    .mrr-needs-card__tenant-pill--target {
        border-color: rgba(22, 163, 74, 0.18);
        background: rgba(240, 253, 244, 0.88);
        color: #166534;
    }

    .dark .mrr-needs-card__tenant-pill {
        border-color: rgba(96, 165, 250, 0.24);
        background: rgba(30, 64, 175, 0.18);
        color: #bfdbfe;
    }

    .dark .mrr-needs-card__tenant-pill--target {
        border-color: rgba(74, 222, 128, 0.24);
        background: rgba(22, 101, 52, 0.18);
        color: #bbf7d0;
    }

    .mrr-place__meta.is-redundant {
        display: none;
    }

    .mrr-card-actions__warning--tenant-mismatch {
        flex-basis: 100%;
        margin-bottom: 0.35rem;
    }

    .mrr-link--contract-mismatch {
        opacity: 0.62;
        cursor: not-allowed;
    }
</style>

<script>
    (() => {
        const clean = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const observedTenantLabelPattern = /^Фактический\s+арендатор$/u;

        const valueFromDataset = (element, keys) => {
            if (!(element instanceof HTMLElement)) {
                return '';
            }

            for (const key of keys) {
                const value = clean(element.dataset[key] || '');

                if (value !== '') {
                    return value;
                }
            }

            return '';
        };

        const detailValueByLabel = (card, labelPattern) => {
            const details = Array.from(card.querySelectorAll('.mrr-place__decision-detail'));

            for (const detail of details) {
                const label = clean(detail.querySelector('.mrr-place__decision-detail-label')?.textContent || '');

                if (!labelPattern.test(label)) {
                    continue;
                }

                return clean(detail.querySelector('.mrr-place__decision-detail-value')?.textContent || '');
            }

            return '';
        };

        const sideValueByLabel = (card, labelPattern) => {
            const sides = Array.from(card.querySelectorAll('.mrr-place__decision-side'));

            for (const side of sides) {
                const label = clean(side.querySelector('.mrr-place__decision-side-label')?.textContent || '');

                if (!labelPattern.test(label)) {
                    continue;
                }

                return clean(side.querySelector('.mrr-place__decision-side-value')?.textContent || '');
            }

            return '';
        };

        const currentTenantName = (card) => {
            const action = card.querySelector('[data-mrr-current-tenant-name]');
            const fromDataset = valueFromDataset(action, ['mrrCurrentTenantName']);

            if (fromDataset !== '') {
                return fromDataset;
            }

            return sideValueByLabel(card, /^Было$/u)
                || detailValueByLabel(card, /^В карточке места$/u)
                || '';
        };

        const targetTenantName = (card) => {
            const manualAction = card.querySelector('[data-mrr-manual-tenant-switch-open]');
            const contractAction = card.querySelector('[data-mrr-contract-tenant-switch-apply]');
            const financialAction = card.querySelector('[data-mrr-financial-tenant-resolve-open]');

            return valueFromDataset(manualAction, ['mrrSuggestedTenantName'])
                || valueFromDataset(contractAction, ['mrrTenantName'])
                || valueFromDataset(financialAction, ['mrrTenantName'])
                || sideValueByLabel(card, /^Станет$/u)
                || detailValueByLabel(card, observedTenantLabelPattern)
                || '';
        };

        const addTenantPill = (container, label, value, modifier = '') => {
            if (clean(value) === '') {
                return;
            }

            const pill = document.createElement('span');
            pill.className = 'mrr-needs-card__tenant-pill' + modifier;

            const strong = document.createElement('strong');
            strong.textContent = label;

            const text = document.createElement('span');
            text.textContent = value;

            pill.append(strong, text);
            container.appendChild(pill);
        };

        const compactDuplicateMeta = (card) => {
            const placeTitle = clean(card.querySelector('.mrr-place__title')?.textContent || '');
            const meta = card.querySelector('.mrr-needs-card__summary-place .mrr-place__meta');

            if (!(meta instanceof HTMLElement) || placeTitle === '') {
                return;
            }

            const metaText = clean(meta.textContent || '');

            if (metaText === '' || metaText === placeTitle) {
                meta.classList.add('is-redundant');
                return;
            }

            if (metaText.startsWith(placeTitle + ' · ')) {
                meta.textContent = 'Локация: ' + clean(metaText.slice((placeTitle + ' · ').length));
            }
        };


        const enhanceCards = () => {
            document.querySelectorAll('.mrr-needs-card').forEach((card) => {
                if (!(card instanceof HTMLElement)) {
                    return;
                }

                if (card.dataset.mrrTenantContextEnhanced !== '1') {
                    compactDuplicateMeta(card);
                    card.dataset.mrrTenantContextEnhanced = '1';
                }


            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhanceCards, { once: true });
        } else {
            enhanceCards();
        }

        window.setTimeout(enhanceCards, 200);
    })();
</script>
