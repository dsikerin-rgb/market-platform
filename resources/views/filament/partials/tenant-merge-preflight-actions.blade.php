<style>
    .mrr-quality-signal__link--primary {
        border-color: rgba(37, 99, 235, 0.28);
        background: #2563eb;
        color: #fff;
        cursor: pointer;
        font-family: inherit;
        line-height: inherit;
    }

    .mrr-quality-signal__link--primary:hover {
        background: #1d4ed8;
        color: #fff;
    }

    .dark .mrr-quality-signal__link--primary {
        border-color: rgba(96, 165, 250, 0.36);
        background: #3b82f6;
        color: #fff;
    }

    .mrr-tenant-merge-modal {
        position: fixed;
        inset: 0;
        z-index: 80;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .mrr-tenant-merge-modal.is-open {
        display: flex;
    }

    .mrr-tenant-merge-modal__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.58);
        backdrop-filter: blur(4px);
    }

    .mrr-tenant-merge-modal__dialog {
        position: relative;
        width: min(980px, 100%);
        max-height: calc(100dvh - 2rem);
        overflow-y: auto;
        border-radius: 1.25rem;
        border: 1px solid rgba(148, 163, 184, 0.24);
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
        padding: 1.1rem;
    }

    .dark .mrr-tenant-merge-modal__dialog {
        border-color: rgba(148, 163, 184, 0.24);
        background: rgba(15, 23, 42, 0.98);
    }

    .mrr-tenant-merge-modal__close {
        position: absolute;
        top: 0.8rem;
        right: 0.8rem;
        width: 2rem;
        height: 2rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(248, 250, 252, 0.95);
        color: #475569;
        cursor: pointer;
        appearance: none;
        font-size: 1.1rem;
        line-height: 1;
    }

    .dark .mrr-tenant-merge-modal__close {
        background: rgba(15, 23, 42, 0.92);
        color: #cbd5e1;
    }

    .mrr-tenant-merge-modal__eyebrow {
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
    }

    .dark .mrr-tenant-merge-modal__eyebrow {
        color: #94a3b8;
    }

    .mrr-tenant-merge-modal__title {
        margin: 0.25rem 2.2rem 0 0;
        font-size: 1.15rem;
        line-height: 1.3;
        color: #0f172a;
    }

    .dark .mrr-tenant-merge-modal__title {
        color: #f8fafc;
    }

    .mrr-tenant-merge-modal__copy {
        margin: 0.4rem 0 0;
        max-width: 50rem;
        font-size: 0.88rem;
        line-height: 1.5;
        color: #475569;
    }

    .dark .mrr-tenant-merge-modal__copy {
        color: #cbd5e1;
    }

    .mrr-tenant-merge-modal__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
        margin-top: 1rem;
    }

    .mrr-tenant-merge-modal__tenant {
        border-radius: 1rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: rgba(248, 250, 252, 0.88);
        padding: 0.8rem 0.9rem;
    }

    .dark .mrr-tenant-merge-modal__tenant {
        border-color: rgba(148, 163, 184, 0.16);
        background: rgba(15, 23, 42, 0.58);
    }

    .mrr-tenant-merge-modal__tenant.is-primary {
        border-color: rgba(37, 99, 235, 0.26);
        background: rgba(239, 246, 255, 0.94);
    }

    .dark .mrr-tenant-merge-modal__tenant.is-primary {
        border-color: rgba(96, 165, 250, 0.32);
        background: rgba(30, 64, 175, 0.18);
    }

    .mrr-tenant-merge-modal__tenant-label {
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b;
    }

    .dark .mrr-tenant-merge-modal__tenant-label {
        color: #94a3b8;
    }

    .mrr-tenant-merge-modal__tenant-name {
        margin-top: 0.25rem;
        color: #0f172a;
        font-size: 0.98rem;
        font-weight: 850;
        line-height: 1.25;
        word-break: break-word;
    }

    .dark .mrr-tenant-merge-modal__tenant-name {
        color: #f8fafc;
    }

    .mrr-tenant-merge-modal__tenant-meta {
        display: grid;
        gap: 0.16rem;
        margin-top: 0.55rem;
        color: #64748b;
        font-size: 0.78rem;
        line-height: 1.35;
    }

    .dark .mrr-tenant-merge-modal__tenant-meta {
        color: #cbd5e1;
    }

    .mrr-tenant-merge-modal__technical {
        margin-top: 0.3rem;
        color: #64748b;
        font-size: 0.74rem;
    }

    .mrr-tenant-merge-modal__technical summary {
        cursor: pointer;
        font-weight: 750;
    }

    .mrr-tenant-merge-modal__technical-body {
        display: grid;
        gap: 0.14rem;
        margin-top: 0.28rem;
        word-break: break-all;
    }

    .dark .mrr-tenant-merge-modal__technical {
        color: #cbd5e1;
    }

    .mrr-tenant-merge-modal__commands {
        display: grid;
        gap: 0.65rem;
        margin-top: 1rem;
    }

    .mrr-tenant-merge-modal__command {
        border-radius: 0.95rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: rgba(15, 23, 42, 0.04);
        padding: 0.75rem;
    }

    .dark .mrr-tenant-merge-modal__command {
        border-color: rgba(148, 163, 184, 0.16);
        background: rgba(15, 23, 42, 0.52);
    }

    .mrr-tenant-merge-modal__command-label {
        margin-bottom: 0.38rem;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #475569;
    }

    .dark .mrr-tenant-merge-modal__command-label {
        color: #cbd5e1;
    }

    .mrr-tenant-merge-modal__code {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        color: #0f172a;
        font-size: 0.86rem;
        line-height: 1.5;
    }

    .dark .mrr-tenant-merge-modal__code {
        color: #f8fafc;
    }

    .mrr-tenant-merge-modal__warning {
        margin-top: 1rem;
        border-radius: 0.95rem;
        border: 1px solid rgba(245, 158, 11, 0.26);
        background: rgba(255, 251, 235, 0.82);
        padding: 0.7rem 0.8rem;
        color: #92400e;
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .dark .mrr-tenant-merge-modal__warning {
        border-color: rgba(251, 191, 36, 0.28);
        background: rgba(69, 26, 3, 0.18);
        color: #fde68a;
    }

    .mrr-tenant-merge-modal__actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .mrr-tenant-merge-modal__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid rgba(15, 23, 42, 0.1);
        background: rgba(255, 255, 255, 0.95);
        padding: 0.42rem 0.72rem;
        color: #0f172a;
        cursor: pointer;
        font: inherit;
        font-size: 0.84rem;
        font-weight: 800;
        line-height: 1.2;
        text-decoration: none;
    }

    .dark .mrr-tenant-merge-modal__button {
        border-color: rgba(148, 163, 184, 0.18);
        background: rgba(15, 23, 42, 0.92);
        color: #f8fafc;
    }

    .mrr-tenant-merge-modal__button--primary {
        border-color: rgba(37, 99, 235, 0.24);
        background: #2563eb;
        color: #fff;
    }

    .mrr-tenant-merge-modal__button--danger {
        border-color: rgba(185, 28, 28, 0.22);
        color: #b91c1c;
    }

    .dark .mrr-tenant-merge-modal__button--primary {
        border-color: rgba(96, 165, 250, 0.32);
        background: #3b82f6;
        color: #fff;
    }

    .dark .mrr-tenant-merge-modal__button--danger {
        border-color: rgba(248, 113, 113, 0.26);
        color: #fecaca;
    }

    @media (max-width: 760px) {
        .mrr-tenant-merge-modal__grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div id="mrrTenantMergePreflightModal" class="mrr-tenant-merge-modal" hidden aria-hidden="true">
    <div class="mrr-tenant-merge-modal__backdrop" data-mrr-tenant-merge-close></div>
    <div class="mrr-tenant-merge-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mrrTenantMergePreflightTitle">
        <button type="button" class="mrr-tenant-merge-modal__close" data-mrr-tenant-merge-close aria-label="Закрыть">×</button>
        <div class="mrr-tenant-merge-modal__eyebrow">Подготовка слияния</div>
        <h3 id="mrrTenantMergePreflightTitle" class="mrr-tenant-merge-modal__title">Разобрать дубль арендатора</h3>
        <p class="mrr-tenant-merge-modal__copy">
            Сначала проверьте, что будет перенесено при объединении. На этом шаге данные не изменятся.
        </p>

        <div class="mrr-tenant-merge-modal__grid">
            <div class="mrr-tenant-merge-modal__tenant is-primary">
                <div class="mrr-tenant-merge-modal__tenant-label">Основная карточка</div>
                <div id="mrrTenantMergeCanonicalName" class="mrr-tenant-merge-modal__tenant-name">—</div>
                <div id="mrrTenantMergeCanonicalMeta" class="mrr-tenant-merge-modal__tenant-meta"></div>
            </div>
            <div class="mrr-tenant-merge-modal__tenant">
                <div class="mrr-tenant-merge-modal__tenant-label">Карточка-дубль</div>
                <div id="mrrTenantMergeSourceName" class="mrr-tenant-merge-modal__tenant-name">—</div>
                <div id="mrrTenantMergeSourceMeta" class="mrr-tenant-merge-modal__tenant-meta"></div>
            </div>
        </div>

        <div class="mrr-tenant-merge-modal__commands">
            <div class="mrr-tenant-merge-modal__command">
                <div class="mrr-tenant-merge-modal__command-label">Проверка</div>
                <pre id="mrrTenantMergeDryRunCommand" class="mrr-tenant-merge-modal__code">—</pre>
            </div>
            <div class="mrr-tenant-merge-modal__command">
                <div class="mrr-tenant-merge-modal__command-label">Слияние после проверки</div>
                <pre id="mrrTenantMergeExecuteCommand" class="mrr-tenant-merge-modal__code">—</pre>
            </div>
        </div>

        <div class="mrr-tenant-merge-modal__warning">
            После проверки можно будет перейти к подтверждению объединения.
        </div>

        <div class="mrr-tenant-merge-modal__actions">
            <button type="button" class="mrr-tenant-merge-modal__button" data-mrr-tenant-merge-swap>Сделать основной другую</button>
            <a id="mrrTenantMergeCanonicalLink" class="mrr-tenant-merge-modal__button" href="#" target="_blank" rel="noopener">Открыть основную</a>
            <a id="mrrTenantMergeSourceLink" class="mrr-tenant-merge-modal__button" href="#" target="_blank" rel="noopener">Открыть дубль</a>
            <button type="button" class="mrr-tenant-merge-modal__button mrr-tenant-merge-modal__button--primary" data-mrr-tenant-merge-copy="dry-run">Проверить</button>
            <button type="button" class="mrr-tenant-merge-modal__button mrr-tenant-merge-modal__button--danger" data-mrr-tenant-merge-copy="execute">Слить дубль</button>
            <button type="button" class="mrr-tenant-merge-modal__button" data-mrr-tenant-merge-close>Закрыть</button>
        </div>
    </div>
</div>

<script>
    (() => {
        const modal = document.getElementById('mrrTenantMergePreflightModal');
        const canonicalName = document.getElementById('mrrTenantMergeCanonicalName');
        const sourceName = document.getElementById('mrrTenantMergeSourceName');
        const canonicalMeta = document.getElementById('mrrTenantMergeCanonicalMeta');
        const sourceMeta = document.getElementById('mrrTenantMergeSourceMeta');
        const dryRunCommand = document.getElementById('mrrTenantMergeDryRunCommand');
        const executeCommand = document.getElementById('mrrTenantMergeExecuteCommand');
        const canonicalLink = document.getElementById('mrrTenantMergeCanonicalLink');
        const sourceLink = document.getElementById('mrrTenantMergeSourceLink');

        if (!modal || !canonicalName || !sourceName || !canonicalMeta || !sourceMeta || !dryRunCommand || !executeCommand || !canonicalLink || !sourceLink) {
            return;
        }

        const state = {
            canonical: null,
            source: null,
        };

        const parseMeta = (tenantCard) => {
            const meta = {
                inn: '',
                kpp: '',
                externalId: '',
                oneCUid: '',
            };

            tenantCard.querySelectorAll('span').forEach((item) => {
                const text = String(item.textContent || '').trim();
                const value = text.split(':').slice(1).join(':').trim();

                if (text.startsWith('ИНН:')) {
                    meta.inn = value === '—' ? '' : value;
                }

                if (text.startsWith('КПП:')) {
                    meta.kpp = value === '—' ? '' : value;
                }

                if (text.startsWith('external_id:') || text.startsWith('ID из 1С:')) {
                    meta.externalId = value === '—' ? '' : value;
                }

                if (text.startsWith('one_c_uid:') || text.startsWith('UID из 1С:')) {
                    meta.oneCUid = value === '—' ? '' : value;
                }
            });

            return meta;
        };

        const parseTenant = (article, tenantCard) => {
            const title = String(tenantCard.querySelector('.mrr-quality-signal__tenant-name')?.textContent || '').trim();
            const match = title.match(/^#(\d+)\s*·\s*(.*)$/u);
            const id = match ? Number(match[1]) : 0;
            const name = match ? String(match[2] || '').trim() : title;
            const link = Array.from(article.querySelectorAll('.mrr-quality-signal__actions a'))
                .find((item) => String(item.textContent || '').includes(`#${id}`));

            return {
                id,
                name,
                url: link instanceof HTMLAnchorElement ? link.href : '',
                ...parseMeta(tenantCard),
            };
        };

        const tenantLabel = (tenant) => {
            if (!tenant || !tenant.id) {
                return '—';
            }

            return `#${tenant.id} · ${tenant.name || 'Без названия'}`;
        };

        const renderMeta = (target, tenant) => {
            target.replaceChildren();

            [
                ['ИНН', tenant?.inn || '—'],
                ['КПП', tenant?.kpp || ''],
            ].forEach(([label, value]) => {
                if (value === '') {
                    return;
                }

                const line = document.createElement('span');
                line.textContent = `${label}: ${value}`;
                target.appendChild(line);
            });

            if (tenant?.externalId || tenant?.oneCUid) {
                const details = document.createElement('details');
                details.className = 'mrr-tenant-merge-modal__technical';

                const summary = document.createElement('summary');
                summary.textContent = 'Технические данные 1С';
                details.appendChild(summary);

                const body = document.createElement('div');
                body.className = 'mrr-tenant-merge-modal__technical-body';

                if (tenant.externalId) {
                    const line = document.createElement('span');
                    line.textContent = `ID из 1С: ${tenant.externalId}`;
                    body.appendChild(line);
                }

                if (tenant.oneCUid) {
                    const line = document.createElement('span');
                    line.textContent = `UID из 1С: ${tenant.oneCUid}`;
                    body.appendChild(line);
                }

                details.appendChild(body);
                target.appendChild(details);
            }
        };

        const commandFor = (execute = false) => {
            const sourceId = Number(state.source?.id || 0);
            const canonicalId = Number(state.canonical?.id || 0);

            if (sourceId <= 0 || canonicalId <= 0) {
                return '—';
            }

            return `php artisan tenants:merge ${sourceId} ${canonicalId}${execute ? ' --execute' : ''}`;
        };

        const syncModal = () => {
            canonicalName.textContent = tenantLabel(state.canonical);
            sourceName.textContent = tenantLabel(state.source);
            renderMeta(canonicalMeta, state.canonical);
            renderMeta(sourceMeta, state.source);
            dryRunCommand.textContent = commandFor(false);
            executeCommand.textContent = commandFor(true);

            if (state.canonical?.url) {
                canonicalLink.href = state.canonical.url;
                canonicalLink.removeAttribute('aria-disabled');
            } else {
                canonicalLink.removeAttribute('href');
                canonicalLink.setAttribute('aria-disabled', 'true');
            }

            if (state.source?.url) {
                sourceLink.href = state.source.url;
                sourceLink.removeAttribute('aria-disabled');
            } else {
                sourceLink.removeAttribute('href');
                sourceLink.setAttribute('aria-disabled', 'true');
            }
        };

        const openModal = (canonical, source) => {
            state.canonical = canonical;
            state.source = source;
            syncModal();
            modal.hidden = false;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        };

        const closeModal = () => {
            modal.classList.remove('is-open');
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            state.canonical = null;
            state.source = null;
        };

        const copyText = async (text) => {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(text);
                return;
            }

            const field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', 'readonly');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            field.remove();
        };

        const enhanceCards = () => {
            document.querySelectorAll('.mrr-quality-signal').forEach((article) => {
                if (!(article instanceof HTMLElement) || article.dataset.mrrTenantMergeEnhanced === '1') {
                    return;
                }

                const tenantCards = article.querySelectorAll('.mrr-quality-signal__tenant');
                const actions = article.querySelector('.mrr-quality-signal__actions');

                if (tenantCards.length < 2 || !(actions instanceof HTMLElement)) {
                    return;
                }

                const canonical = parseTenant(article, tenantCards[0]);
                const source = parseTenant(article, tenantCards[1]);

                if (!canonical.id || !source.id || canonical.id === source.id) {
                    return;
                }

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'mrr-quality-signal__link mrr-quality-signal__link--primary';
                button.textContent = 'Подготовить слияние';
                button.addEventListener('click', () => openModal(canonical, source));

                actions.appendChild(button);
                article.dataset.mrrTenantMergeEnhanced = '1';
            });
        };

        modal.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            if (event.target.hasAttribute('data-mrr-tenant-merge-close')) {
                event.preventDefault();
                closeModal();
                return;
            }

            if (event.target.hasAttribute('data-mrr-tenant-merge-swap')) {
                event.preventDefault();
                const oldCanonical = state.canonical;
                state.canonical = state.source;
                state.source = oldCanonical;
                syncModal();
                return;
            }

            const copyButton = event.target.closest('[data-mrr-tenant-merge-copy]');
            if (copyButton instanceof HTMLElement) {
                event.preventDefault();
                const type = String(copyButton.dataset.mrrTenantMergeCopy || 'dry-run');
                const text = commandFor(type === 'execute');
                const originalText = copyButton.textContent;

                copyText(text).then(() => {
                    copyButton.textContent = 'Скопировано';
                    window.setTimeout(() => {
                        copyButton.textContent = originalText;
                    }, 1200);
                }).catch(() => {
                    copyButton.textContent = 'Не скопировано';
                    window.setTimeout(() => {
                        copyButton.textContent = originalText;
                    }, 1200);
                });
            }
        });

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                event.preventDefault();
                closeModal();
            }
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhanceCards, { once: true });
        } else {
            enhanceCards();
        }

        window.setTimeout(enhanceCards, 200);
    })();
</script>
