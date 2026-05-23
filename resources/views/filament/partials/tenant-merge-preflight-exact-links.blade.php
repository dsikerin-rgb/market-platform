<script>
    (() => {
        const clean = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const tenantIdFromCard = (tenantCard) => {
            const title = clean(tenantCard.querySelector('.mrr-quality-signal__tenant-name')?.textContent || '');
            const match = title.match(/^#(\d+)\s*·/u);

            return match ? Number(match[1]) : 0;
        };

        const exactTenantUrl = (article, tenantId) => {
            const expectedPath = `/admin/tenants/${tenantId}/edit`;
            const links = Array.from(article.querySelectorAll('.mrr-quality-signal__actions a'));

            for (const link of links) {
                if (!(link instanceof HTMLAnchorElement)) {
                    continue;
                }

                try {
                    const url = new URL(link.href, window.location.href);

                    if (url.pathname === expectedPath || url.pathname === `${expectedPath}/`) {
                        return link.href;
                    }
                } catch (error) {
                    // Ignore malformed hrefs and continue with the text fallback below.
                }

                const text = clean(link.textContent || '');
                const exactTextPattern = new RegExp(`^Открыть арендатора #${tenantId}$`, 'u');

                if (exactTextPattern.test(text)) {
                    return link.href;
                }
            }

            return '';
        };

        const setLink = (link, url) => {
            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }

            if (url !== '') {
                link.href = url;
                link.removeAttribute('aria-disabled');
            } else {
                link.removeAttribute('href');
                link.setAttribute('aria-disabled', 'true');
            }
        };

        const applyExactLinks = (modal) => {
            if (!(modal instanceof HTMLElement)) {
                return;
            }

            const swapped = modal.dataset.mrrExactTenantLinksSwapped === '1';
            const canonicalUrl = modal.dataset.mrrExactCanonicalUrl || '';
            const sourceUrl = modal.dataset.mrrExactSourceUrl || '';

            setLink(
                document.getElementById('mrrTenantMergeCanonicalLink'),
                swapped ? sourceUrl : canonicalUrl,
            );
            setLink(
                document.getElementById('mrrTenantMergeSourceLink'),
                swapped ? canonicalUrl : sourceUrl,
            );
        };

        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            const prepareButton = event.target.closest('button.mrr-quality-signal__link--primary');

            if (prepareButton instanceof HTMLButtonElement) {
                const article = prepareButton.closest('.mrr-quality-signal');
                const modal = document.getElementById('mrrTenantMergePreflightModal');
                const tenantCards = article instanceof HTMLElement
                    ? article.querySelectorAll('.mrr-quality-signal__tenant')
                    : [];

                if (!(article instanceof HTMLElement) || !(modal instanceof HTMLElement) || tenantCards.length < 2) {
                    return;
                }

                const canonicalId = tenantIdFromCard(tenantCards[0]);
                const sourceId = tenantIdFromCard(tenantCards[1]);

                modal.dataset.mrrExactCanonicalUrl = canonicalId > 0 ? exactTenantUrl(article, canonicalId) : '';
                modal.dataset.mrrExactSourceUrl = sourceId > 0 ? exactTenantUrl(article, sourceId) : '';
                modal.dataset.mrrExactTenantLinksSwapped = '0';

                window.setTimeout(() => applyExactLinks(modal), 0);
                return;
            }

            const swapButton = event.target.closest('[data-mrr-tenant-merge-swap]');

            if (swapButton instanceof HTMLElement) {
                const modal = document.getElementById('mrrTenantMergePreflightModal');

                if (!(modal instanceof HTMLElement)) {
                    return;
                }

                window.setTimeout(() => {
                    modal.dataset.mrrExactTenantLinksSwapped = modal.dataset.mrrExactTenantLinksSwapped === '1' ? '0' : '1';
                    applyExactLinks(modal);
                }, 0);
            }
        }, true);
    })();
</script>
