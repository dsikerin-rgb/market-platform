<x-filament-panels::page>
    <style>
        .work-progress {
            display: grid;
            gap: 1rem;
        }

        .work-progress__hero {
            border: 1px solid rgba(15, 23, 42, .10);
            border-radius: .75rem;
            background: rgba(255, 255, 255, .92);
            padding: 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__hero {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(17, 24, 39, .72);
            }
        }

        .work-progress__hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(14rem, .5fr);
            gap: 1rem;
            align-items: center;
        }

        @media (max-width: 860px) {
            .work-progress__hero-grid {
                grid-template-columns: 1fr;
            }
        }

        .work-progress__title {
            margin: 0;
            font-size: 1.35rem;
            line-height: 1.2;
            font-weight: 800;
            color: #0f172a;
        }

        .work-progress__text {
            margin-top: .5rem;
            max-width: 64rem;
            font-size: .875rem;
            line-height: 1.55;
            color: #475569;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__title {
                color: #f8fafc;
            }

            .work-progress__text {
                color: #cbd5e1;
            }
        }

        .work-progress__percent {
            display: grid;
            gap: .45rem;
        }

        .work-progress__percent-value {
            font-size: 2.4rem;
            line-height: 1;
            font-weight: 800;
            color: #0f172a;
            text-align: right;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__percent-value {
                color: #f8fafc;
            }
        }

        @media (max-width: 860px) {
            .work-progress__percent-value {
                text-align: left;
            }
        }

        .work-progress__bar {
            width: 100%;
            height: .65rem;
            border-radius: 999px;
            background: rgba(148, 163, 184, .24);
            overflow: hidden;
        }

        .work-progress__bar-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #0284c7, #16a34a);
        }

        .work-progress__meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
        }

        @media (max-width: 1024px) {
            .work-progress__meta-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .work-progress__meta-grid {
                grid-template-columns: 1fr;
            }
        }

        .work-progress__metric {
            border: 1px solid rgba(15, 23, 42, .10);
            border-radius: .75rem;
            padding: .85rem;
            background: rgba(248, 250, 252, .86);
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__metric {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(15, 23, 42, .56);
            }
        }

        .work-progress__metric-label {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #64748b;
        }

        .work-progress__metric-value {
            margin-top: .35rem;
            font-size: .92rem;
            line-height: 1.35;
            font-weight: 700;
            color: #0f172a;
            overflow-wrap: anywhere;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__metric-label {
                color: #94a3b8;
            }

            .work-progress__metric-value {
                color: #f8fafc;
            }
        }

        .work-progress__stage-grid {
            display: grid;
            gap: .75rem;
        }

        .work-progress__stage {
            border: 1px solid rgba(15, 23, 42, .10);
            border-radius: .75rem;
            background: rgba(255, 255, 255, .94);
            overflow: hidden;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__stage {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(17, 24, 39, .74);
            }
        }

        .work-progress__stage-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1rem;
            padding: .9rem 1rem;
            border-bottom: 1px solid rgba(15, 23, 42, .08);
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__stage-head {
                border-bottom-color: rgba(255, 255, 255, .10);
            }
        }

        @media (max-width: 720px) {
            .work-progress__stage-head {
                grid-template-columns: 1fr;
            }
        }

        .work-progress__stage-title {
            margin: 0;
            font-size: .98rem;
            font-weight: 800;
            color: #0f172a;
        }

        .work-progress__stage-summary {
            margin-top: .25rem;
            font-size: .78rem;
            line-height: 1.45;
            color: #64748b;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__stage-title {
                color: #f8fafc;
            }

            .work-progress__stage-summary {
                color: #94a3b8;
            }
        }

        .work-progress__stage-progress {
            min-width: 10rem;
            display: grid;
            gap: .35rem;
            align-content: start;
        }

        .work-progress__stage-percent {
            font-size: .9rem;
            font-weight: 800;
            text-align: right;
            color: #0f172a;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__stage-percent {
                color: #f8fafc;
            }
        }

        @media (max-width: 720px) {
            .work-progress__stage-percent {
                text-align: left;
            }
        }

        .work-progress__items {
            display: grid;
            gap: .45rem;
            padding: .9rem 1rem 1rem;
        }

        .work-progress__item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: .6rem;
            align-items: center;
            min-height: 2rem;
            font-size: .82rem;
            color: #334155;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__item {
                color: #d1d5db;
            }
        }

        .work-progress__check {
            width: .65rem;
            height: .65rem;
            border-radius: 999px;
            background: #cbd5e1;
        }

        .work-progress__check--done {
            background: #16a34a;
        }

        .work-progress__check--in_progress {
            background: #f59e0b;
        }

        .work-progress__check--blocked {
            background: #dc2626;
        }

        .work-progress__risk-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .75rem;
        }

        @media (max-width: 980px) {
            .work-progress__risk-grid {
                grid-template-columns: 1fr;
            }
        }

        .work-progress__risk {
            border: 1px solid rgba(15, 23, 42, .10);
            border-radius: .75rem;
            padding: .9rem;
            background: rgba(248, 250, 252, .86);
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__risk {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(15, 23, 42, .56);
            }
        }

        .work-progress__risk-title {
            font-size: .86rem;
            font-weight: 800;
            color: #0f172a;
        }

        .work-progress__risk-text {
            margin-top: .35rem;
            font-size: .76rem;
            line-height: 1.45;
            color: #64748b;
        }

        @media (prefers-color-scheme: dark) {
            .work-progress__risk-title {
                color: #f8fafc;
            }

            .work-progress__risk-text {
                color: #94a3b8;
            }
        }
    </style>

    <div class="work-progress">
        <section class="work-progress__hero">
            <div class="work-progress__hero-grid">
                <div>
                    <h1 class="work-progress__title">{{ $progress['title'] }}</h1>
                    <p class="work-progress__text">{{ $progress['subtitle'] }}</p>
                    <p class="work-progress__text">
                        Текущий фокус: {{ $progress['currentFocus'] }}
                    </p>
                </div>

                <div class="work-progress__percent">
                    <div class="work-progress__percent-value">{{ $progress['overallPercent'] }}%</div>
                    <div class="work-progress__bar" aria-label="Общий прогресс">
                        <div class="work-progress__bar-fill" style="width: {{ $progress['overallPercent'] }}%"></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="work-progress__meta-grid">
            <div class="work-progress__metric">
                <div class="work-progress__metric-label">Обновлено</div>
                <div class="work-progress__metric-value">{{ $progress['lastUpdatedAt'] ?: '—' }}</div>
            </div>

            <div class="work-progress__metric">
                <div class="work-progress__metric-label">Текущий этап</div>
                <div class="work-progress__metric-value">{{ $progress['currentStage']['title'] ?? '—' }}</div>
            </div>

            <div class="work-progress__metric">
                <div class="work-progress__metric-label">Задачи</div>
                <div class="work-progress__metric-value">
                    {{ $progress['counts']['done'] }} готово · {{ $progress['counts']['in_progress'] }} в работе · {{ $progress['counts']['pending'] }} ожидает
                </div>
            </div>

            <div class="work-progress__metric">
                <div class="work-progress__metric-label">Prod policy</div>
                <div class="work-progress__metric-value">{{ $progress['releasePolicy'] }}</div>
            </div>
        </section>

        <x-filament::section
            heading="Следующий шаг"
            description="{{ $progress['nextStep'] }}"
        />

        <section class="work-progress__stage-grid">
            @foreach ($progress['stages'] as $stage)
                <article class="work-progress__stage">
                    <header class="work-progress__stage-head">
                        <div>
                            <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                                <h2 class="work-progress__stage-title">{{ $stage['title'] }}</h2>
                                <x-filament::badge :color="$stage['statusColor']">
                                    {{ $stage['statusLabel'] }}
                                </x-filament::badge>
                            </div>
                            <p class="work-progress__stage-summary">{{ $stage['summary'] }}</p>
                        </div>

                        <div class="work-progress__stage-progress">
                            <div class="work-progress__stage-percent">{{ $stage['percent'] }}%</div>
                            <div class="work-progress__bar" aria-label="Прогресс этапа">
                                <div class="work-progress__bar-fill" style="width: {{ $stage['percent'] }}%"></div>
                            </div>
                        </div>
                    </header>

                    <div class="work-progress__items">
                        @foreach ($stage['items'] as $item)
                            <div class="work-progress__item">
                                <span class="work-progress__check work-progress__check--{{ $item['status'] }}"></span>
                                <span>{{ $item['title'] }}</span>
                                <x-filament::badge :color="$item['statusColor']" size="sm">
                                    {{ $item['statusLabel'] }}
                                </x-filament::badge>
                            </div>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>

        @if (! empty($progress['risks']))
            <x-filament::section heading="Риски и защита">
                <div class="work-progress__risk-grid">
                    @foreach ($progress['risks'] as $risk)
                        <article class="work-progress__risk">
                            <div class="work-progress__risk-title">{{ $risk['title'] ?? 'Риск' }}</div>
                            <p class="work-progress__risk-text">{{ $risk['description'] ?? '' }}</p>
                            <p class="work-progress__risk-text"><strong>Защита:</strong> {{ $risk['mitigation'] ?? '' }}</p>
                        </article>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
