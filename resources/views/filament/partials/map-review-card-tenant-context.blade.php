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
                || detailValueByLabel(card, /^Фактический арендатор$/u)
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

        const canonicalTenantName = (value) => clean(value)
            .toLocaleLowerCase('ru-RU')
            .replace(/[«»"'()]/g, ' ')
            .replace(/\b(ип|ооо|ао|пао|зао|оао)\b/giu, ' ')
            .replace(/[^\p{L}\p{N}]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        const tenantNamesLookSame = (left, right) => {
            const a = canonicalTenantName(left);
            const b = canonicalTenantName(right);

            if (a === '' || b === '') {
                return false;
            }

            return a === b || a.includes(b) || b.includes(a);
        };

        const reviewReasonText = (card) => clean(
            card.querySelector('.mrr-conflict-brief__hint-text')?.textContent
            || card.querySelector('.mrr-needs-card__reason')?.textContent
            || ''
        );

        const extractReviewerTenantName = (reason) => {
            const source = clean(reason);

            if (source === '') {
                return '';
            }

            const explicitMatch = source.match(/арендатор\s+(?:стал|стала|сменился|сменился\s+на|теперь|новый)?\s*[:—-]?\s*(.+?)(?:\.|$)/iu);
            let text = clean(explicitMatch?.[1] || '');

            if (text === '') {
                return '';
            }

            text = text
                .replace(/^(стал|стала)\s+/iu, '')
                .replace(/\s+(стоит|уже|договор|по\s+данным|после|старый|старые)\b.*$/iu, '')
                .replace(/[.;,:—-]+$/u, '')
                .trim();

            return text.length >= 3 ? text : '';
        };

        const addManualTenantOverrideAction = (card) => {
            if (!(card instanceof HTMLElement) || card.dataset.mrrManualTenantOverrideEnhanced === '1') {
                return;
            }

            const contractButton = card.querySelector('[data-mrr-contract-tenant-switch-apply]');
            if (!(contractButton instanceof HTMLButtonElement)) {
                card.dataset.mrrManualTenantOverrideEnhanced = '1';
                return;
            }

            const reason = reviewReasonText(card);
            const reviewerTenantName = extractReviewerTenantName(reason);
            const contractTenantName = clean(contractButton.dataset.mrrTenantName || targetTenantName(card));

            if (
                reviewerTenantName === ''
                || contractTenantName === ''
                || tenantNamesLookSame(reviewerTenantName, contractTenantName)
            ) {
                card.dataset.mrrManualTenantOverrideEnhanced = '1';
                return;
            }

            const row = contractButton.closest('.mrr-card-actions__row');
            if (!(row instanceof HTMLElement)) {
                card.dataset.mrrManualTenantOverrideEnhanced = '1';
                return;
            }

            const warning = document.createElement('div');
            warning.className = 'mrr-card-actions__warning mrr-card-actions__warning--tenant-mismatch';

            const warningStrong = document.createElement('strong');
            warningStrong.textContent = 'Проверьте арендатора:';

            const warningText = document.createElement('span');
            warningText.textContent = ` ревизор указал «${reviewerTenantName}», а договор предлагает «${contractTenantName}». Договорная кнопка отключена, выберите арендатора вручную.`;

            warning.append(warningStrong, warningText);

            const manualButton = document.createElement('button');
            manualButton.type = 'button';
            manualButton.className = 'mrr-link mrr-link--button mrr-link--primary';
            manualButton.setAttribute('data-mrr-manual-tenant-switch-open', '');
            manualButton.dataset.mrrSpaceId = clean(contractButton.dataset.mrrSpaceId || '');
            manualButton.dataset.mrrCurrentTenantName = clean(contractButton.dataset.mrrCurrentTenantName || currentTenantName(card));
            manualButton.dataset.mrrSuggestedTenantId = '0';
            manualButton.dataset.mrrSuggestedTenantName = reviewerTenantName;
            manualButton.dataset.mrrEffectiveDate = new Date().toISOString().slice(0, 10);
            manualButton.dataset.mrrReason = reason || `По ревизии: арендатор стал ${reviewerTenantName}.`;
            manualButton.textContent = 'Сменить на арендатора из ревизии';

            contractButton.textContent = 'Договорный кандидат не совпадает';
            contractButton.title = `Ревизор указал ${reviewerTenantName}, договор предлагает ${contractTenantName}`;
            contractButton.classList.add('mrr-link--contract-mismatch');
            contractButton.setAttribute('disabled', 'disabled');
            contractButton.setAttribute('aria-disabled', 'true');

            row.insertBefore(warning, contractButton);
            row.insertBefore(manualButton, contractButton.nextSibling);
            card.dataset.mrrManualTenantOverrideEnhanced = '1';
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

                addManualTenantOverrideAction(card);
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