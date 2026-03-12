<x-filament::section>
    <style>
        .market-attention-widget {
            position: relative;
        }

        .market-attention-widget__surface {
            position: relative;
            overflow: hidden;
            border-radius: 1.5rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background:
                radial-gradient(circle at top right, rgba(251, 191, 36, 0.12), transparent 32%),
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.10), transparent 28%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.88));
            padding: 1.25rem;
        }

        .dark .market-attention-widget__surface {
            border-color: rgba(71, 85, 105, 0.45);
            background:
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.12), transparent 30%),
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.10), transparent 26%),
                linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(17, 24, 39, 0.96));
        }

        .market-attention-widget__mesh {
            pointer-events: none;
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
            background-size: 24px 24px;
            mask-image: linear-gradient(180deg, rgba(255, 255, 255, 0.7), transparent 80%);
            opacity: 0.45;
        }

        .market-attention-widget__heading {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .market-attention-widget__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .market-attention-widget__pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            border-radius: 9999px;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .market-attention-widget__card {
            position: relative;
            overflow: hidden;
            border-radius: 1.25rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(255, 255, 255, 0.84);
            backdrop-filter: blur(10px);
            transition:
                transform 160ms ease,
                border-color 160ms ease,
                box-shadow 160ms ease,
                background-color 160ms ease;
        }

        .market-attention-widget__card:hover {
            transform: translateY(-2px);
            border-color: rgba(245, 158, 11, 0.34);
            box-shadow: 0 16px 40px -28px rgba(15, 23, 42, 0.45);
        }

        .dark .market-attention-widget__card {
            border-color: rgba(71, 85, 105, 0.45);
            background: rgba(15, 23, 42, 0.78);
        }

        .market-attention-widget__card:hover .market-attention-widget__cta-icon {
            transform: translate(2px, -2px);
        }

        .market-attention-widget__card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 0.28rem;
            background: var(--attention-accent, #94a3b8);
        }

        .market-attention-widget__glow {
            position: absolute;
            top: -2rem;
            right: -2rem;
            width: 7rem;
            height: 7rem;
            border-radius: 9999px;
            background: var(--attention-glow, rgba(148, 163, 184, 0.18));
            filter: blur(22px);
            opacity: 0.9;
        }

        .market-attention-widget__value {
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .market-attention-widget__empty {
            border-radius: 1.25rem;
            border: 1px dashed rgba(34, 197, 94, 0.35);
            background: rgba(34, 197, 94, 0.06);
            padding: 1.25rem;
        }

        .dark .market-attention-widget__empty {
            border-color: rgba(34, 197, 94, 0.28);
            background: rgba(21, 128, 61, 0.12);
        }
    </style>

    @php
        $signalsCount = (int) ($signalsCount ?? count($items ?? []));
        $signalsLabel = match (true) {
            $signalsCount % 10 === 1 && $signalsCount % 100 !== 11 => 'сигнал',
            in_array($signalsCount % 10, [2, 3, 4], true) && ! in_array($signalsCount % 100, [12, 13, 14], true) => 'сигнала',
            default => 'сигналов',
        };
    @endphp

    <div class="market-attention-widget">
        <div class="market-attention-widget__surface">
            <div class="market-attention-widget__mesh"></div>

            <div class="relative z-10 space-y-5">
                <div class="market-attention-widget__heading">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-warning-500/10 text-warning-600 ring-1 ring-inset ring-warning-500/15 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/20">
                                <x-filament::icon icon="heroicon-m-shield-exclamation" class="h-6 w-6" />
                            </div>

                            <div class="space-y-1">
                                <div class="text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                                    Требует внимания
                                </div>

                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    @isset($marketName)
                                        Рынок: {{ $marketName }}
                                    @else
                                        Критичные сигналы по текущему контуру рынка
                                    @endisset
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="market-attention-widget__meta">
                        <span class="market-attention-widget__pill bg-gray-950 text-white dark:bg-white dark:text-gray-950">
                            <x-filament::icon icon="heroicon-m-bell-alert" class="h-4 w-4" />
                            {{ $signalsCount }} {{ $signalsLabel }}
                        </span>

                        @if ($signalsCount > 0)
                            <span class="market-attention-widget__pill bg-warning-500/12 text-warning-700 ring-1 ring-inset ring-warning-500/20 dark:bg-warning-400/12 dark:text-warning-200 dark:ring-warning-400/20">
                                <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4" />
                                Требуется действие
                            </span>
                        @endif
                    </div>
                </div>

                @if ($items === [])
                    <div class="market-attention-widget__empty relative z-10">
                        <div class="flex items-start gap-4">
                            <div class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-success-500/12 text-success-600 ring-1 ring-inset ring-success-500/20 dark:bg-success-400/12 dark:text-success-300 dark:ring-success-400/20">
                                <x-filament::icon icon="heroicon-m-check-badge" class="h-6 w-6" />
                            </div>

                            <div class="space-y-1">
                                <div class="text-base font-semibold text-success-800 dark:text-success-200">
                                    {{ $emptyHeading ?? 'Критичных сигналов нет' }}
                                </div>

                                @if (filled($emptyDescription ?? null))
                                    <div class="text-sm leading-6 text-success-700/90 dark:text-success-200/80">
                                        {{ $emptyDescription }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($items as $item)
                            @php
                                $tone = $item['tone'] ?? 'gray';

                                $accentClasses = match ($tone) {
                                    'danger' => [
                                        'chip' => 'bg-danger-500/12 text-danger-700 ring-danger-500/20 dark:bg-danger-400/12 dark:text-danger-200 dark:ring-danger-400/20',
                                        'status' => 'bg-danger-500/10 text-danger-700 dark:bg-danger-400/10 dark:text-danger-200',
                                        'cta' => 'bg-danger-600 text-white hover:bg-danger-500 dark:bg-danger-500 dark:hover:bg-danger-400',
                                        'icon' => 'text-danger-600 dark:text-danger-300',
                                        'style' => '--attention-accent:#ef4444;--attention-glow:rgba(239,68,68,0.18);',
                                    ],
                                    'warning' => [
                                        'chip' => 'bg-warning-500/12 text-warning-700 ring-warning-500/20 dark:bg-warning-400/12 dark:text-warning-200 dark:ring-warning-400/20',
                                        'status' => 'bg-warning-500/10 text-warning-700 dark:bg-warning-400/10 dark:text-warning-200',
                                        'cta' => 'bg-warning-500 text-gray-950 hover:bg-warning-400 dark:bg-warning-400 dark:hover:bg-warning-300',
                                        'icon' => 'text-warning-600 dark:text-warning-300',
                                        'style' => '--attention-accent:#f59e0b;--attention-glow:rgba(245,158,11,0.20);',
                                    ],
                                    'success' => [
                                        'chip' => 'bg-success-500/12 text-success-700 ring-success-500/20 dark:bg-success-400/12 dark:text-success-200 dark:ring-success-400/20',
                                        'status' => 'bg-success-500/10 text-success-700 dark:bg-success-400/10 dark:text-success-200',
                                        'cta' => 'bg-success-600 text-white hover:bg-success-500 dark:bg-success-500 dark:hover:bg-success-400',
                                        'icon' => 'text-success-600 dark:text-success-300',
                                        'style' => '--attention-accent:#22c55e;--attention-glow:rgba(34,197,94,0.18);',
                                    ],
                                    default => [
                                        'chip' => 'bg-gray-500/10 text-gray-700 ring-gray-500/15 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/15',
                                        'status' => 'bg-gray-500/10 text-gray-700 dark:bg-gray-400/10 dark:text-gray-200',
                                        'cta' => 'bg-gray-900 text-white hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-100',
                                        'icon' => 'text-gray-700 dark:text-gray-200',
                                        'style' => '--attention-accent:#94a3b8;--attention-glow:rgba(148,163,184,0.18);',
                                    ],
                                };
                            @endphp

                            <a
                                href="{{ $item['action_url'] }}"
                                class="market-attention-widget__card group block p-5 no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                style="{{ $accentClasses['style'] }}"
                            >
                                <span class="market-attention-widget__glow"></span>

                                <div class="relative z-10 flex h-full flex-col gap-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl ring-1 ring-inset ring-white/40 dark:ring-white/10 {{ $accentClasses['chip'] }}">
                                            <x-filament::icon :icon="$item['icon']" class="h-6 w-6 {{ $accentClasses['icon'] }}" />
                                        </div>

                                        <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] ring-1 ring-inset {{ $accentClasses['chip'] }}">
                                            {{ $item['category'] }}
                                        </span>
                                    </div>

                                    <div class="space-y-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="space-y-1.5">
                                                <div class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                                    {{ $item['title'] }}
                                                </div>

                                                <div class="market-attention-widget__value text-4xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                                    {{ $item['value'] }}
                                                </div>
                                            </div>

                                            <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $accentClasses['status'] }}">
                                                Требует решения
                                            </span>
                                        </div>

                                        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                                            {{ $item['description'] }}
                                        </p>
                                    </div>

                                    <div class="mt-auto pt-1">
                                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-medium shadow-sm transition {{ $accentClasses['cta'] }}">
                                            Перейти
                                            <x-filament::icon
                                                icon="heroicon-m-arrow-up-right"
                                                class="market-attention-widget__cta-icon h-4 w-4 transition-transform"
                                            />
                                        </span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament::section>
