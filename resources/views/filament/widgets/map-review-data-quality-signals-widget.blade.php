<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <style>
        .mrr-quality-signals {
            display: grid;
            gap: 0.9rem;
        }

        .mrr-quality-signal {
            border-radius: 1rem;
            border: 1px solid rgba(245, 158, 11, 0.22);
            background: rgba(255, 251, 235, 0.78);
            padding: 0.9rem 1rem;
        }

        .dark .mrr-quality-signal {
            border-color: rgba(251, 191, 36, 0.24);
            background: rgba(69, 26, 3, 0.16);
        }

        .mrr-quality-signal.is-ignored,
        .mrr-hidden-pair.is-restored {
            display: none;
        }

        .mrr-quality-signal__head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.65rem;
        }

        .mrr-quality-signal__title {
            margin: 0;
            color: #0f172a;
            font-size: 0.98rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .dark .mrr-quality-signal__title {
            color: #f8fafc;
        }

        .mrr-quality-signal__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        .mrr-quality-signal__badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.24rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 800;
            line-height: 1.2;
            background: rgba(245, 158, 11, 0.14);
            color: #92400e;
        }

        .mrr-quality-signal__badge--high {
            background: rgba(239, 68, 68, 0.14);
            color: #b91c1c;
        }

        .dark .mrr-quality-signal__badge {
            color: #fde68a;
        }

        .dark .mrr-quality-signal__badge--high {
            color: #fecaca;
        }

        .mrr-quality-signal__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            margin-top: 0.8rem;
        }

        .mrr-quality-signal__tenant {
            border-radius: 0.85rem;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.78);
            padding: 0.72rem 0.8rem;
        }

        .dark .mrr-quality-signal__tenant {
            border-color: rgba(148, 163, 184, 0.16);
            background: rgba(15, 23, 42, 0.46);
        }

        .mrr-quality-signal__tenant-label {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .dark .mrr-quality-signal__tenant-label {
            color: #94a3b8;
        }

        .mrr-quality-signal__tenant-name {
            margin-top: 0.22rem;
            font-size: 0.94rem;
            font-weight: 800;
            line-height: 1.25;
            color: #0f172a;
            word-break: break-word;
        }

        .dark .mrr-quality-signal__tenant-name {
            color: #f8fafc;
        }

        .mrr-quality-signal__tenant-meta {
            margin-top: 0.45rem;
            display: grid;
            gap: 0.15rem;
            font-size: 0.76rem;
            line-height: 1.35;
            color: #64748b;
        }

        .dark .mrr-quality-signal__tenant-meta {
            color: #cbd5e1;
        }

        .mrr-quality-signal__technical {
            margin-top: 0.35rem;
            font-size: 0.74rem;
            color: #64748b;
        }

        .mrr-quality-signal__technical summary {
            cursor: pointer;
            font-weight: 700;
        }

        .mrr-quality-signal__technical-body {
            display: grid;
            gap: 0.15rem;
            margin-top: 0.3rem;
            word-break: break-all;
        }

        .dark .mrr-quality-signal__technical {
            color: #cbd5e1;
        }

        .mrr-quality-signal__reasons {
            margin: 0.8rem 0 0;
            padding-left: 1.1rem;
            color: #475569;
            font-size: 0.82rem;
            line-height: 1.45;
        }

        .dark .mrr-quality-signal__reasons {
            color: #cbd5e1;
        }

        .mrr-quality-signal__actions,
        .mrr-hidden-pair__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            margin-top: 0.8rem;
        }

        .mrr-quality-signal__link {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(37, 99, 235, 0.18);
            background: transparent;
            padding: 0.32rem 0.66rem;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 800;
            line-height: 1.2;
            color: #1d4ed8;
            cursor: pointer;
            text-decoration: none;
        }

        .mrr-quality-signal__link--muted {
            border-color: rgba(100, 116, 139, 0.2);
            color: #475569;
        }

        .mrr-quality-signal__link:disabled {
            cursor: not-allowed;
            opacity: 0.62;
        }

        .dark .mrr-quality-signal__link {
            border-color: rgba(96, 165, 250, 0.3);
            color: #bfdbfe;
        }

        .dark .mrr-quality-signal__link--muted {
            border-color: rgba(148, 163, 184, 0.22);
            color: #cbd5e1;
        }

        .mrr-quality-signal__note {
            margin-top: 0.7rem;
            border-radius: 0.85rem;
            border: 1px solid rgba(37, 99, 235, 0.14);
            background: rgba(239, 246, 255, 0.86);
            padding: 0.58rem 0.68rem;
            font-size: 0.8rem;
            line-height: 1.45;
            color: #1e3a8a;
        }

        .mrr-quality-signal__notice {
            margin-top: 0.7rem;
            border-radius: 0.85rem;
            border: 1px solid rgba(22, 163, 74, 0.2);
            background: rgba(240, 253, 244, 0.92);
            padding: 0.58rem 0.68rem;
            font-size: 0.8rem;
            line-height: 1.45;
            color: #166534;
        }

        .mrr-quality-signal__notice.is-error {
            border-color: rgba(220, 38, 38, 0.22);
            background: rgba(254, 242, 242, 0.92);
            color: #991b1b;
        }

        .mrr-hidden-pairs {
            margin-bottom: 0.9rem;
            border-radius: 1rem;
            border: 1px solid rgba(100, 116, 139, 0.18);
            background: rgba(248, 250, 252, 0.88);
            padding: 0.75rem 0.85rem;
        }

        .mrr-hidden-pairs summary {
            cursor: pointer;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 850;
        }

        .mrr-hidden-pairs__copy {
            margin: 0.45rem 0 0;
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.45;
        }

        .mrr-hidden-pairs__list {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.7rem;
        }

        .mrr-hidden-pair {
            border-radius: 0.85rem;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.82);
            padding: 0.65rem 0.75rem;
        }

        .mrr-hidden-pair__title {
            color: #0f172a;
            font-size: 0.84rem;
            font-weight: 850;
            line-height: 1.35;
        }

        .mrr-hidden-pair__meta {
            margin-top: 0.28rem;
            color: #64748b;
            font-size: 0.74rem;
            line-height: 1.45;
        }

        .dark .mrr-quality-signal__note {
            border-color: rgba(96, 165, 250, 0.2);
            background: rgba(30, 64, 175, 0.16);
            color: #bfdbfe;
        }

        .dark .mrr-quality-signal__notice {
            border-color: rgba(74, 222, 128, 0.24);
            background: rgba(20, 83, 45, 0.2);
            color: #bbf7d0;
        }

        .dark .mrr-quality-signal__notice.is-error {
            border-color: rgba(248, 113, 113, 0.26);
            background: rgba(127, 29, 29, 0.22);
            color: #fecaca;
        }

        .dark .mrr-hidden-pairs {
            border-color: rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.42);
        }

        .dark .mrr-hidden-pairs summary,
        .dark .mrr-hidden-pair__title {
            color: #f8fafc;
        }

        .dark .mrr-hidden-pairs__copy,
        .dark .mrr-hidden-pair__meta {
            color: #cbd5e1;
        }

        .dark .mrr-hidden-pair {
            border-color: rgba(148, 163, 184, 0.16);
            background: rgba(15, 23, 42, 0.48);
        }

        @media (max-width: 760px) {
            .mrr-quality-signal__grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="aw-shell">
        <section class="aw-panel aw-panel--muted">
            <div class="aw-panel-head">
                <div>
                    <h2 class="aw-panel-title">Возможные дубли арендаторов</h2>
                    <p class="aw-panel-copy">
                        Здесь показаны арендаторы, которые могут быть заведены дважды. Проверьте пару и, если это один и тот же арендатор, объедините карточки.
                    </p>
                </div>
            </div>

            <div class="aw-panel-body">
                @if ($marketId <= 0)
                    <div class="mrr-empty">Выберите рынок, чтобы увидеть возможные дубли арендаторов.</div>
                @else
                    @if (! empty($hiddenPairs))
                        <details class="mrr-hidden-pairs">
                            <summary>Скрытые пары: {{ count($hiddenPairs) }}</summary>
                            <p class="mrr-hidden-pairs__copy">
                                Здесь пары, которые вручную отмечены как разные арендаторы. Если пару скрыли ошибочно, верните её в список проверки.
                            </p>
                            <div class="mrr-hidden-pairs__list">
                                @foreach ($hiddenPairs as $pair)
                                    @php
                                        $tenantA = is_array($pair['tenant_a'] ?? null) ? $pair['tenant_a'] : [];
                                        $tenantB = is_array($pair['tenant_b'] ?? null) ? $pair['tenant_b'] : [];
                                    @endphp
                                    <article
                                        class="mrr-hidden-pair"
                                        data-hidden-tenant-a-id="{{ (int) ($tenantA['id'] ?? 0) }}"
                                        data-hidden-tenant-b-id="{{ (int) ($tenantB['id'] ?? 0) }}"
                                    >
                                        <div class="mrr-hidden-pair__title">
                                            #{{ $tenantA['id'] ?? '—' }} · {{ $tenantA['name'] ?? 'Без названия' }} / #{{ $tenantB['id'] ?? '—' }} · {{ $tenantB['name'] ?? 'Без названия' }}
                                        </div>
                                        <div class="mrr-hidden-pair__meta">
                                            Причина: {{ $pair['reason_label'] ?? 'проверено вручную' }}
                                            @if (filled($pair['hidden_at'] ?? null))
                                                · скрыто: {{ $pair['hidden_at'] }}
                                            @endif
                                        </div>
                                        <div class="mrr-hidden-pair__actions">
                                            @if (! empty($tenantA['url']))
                                                <a class="mrr-quality-signal__link" href="{{ $tenantA['url'] }}">Открыть #{{ $tenantA['id'] }}</a>
                                            @endif
                                            @if (! empty($tenantB['url']))
                                                <a class="mrr-quality-signal__link" href="{{ $tenantB['url'] }}">Открыть #{{ $tenantB['id'] }}</a>
                                            @endif
                                            <button type="button" class="mrr-quality-signal__link" data-mrr-tenant-duplicate-restore>
                                                Вернуть в список
                                            </button>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if ($signals === [])
                        <div class="mrr-empty">Возможные дубли арендаторов сейчас не найдены.</div>
                    @else
                        <div class="mrr-quality-signals">
                            @foreach ($signals as $signal)
                                @php
                                    $candidateA = is_array($signal['candidate_a'] ?? null) ? $signal['candidate_a'] : [];
                                    $candidateB = is_array($signal['candidate_b'] ?? null) ? $signal['candidate_b'] : [];
                                    $severity = (string) ($signal['severity'] ?? 'medium');
                                @endphp

                                <article
                                    class="mrr-quality-signal"
                                    data-tenant-a-id="{{ (int) ($candidateA['id'] ?? 0) }}"
                                    data-tenant-b-id="{{ (int) ($candidateB['id'] ?? 0) }}"
                                >
                                    <div class="mrr-quality-signal__head">
                                        <h3 class="mrr-quality-signal__title">{{ $signal['title'] ?? 'Возможный дубль арендатора' }}</h3>
                                        <div class="mrr-quality-signal__meta">
                                            <span class="mrr-quality-signal__badge {{ $severity === 'high' ? 'mrr-quality-signal__badge--high' : '' }}">
                                                {{ $severity === 'high' ? 'Очень похоже на дубль' : 'Похоже на дубль' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mrr-quality-signal__grid">
                                        @foreach ([['label' => 'Карточка 1', 'tenant' => $candidateA], ['label' => 'Карточка 2', 'tenant' => $candidateB]] as $item)
                                            @php($tenant = $item['tenant'])
                                            <div class="mrr-quality-signal__tenant">
                                                <div class="mrr-quality-signal__tenant-label">{{ $item['label'] }}</div>
                                                <div class="mrr-quality-signal__tenant-name">
                                                    #{{ $tenant['id'] ?? '—' }} · {{ $tenant['name'] ?? 'Без названия' }}
                                                </div>
                                                <div class="mrr-quality-signal__tenant-meta">
                                                    <span>ИНН: {{ filled($tenant['inn'] ?? null) ? $tenant['inn'] : '—' }}</span>
                                                    @if (filled($tenant['kpp'] ?? null))
                                                        <span>КПП: {{ $tenant['kpp'] }}</span>
                                                    @endif
                                                </div>
                                                @if (filled($tenant['external_id'] ?? null) || filled($tenant['one_c_uid'] ?? null))
                                                    <details class="mrr-quality-signal__technical">
                                                        <summary>Технические данные 1С</summary>
                                                        <div class="mrr-quality-signal__technical-body">
                                                            @if (filled($tenant['external_id'] ?? null))
                                                                <span>ID из 1С: {{ $tenant['external_id'] }}</span>
                                                            @endif
                                                            @if (filled($tenant['one_c_uid'] ?? null))
                                                                <span>UID из 1С: {{ $tenant['one_c_uid'] }}</span>
                                                            @endif
                                                        </div>
                                                    </details>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>

                                    @if (! empty($signal['reasons']))
                                        <ul class="mrr-quality-signal__reasons">
                                            @foreach ($signal['reasons'] as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div class="mrr-quality-signal__actions">
                                        @if (! empty($candidateA['url']))
                                            <a class="mrr-quality-signal__link" href="{{ $candidateA['url'] }}">Открыть карточку #{{ $candidateA['id'] }}</a>
                                        @endif
                                        @if (! empty($candidateB['url']))
                                            <a class="mrr-quality-signal__link" href="{{ $candidateB['url'] }}">Открыть карточку #{{ $candidateB['id'] }}</a>
                                        @endif
                                        <button type="button" class="mrr-quality-signal__link mrr-quality-signal__link--muted" data-mrr-tenant-duplicate-ignore>
                                            Это разные арендаторы
                                        </button>
                                    </div>

                                    <div class="mrr-quality-signal__note">
                                        {{ $signal['recommendation'] ?? 'Откройте обе карточки и проверьте ИНН, договоры, начисления и торговые места.' }}
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </section>
    </div>

    @include('filament.partials.tenant-merge-preflight-actions')

    <script>
        (() => {
            const ignoreUrl = @json(route('filament.admin.tenant-duplicates.ignore'));
            const restoreUrl = @json(route('filament.admin.tenant-duplicates.restore'));
            const csrfToken = @json(csrf_token());

            const notice = (article, message, isError = false) => {
                const existing = article.querySelector('[data-mrr-tenant-duplicate-ignore-notice]');
                if (existing instanceof HTMLElement) {
                    existing.remove();
                }

                const box = document.createElement('div');
                box.className = `mrr-quality-signal__notice${isError ? ' is-error' : ''}`;
                box.setAttribute('data-mrr-tenant-duplicate-ignore-notice', '1');
                box.textContent = message;
                article.appendChild(box);
            };

            const postPair = async (url, tenantAId, tenantBId, payload = {}) => {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        tenant_a_id: tenantAId,
                        tenant_b_id: tenantBId,
                        ...payload,
                    }),
                });

                const data = await response.json().catch(() => ({}));

                return { response, data };
            };

            const ignorePair = async (article, button) => {
                const tenantAId = Number(article.dataset.tenantAId || 0);
                const tenantBId = Number(article.dataset.tenantBId || 0);

                if (tenantAId <= 0 || tenantBId <= 0 || tenantAId === tenantBId) {
                    notice(article, 'Не удалось определить пару арендаторов. Обновите страницу и попробуйте ещё раз.', true);
                    return;
                }

                const confirmed = window.confirm('Больше не показывать эту пару как дубль?');
                if (!confirmed) {
                    return;
                }

                const originalText = button.textContent;
                button.textContent = 'Скрываю…';
                button.setAttribute('disabled', 'disabled');

                try {
                    const { response, data } = await postPair(ignoreUrl, tenantAId, tenantBId, { reason: 'different_tenants' });

                    if (!response.ok || data?.ok === false) {
                        notice(article, data?.message || 'Не удалось скрыть пару. Попробуйте ещё раз.', true);
                        button.textContent = originalText;
                        button.removeAttribute('disabled');
                        return;
                    }

                    notice(article, data?.message || 'Пара скрыта из списка дублей.');
                    window.setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    notice(article, 'Не удалось скрыть пару. Проверьте соединение и попробуйте ещё раз.', true);
                    button.textContent = originalText;
                    button.removeAttribute('disabled');
                }
            };

            const restorePair = async (article, button) => {
                const tenantAId = Number(article.dataset.hiddenTenantAId || 0);
                const tenantBId = Number(article.dataset.hiddenTenantBId || 0);

                if (tenantAId <= 0 || tenantBId <= 0 || tenantAId === tenantBId) {
                    notice(article, 'Не удалось определить пару арендаторов. Обновите страницу и попробуйте ещё раз.', true);
                    return;
                }

                const confirmed = window.confirm('Вернуть эту пару в список проверки?');
                if (!confirmed) {
                    return;
                }

                const originalText = button.textContent;
                button.textContent = 'Возвращаю…';
                button.setAttribute('disabled', 'disabled');

                try {
                    const { response, data } = await postPair(restoreUrl, tenantAId, tenantBId);

                    if (!response.ok || data?.ok === false) {
                        notice(article, data?.message || 'Не удалось вернуть пару. Попробуйте ещё раз.', true);
                        button.textContent = originalText;
                        button.removeAttribute('disabled');
                        return;
                    }

                    notice(article, data?.message || 'Пара возвращена в список проверки.');
                    window.setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    notice(article, 'Не удалось вернуть пару. Проверьте соединение и попробуйте ещё раз.', true);
                    button.textContent = originalText;
                    button.removeAttribute('disabled');
                }
            };

            document.addEventListener('click', (event) => {
                const ignoreButton = event.target instanceof Element
                    ? event.target.closest('[data-mrr-tenant-duplicate-ignore]')
                    : null;
                const restoreButton = event.target instanceof Element
                    ? event.target.closest('[data-mrr-tenant-duplicate-restore]')
                    : null;

                if (ignoreButton instanceof HTMLElement) {
                    const article = ignoreButton.closest('.mrr-quality-signal');
                    if (!(article instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    ignorePair(article, ignoreButton);
                    return;
                }

                if (restoreButton instanceof HTMLElement) {
                    const article = restoreButton.closest('.mrr-hidden-pair');
                    if (!(article instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    restorePair(article, restoreButton);
                }
            });
        })();
    </script>
</x-filament::section>
