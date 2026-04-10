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

            .mrr-link--button {
                background: transparent;
                appearance: none;
                cursor: pointer;
                font: inherit;
                line-height: inherit;
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

            .mrr-clarify-modal {
                position: fixed;
                inset: 0;
                z-index: 60;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }

            .mrr-clarify-modal.is-open {
                display: flex;
            }

            .mrr-clarify-modal__backdrop {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(4px);
            }

            .mrr-clarify-modal__dialog {
                position: relative;
                width: min(560px, 100%);
                border-radius: 1.25rem;
                border: 1px solid rgba(148, 163, 184, 0.24);
                background: rgba(255, 255, 255, 0.98);
                box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
                padding: 1.25rem;
                display: flex;
                flex-direction: column;
                gap: 0.95rem;
            }

            .dark .mrr-clarify-modal__dialog {
                background: rgba(15, 23, 42, 0.98);
                border-color: rgba(148, 163, 184, 0.24);
            }

            .mrr-clarify-modal__eyebrow {
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-clarify-modal__eyebrow {
                color: #94a3b8;
            }

            .mrr-clarify-modal__title {
                margin: 0;
                font-size: 1.1rem;
                line-height: 1.3;
                color: #0f172a;
            }

            .dark .mrr-clarify-modal__title {
                color: #f8fafc;
            }

            .mrr-clarify-modal__description {
                margin: 0;
                font-size: 0.9rem;
                line-height: 1.5;
                color: #475569;
            }

            .dark .mrr-clarify-modal__description {
                color: #cbd5e1;
            }

            .mrr-clarify-modal__label {
                font-size: 0.82rem;
                font-weight: 700;
                color: #334155;
            }

            .dark .mrr-clarify-modal__label {
                color: #e2e8f0;
            }

            .mrr-clarify-modal__input {
                width: 100%;
                box-sizing: border-box;
                border-radius: 0.9rem;
                border: 1px solid rgba(148, 163, 184, 0.38);
                background: #fff;
                color: #0f172a;
                padding: 0.85rem 0.95rem;
                font-size: 0.95rem;
                outline: none;
            }

            .dark .mrr-clarify-modal__input {
                background: rgba(15, 23, 42, 0.96);
                border-color: rgba(148, 163, 184, 0.34);
                color: #f8fafc;
            }

            .mrr-clarify-modal__input:focus {
                border-color: rgba(37, 99, 235, 0.85);
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
            }

            .mrr-clarify-modal__error {
                min-height: 1.1rem;
                font-size: 0.82rem;
                color: #b91c1c;
            }

            .dark .mrr-clarify-modal__error {
                color: #f87171;
            }

            .mrr-clarify-modal__actions {
                display: flex;
                flex-wrap: wrap;
                justify-content: flex-end;
                gap: 0.55rem;
            }

            .mrr-clarify-modal__button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.35rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.1);
                padding: 0.45rem 0.78rem;
                font-size: 0.85rem;
                font-weight: 700;
                color: #0f172a;
                background: rgba(255, 255, 255, 0.95);
                cursor: pointer;
                appearance: none;
            }

            .dark .mrr-clarify-modal__button {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(15, 23, 42, 0.92);
                color: #f8fafc;
            }

            .mrr-clarify-modal__button--primary {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            }

            .dark .mrr-clarify-modal__button--primary {
                background: #2563eb;
                border-color: #2563eb;
            }

            .mrr-clarify-modal__close {
                position: absolute;
                top: 0.75rem;
                right: 0.75rem;
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

            .dark .mrr-clarify-modal__close {
                background: rgba(15, 23, 42, 0.92);
                color: #cbd5e1;
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

            .mrr-sort-toggle {
                display: inline-flex;
                flex-wrap: wrap;
                gap: 0.45rem;
                margin-top: 0.85rem;
                padding: 0.25rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: rgba(248, 250, 252, 0.85);
                width: fit-content;
            }

            .dark .mrr-sort-toggle {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(15, 23, 42, 0.42);
            }

            .mrr-sort-toggle__link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                border: 1px solid transparent;
                padding: 0.42rem 0.8rem;
                font-size: 0.8125rem;
                font-weight: 700;
                line-height: 1.15;
                color: #475569;
                text-decoration: none;
                transition:
                    background-color 0.16s ease,
                    border-color 0.16s ease,
                    color 0.16s ease,
                    box-shadow 0.16s ease;
            }

            .mrr-sort-toggle__link:hover {
                color: #0f172a;
                background: rgba(255, 255, 255, 0.92);
                border-color: rgba(15, 23, 42, 0.08);
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }

            .dark .mrr-sort-toggle__link {
                color: #cbd5e1;
            }

            .dark .mrr-sort-toggle__link:hover {
                color: #f8fafc;
                background: rgba(30, 41, 59, 0.88);
                border-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-sort-toggle__link.is-active {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            }

            .mrr-sort-toggle__link.is-active:hover {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
            }

            .dark .mrr-sort-toggle__link.is-active {
                background: #e2e8f0;
                border-color: #e2e8f0;
                color: #0f172a;
            }

            .dark .mrr-sort-toggle__link.is-active:hover {
                background: #f8fafc;
                border-color: #f8fafc;
                color: #0f172a;
            }

            .mrr-row--priority td {
                background: rgba(37, 99, 235, 0.045);
            }

            .mrr-row--priority td:first-child {
                box-shadow: inset 3px 0 0 rgba(37, 99, 235, 0.22);
            }

            .dark .mrr-row--priority td {
                background: rgba(37, 99, 235, 0.085);
            }

            .dark .mrr-row--priority td:first-child {
                box-shadow: inset 3px 0 0 rgba(96, 165, 250, 0.28);
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

            .mrr-ai__priority {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem 0.5rem;
                margin-bottom: 0.45rem;
                padding: 0.45rem 0.55rem;
                border-radius: 0.9rem;
                background: rgba(248, 250, 252, 0.92);
                border: 1px solid rgba(15, 23, 42, 0.08);
            }

            .dark .mrr-ai__priority {
                background: rgba(15, 23, 42, 0.38);
                border-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-ai__priority--high {
                background: rgba(239, 68, 68, 0.08);
                border-color: rgba(239, 68, 68, 0.18);
            }

            .dark .mrr-ai__priority--high {
                background: rgba(239, 68, 68, 0.12);
                border-color: rgba(248, 113, 113, 0.2);
            }

            .mrr-ai__priority--medium {
                background: rgba(245, 158, 11, 0.08);
                border-color: rgba(245, 158, 11, 0.18);
            }

            .dark .mrr-ai__priority--medium {
                background: rgba(245, 158, 11, 0.12);
                border-color: rgba(251, 191, 36, 0.2);
            }

            .mrr-ai__priority--normal {
                background: rgba(148, 163, 184, 0.08);
                border-color: rgba(148, 163, 184, 0.14);
            }

            .dark .mrr-ai__priority--normal {
                background: rgba(148, 163, 184, 0.1);
                border-color: rgba(148, 163, 184, 0.18);
            }

            .mrr-ai__priority-label {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                padding: 0.24rem 0.55rem;
                font-size: 0.75rem;
                font-weight: 800;
                line-height: 1.2;
                letter-spacing: 0.01em;
                background: #fff;
                color: #0f172a;
                border: 1px solid rgba(15, 23, 42, 0.08);
            }

            .dark .mrr-ai__priority-label {
                background: rgba(15, 23, 42, 0.48);
                color: #f8fafc;
                border-color: rgba(148, 163, 184, 0.18);
            }

            .mrr-ai__priority--high .mrr-ai__priority-label {
                color: #b91c1c;
                border-color: rgba(239, 68, 68, 0.2);
            }

            .dark .mrr-ai__priority--high .mrr-ai__priority-label {
                color: #fecaca;
                border-color: rgba(248, 113, 113, 0.24);
            }

            .mrr-ai__priority--medium .mrr-ai__priority-label {
                color: #b45309;
                border-color: rgba(245, 158, 11, 0.22);
            }

            .dark .mrr-ai__priority--medium .mrr-ai__priority-label {
                color: #fde68a;
                border-color: rgba(251, 191, 36, 0.24);
            }

            .mrr-ai__priority--normal .mrr-ai__priority-label {
                color: #334155;
            }

            .dark .mrr-ai__priority--normal .mrr-ai__priority-label {
                color: #e2e8f0;
            }

            .mrr-ai__priority-score {
                font-size: 0.75rem;
                font-weight: 700;
                color: #64748b;
            }

            .dark .mrr-ai__priority-score {
                color: #94a3b8;
            }

            .mrr-ai__priority-reason {
                margin-bottom: 0.4rem;
                font-size: 0.75rem;
                line-height: 1.45;
                color: #64748b;
            }

            .dark .mrr-ai__priority-reason {
                color: #94a3b8;
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
                                <div class="mrr-sort-toggle">
                                    <a
                                        class="mrr-sort-toggle__link {{ $needsAttentionSortMode === 'default' ? 'is-active' : '' }}"
                                        href="{{ $needsAttentionSortDefaultUrl }}"
                                    >
                                        Обычный порядок
                                    </a>
                                    <a
                                        class="mrr-sort-toggle__link {{ $needsAttentionSortMode === 'ai_priority' ? 'is-active' : '' }}"
                                        href="{{ $needsAttentionSortAiUrl }}"
                                    >
                                        AI-приоритет
                                    </a>
                                </div>
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
                                                    $priorityTone = $row['priority_score'] >= 85
                                                        ? 'high'
                                                        : ($row['priority_score'] >= 65 ? 'medium' : 'normal');
                                                @endphp
                                                <tr class="{{ $row['priority_is_high'] ? 'mrr-row--priority' : '' }}">
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
                                                            @if (($row['decision'] ?? null) === 'space_identity_needs_clarification')
                                                                <button
                                                                    type="button"
                                                                    class="mrr-link mrr-link--button"
                                                                    data-mrr-clarify-action="open"
                                                                    data-space-id="{{ $row['space_id'] }}"
                                                                    data-space-number="{{ $row['number'] ?? '' }}"
                                                                    data-space-display-name="{{ $row['display_name'] ?? '' }}"
                                                                    title="Применить безопасное уточнение номера или названия места"
                                                                    aria-label="Применить уточнение"
                                                                >
                                                                    Применить уточнение
                                                                </button>
                                                            @endif
                                                            <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        @php
                                                            $hasAiKey = array_key_exists($row['space_id'], $aiSummaries);

                                                            // Функция для замены технических кодов на русский текст
                                                            $humanize = function(?string $text): string {
                                                                if (blank($text)) return '';
                                                                $map = [
                                                                    'occupancy_conflict'     => 'конфликт по занятости',
                                                                    'tenant_changed_on_site' => 'на месте другой арендатор',
                                                                    'shape_not_found'        => 'место не найдено на карте',
                                                                    'mark_space_free'        => 'отметить место как свободное',
                                                                    'mark_space_service'     => 'отметить место как служебное',
                                                                    'fix_space_identity'     => 'уточнить номер и название',
                                                                    'bind_shape_to_space'    => 'привязать фигуру к месту',
                                                                    'unbind_shape_from_space'=> 'отвязать фигуру',
                                                                ];
                                                                $text = str_replace(array_keys($map), array_values($map), $text);
                                                                return $text;
                                                            };
                                                        @endphp
                                                        <div class="mrr-ai">
                                                            <div class="mrr-ai__priority mrr-ai__priority--{{ $priorityTone }}">
                                                                <span class="mrr-ai__priority-label">{{ $row['priority_label'] }}</span>
                                                                <span class="mrr-ai__priority-score">Приоритет {{ $row['priority_score'] }}/100</span>
                                                            </div>
                                                            <div class="mrr-ai__priority-reason">{{ $humanize($row['priority_reason']) }}</div>
                                                            @if ($ai && filled($ai['summary']))
                                                                <div class="mrr-ai__summary">{{ $humanize($ai['summary']) }}</div>
                                                                <div class="mrr-ai__reason">
                                                                    <strong>Почему:</strong> {{ $humanize($ai['why_flagged']) }}
                                                                </div>
                                                                <div class="mrr-ai__step">
                                                                    <strong>Что сделать:</strong> {{ $humanize($ai['recommended_next_step']) }}
                                                                </div>
                                                                <div class="mrr-ai__badges">
                                                                    <span class="mrr-ai__badge mrr-ai__badge--risk" title="Риск {{ $ai['risk_score'] }}/10">
                                                                        ⚠ {{ $ai['risk_score'] }}/10
                                                                    </span>
                                                                    <span class="mrr-ai__badge mrr-ai__badge--conf" title="Уверенность {{ round($ai['confidence'] * 100) }}%">
                                                                        🎯 {{ round($ai['confidence'] * 100) }}%
                                                                    </span>
                                                                </div>
                                                            @elseif ($hasAiKey)
                                                                <div class="mrr-ai mrr-ai--empty">
                                                                    <span class="mrr-ai__placeholder">AI-анализ недоступен</span>
                                                                </div>
                                                            @elseif (empty($aiSummaries))
                                                                <div class="mrr-ai mrr-ai--empty">
                                                                    <span class="mrr-ai__placeholder">AI-сводка временно недоступна</span>
                                                                </div>
                                                            @else
                                                                <div class="mrr-ai mrr-ai--skipped">
                                                                    <span class="mrr-ai__placeholder">AI-разбор показан для первых 5 мест</span>
                                                                </div>
                                                            @endif
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

                <div id="mrrClarifyModal" class="mrr-clarify-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-clarify-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrClarifyTitle"
                        aria-describedby="mrrClarifyDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-clarify-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Нужно уточнить</div>
                        <h3 id="mrrClarifyTitle" class="mrr-clarify-modal__title">Применить уточнение</h3>
                        <p id="mrrClarifyDescription" class="mrr-clarify-modal__description">
                            Введите, как это место обозначено на схеме, вывеске или на самом месте.
                        </p>

                        <label class="mrr-clarify-modal__label" for="mrrClarifyInput">Номер или название места</label>
                        <input
                            id="mrrClarifyInput"
                            class="mrr-clarify-modal__input"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            inputmode="text"
                        >
                        <div id="mrrClarifyError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-clarify-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-clarify-save>Сохранить</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <script>
            (() => {
                const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const modal = document.getElementById('mrrClarifyModal');
                const input = document.getElementById('mrrClarifyInput');
                const error = document.getElementById('mrrClarifyError');

                if (!modal || !input || !error) {
                    return;
                }

                const openModal = (button) => {
                    modal.hidden = false;
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    modal.dataset.spaceId = String(button.dataset.spaceId || '');
                    input.value = button.dataset.spaceNumber || button.dataset.spaceDisplayName || '';
                    error.textContent = '';

                    requestAnimationFrame(() => {
                        input.focus({ preventScroll: true });
                        input.select();
                    });
                };

                const closeModal = () => {
                    modal.classList.remove('is-open');
                    modal.hidden = true;
                    modal.setAttribute('aria-hidden', 'true');
                    delete modal.dataset.spaceId;
                    error.textContent = '';
                };

                const save = async () => {
                    const spaceId = Number(modal.dataset.spaceId || 0);
                    const value = String(input.value || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        error.textContent = 'Не удалось определить место.';
                        return;
                    }

                    if (!value) {
                        error.textContent = 'Нужен номер или название места.';
                        input.focus({ preventScroll: true });
                        return;
                    }

                    error.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: 'fix_space_identity',
                            market_space_id: spaceId,
                            number: value,
                            display_name: value,
                        }),
                    });

                    let json = null;
                    try {
                        json = await response.json();
                    } catch (e) {
                        json = null;
                    }

                    if (!response.ok || !json || json.ok !== true) {
                        error.textContent = String(json?.message || 'Не удалось применить уточнение.');
                        return;
                    }

                    window.location.reload();
                };

                document.addEventListener('click', (event) => {
                    const button = event.target instanceof Element
                        ? event.target.closest('[data-mrr-clarify-action="open"]')
                        : null;

                    if (!button || !(button instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    openModal(button);
                });

                modal.addEventListener('click', (event) => {
                    if (!(event.target instanceof Element)) {
                        return;
                    }

                    if (event.target.hasAttribute('data-mrr-clarify-close')) {
                        event.preventDefault();
                        closeModal();
                        return;
                    }

                    if (event.target.hasAttribute('data-mrr-clarify-save')) {
                        event.preventDefault();
                        save().catch((errorInstance) => {
                            error.textContent = String(errorInstance?.message || errorInstance);
                        });
                    }
                });

                input.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') {
                        return;
                    }

                    event.preventDefault();
                    save().catch((errorInstance) => {
                        error.textContent = String(errorInstance?.message || errorInstance);
                    });
                });

                window.addEventListener('keydown', (event) => {
                    if (!modal.classList.contains('is-open')) {
                        return;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeModal();
                    }
                });
            })();
        </script>
    </div>
</x-filament-panels::page>
