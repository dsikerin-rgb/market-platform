<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    @once
        <style>
            .mrr-table-wrap {
                overflow-x: auto;
            }

            .mrr-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9375rem;
            }

            .mrr-table th,
            .mrr-table td {
                padding: 0.85rem 0.9rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                text-align: left;
                vertical-align: top;
            }

            .dark .mrr-table th,
            .dark .mrr-table td {
                border-bottom-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-table th {
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-table th {
                color: #94a3b8;
            }

            .mrr-place {
                display: flex;
                flex-direction: column;
                gap: 0.18rem;
            }

            .mrr-place__title {
                font-weight: 700;
                color: #0f172a;
            }

            .dark .mrr-place__title {
                color: #f8fafc;
            }

            .mrr-place__meta {
                font-size: 0.8125rem;
                color: #64748b;
            }

            .dark .mrr-place__meta {
                color: #94a3b8;
            }

            .mrr-badge {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                padding: 0.22rem 0.6rem;
                font-size: 0.75rem;
                font-weight: 700;
                line-height: 1.25;
                white-space: nowrap;
            }

            .mrr-badge--matched {
                background: rgba(34, 197, 94, 0.14);
                color: #15803d;
            }

            .mrr-badge--changed {
                background: rgba(59, 130, 246, 0.14);
                color: #1d4ed8;
            }

            .mrr-badge--changed_tenant,
            .mrr-badge--conflict,
            .mrr-badge--not_found {
                background: rgba(239, 68, 68, 0.14);
                color: #b91c1c;
            }

            .mrr-links {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .mrr-link {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.1);
                padding: 0.42rem 0.72rem;
                font-size: 0.8125rem;
                font-weight: 600;
                color: #0f172a;
                text-decoration: none;
            }

            .dark .mrr-link {
                border-color: rgba(148, 163, 184, 0.18);
                color: #f8fafc;
            }

            .mrr-empty {
                border-radius: 1rem;
                border: 1px dashed rgba(15, 23, 42, 0.14);
                padding: 1rem 1.1rem;
                color: #64748b;
            }

            .dark .mrr-empty {
                border-color: rgba(148, 163, 184, 0.2);
                color: #94a3b8;
            }

            .mrr-progress-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .mrr-progress-bar {
                height: 0.75rem;
                border-radius: 999px;
                background: rgba(148, 163, 184, 0.2);
                overflow: hidden;
            }

            .mrr-progress-bar > span {
                display: block;
                height: 100%;
                border-radius: inherit;
                background: linear-gradient(90deg, #2563eb 0%, #16a34a 100%);
            }

            .mrr-chip-row {
                display: flex;
                flex-wrap: wrap;
                gap: 0.65rem;
            }

            .mrr-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.08);
                padding: 0.45rem 0.7rem;
                font-size: 0.8125rem;
                color: #334155;
            }

            .dark .mrr-chip {
                border-color: rgba(148, 163, 184, 0.16);
                color: #cbd5e1;
            }

            .mrr-chip strong {
                color: #0f172a;
            }

            .dark .mrr-chip strong {
                color: #f8fafc;
            }

            @media (max-width: 1024px) {
                .mrr-progress-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 640px) {
                .mrr-progress-grid {
                    grid-template-columns: 1fr;
                }

                .mrr-table th,
                .mrr-table td {
                    padding-inline: 0.7rem;
                }
            }

            /* AI-разбор колонка */
            .mrr-ai {
                max-width: 320px;
            }

            .mrr-ai__summary {
                font-size: 0.8125rem;
                color: #475569;
                line-height: 1.45;
                margin-bottom: 0.4rem;
            }

            .dark .mrr-ai__summary {
                color: #cbd5e1;
            }

            .mrr-ai__reason {
                font-size: 0.75rem;
                color: #64748b;
                line-height: 1.4;
                margin-bottom: 0.35rem;
            }

            .dark .mrr-ai__reason {
                color: #94a3b8;
            }

            .mrr-ai__reason strong {
                color: #334155;
            }

            .dark .mrr-ai__reason strong {
                color: #e2e8f0;
            }

            .mrr-ai__step {
                font-size: 0.75rem;
                color: #64748b;
                line-height: 1.4;
                margin-bottom: 0.4rem;
            }

            .dark .mrr-ai__step {
                color: #94a3b8;
            }

            .mrr-ai__step strong {
                color: #334155;
            }

            .dark .mrr-ai__step strong {
                color: #e2e8f0;
            }

            .mrr-ai__badges {
                display: flex;
                gap: 0.35rem;
                flex-wrap: wrap;
            }

            .mrr-ai__badge {
                display: inline-flex;
                align-items: center;
                gap: 0.2rem;
                border-radius: 999px;
                padding: 0.2rem 0.5rem;
                font-size: 0.6875rem;
                font-weight: 600;
                line-height: 1;
            }

            .mrr-ai__badge--risk {
                background: rgba(234, 88, 12, 0.1);
                color: #ea580c;
            }

            .dark .mrr-ai__badge--risk {
                background: rgba(234, 88, 12, 0.15);
                color: #fb923c;
            }

            .mrr-ai__badge--conf {
                background: rgba(37, 99, 235, 0.1);
                color: #2563eb;
            }

            .dark .mrr-ai__badge--conf {
                background: rgba(37, 99, 235, 0.15);
                color: #93c5fd;
            }

            .mrr-ai--empty {
                font-size: 0.75rem;
                color: #94a3b8;
                font-style: italic;
            }

            .mrr-ai--skipped {
                font-size: 0.6875rem;
                color: #94a3b8;
            }

            .mrr-ai__placeholder {
                color: #94a3b8;
            }

            .dark .mrr-ai__placeholder {
                color: #64748b;
            }
        </style>
    @endonce

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Результаты ревизии</h1>
                            <p class="aw-hero-subheading">
                                Read-only сводка по карте и ревизионным решениям без захода в сырой журнал операций.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рынок</div>
                        <div class="aw-stat-value" style="font-size: 1.15rem;">
                            {{ $marketName }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Проверено</div>
                        <div class="aw-stat-value">{{ number_format($progress['reviewed'], 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Осталось</div>
                        <div class="aw-stat-value">{{ number_format($progress['remaining'], 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Готовность</div>
                        <div class="aw-stat-value">{{ $progress['percent'] }}%</div>
                    </div>
                </div>
            </div>
        </section>

        @if (! $hasSelectedMarket)
            <section class="aw-panel">
                <div class="aw-panel-body">
                    <div class="mrr-empty">
                        Для страницы результатов ревизии нужно выбрать рынок в текущей admin-session.
                    </div>
                </div>
            </section>
        @else
            <div class="aw-grid">
                <div class="aw-column">
                    <section class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h2 class="aw-panel-title">Общий прогресс</h2>
                                <p class="aw-panel-copy">Статус ревизии по активным местам выбранного рынка.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            <div class="mrr-progress-grid">
                                <div class="aw-stat-card">
                                    <div class="aw-stat-label">Всего мест</div>
                                    <div class="aw-stat-value">{{ number_format($progress['total'], 0, ',', ' ') }}</div>
                                </div>

                                <div class="aw-stat-card">
                                    <div class="aw-stat-label">Проверено</div>
                                    <div class="aw-stat-value">{{ number_format($progress['reviewed'], 0, ',', ' ') }}</div>
                                </div>

                                <div class="aw-stat-card">
                                    <div class="aw-stat-label">Осталось</div>
                                    <div class="aw-stat-value">{{ number_format($progress['remaining'], 0, ',', ' ') }}</div>
                                </div>

                                <div class="aw-stat-card">
                                    <div class="aw-stat-label">Процент</div>
                                    <div class="aw-stat-value">{{ $progress['percent'] }}%</div>
                                </div>
                            </div>

                            <div style="margin-top: 1rem;">
                                <div class="mrr-progress-bar" aria-hidden="true">
                                    <span style="width: {{ $progress['percent'] }}%;"></span>
                                </div>
                            </div>

                            <div class="mrr-chip-row" style="margin-top: 1rem;">
                                @forelse ($progress['counts'] as $status => $count)
                                    <div class="mrr-chip">
                                        <strong>{{ $progress['labels'][$status] ?? $status }}</strong>
                                        <span>{{ number_format($count, 0, ',', ' ') }}</span>
                                    </div>
                                @empty
                                    <div class="mrr-empty" style="width: 100%;">
                                        Ревизионных отметок по выбранному рынку пока нет.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </section>

                    <section class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h2 class="aw-panel-title">Нужно уточнить</h2>
                                <p class="aw-panel-copy">Места со спорным или незавершённым ревизионным результатом.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            @if ($needsAttention === [])
                                <div class="mrr-empty">Сейчас нет мест, требующих уточнения.</div>
                            @else
                                <div class="mrr-table-wrap">
                                    <table class="mrr-table">
                                        <thead>
                                            <tr>
                                                <th>Место</th>
                                                <th>Статус</th>
                                                <th>Последнее решение</th>
                                                <th>Кем и когда</th>
                                                <th>Переходы</th>
                                                <th>AI-разбор</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($needsAttention as $row)
                                                @php
                                                    $ai = $aiSummaries[$row['space_id']] ?? null;
                                                @endphp
                                                <tr>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">
                                                                {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                            </div>
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="mrr-badge mrr-badge--{{ $row['review_status'] }}">
                                                            {{ $row['review_status_label'] ?? '—' }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['decision_label'] ?? '—' }}</div>
                                                            @if (filled($row['reason']))
                                                                <div class="mrr-place__meta">{{ $row['reason'] }}</div>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['reviewed_by_name'] ?: '—' }}</div>
                                                            <div class="mrr-place__meta">{{ $row['reviewed_at'] ?: '—' }}</div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-links">
                                                            <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        @php
                                                            $hasAiKey = array_key_exists($row['space_id'], $aiSummaries);
                                                            $ai = $hasAiKey ? $aiSummaries[$row['space_id']] : null;
                                                        @endphp
                                                        @if ($ai && filled($ai['summary']))
                                                            <div class="mrr-ai">
                                                                <div class="mrr-ai__summary">{{ $ai['summary'] }}</div>
                                                                <div class="mrr-ai__reason">
                                                                    <strong>Почему:</strong> {{ $ai['why_flagged'] }}
                                                                </div>
                                                                <div class="mrr-ai__step">
                                                                    <strong>Действие:</strong> {{ $ai['recommended_next_step'] }}
                                                                </div>
                                                                <div class="mrr-ai__badges">
                                                                    <span class="mrr-ai__badge mrr-ai__badge--risk" title="Риск {{ $ai['risk_score'] }}/10">
                                                                        ⚠ {{ $ai['risk_score'] }}/10
                                                                    </span>
                                                                    <span class="mrr-ai__badge mrr-ai__badge--conf" title="Уверенность {{ round($ai['confidence'] * 100) }}%">
                                                                        🎯 {{ round($ai['confidence'] * 100) }}%
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        @elseif ($hasAiKey)
                                                            <div class="mrr-ai mrr-ai--empty">
                                                                <span class="mrr-ai__placeholder">AI-анализ недоступен</span>
                                                            </div>
                                                        @else
                                                            <div class="mrr-ai mrr-ai--skipped">
                                                                <span class="mrr-ai__placeholder">AI-разбор показан для первых 5 мест</span>
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="aw-panel">
                        <div class="aw-panel-head">
                            <div>
                                <h2 class="aw-panel-title">Применено</h2>
                                <p class="aw-panel-copy">Безопасные изменения, уже прошедшие через SPACE_REVIEW.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            @if ($appliedChanges === [])
                                <div class="mrr-empty">Применённых ревизионных изменений по выбранному рынку пока нет.</div>
                            @else
                                <div class="mrr-table-wrap">
                                    <table class="mrr-table">
                                        <thead>
                                            <tr>
                                                <th>Место</th>
                                                <th>Что применено</th>
                                                <th>Детали</th>
                                                <th>Кем и когда</th>
                                                <th>Переходы</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($appliedChanges as $row)
                                                <tr>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">
                                                                {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                            </div>
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['decision_label'] }}</div>
                                                            @if (filled($row['review_status_label']))
                                                                <div class="mrr-place__meta">{{ $row['review_status_label'] }}</div>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td>{{ $row['summary'] }}</td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['created_by_name'] ?: '—' }}</div>
                                                            <div class="mrr-place__meta">{{ $row['effective_at'] ?: '—' }}</div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-links">
                                                            <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
