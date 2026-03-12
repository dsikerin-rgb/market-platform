<x-filament-panels::page>
    @if (! $this->shouldUseWorkspaceDashboard())
        {{ $this->content }}
    @else
        @php
            $hero = $this->getWorkspaceHeroData();
            $sections = $this->getWorkspaceDashboardSections();
            $attentionSection = collect($sections)->firstWhere('key', 'attention');
            $contentSections = array_values(array_filter(
                $sections,
                static fn (array $section): bool => $section['key'] !== 'attention',
            ));
            $workspaceHeaderWidgets = $this->getWorkspaceHeaderWidgets();
            $widgetData = [
                ...$this->getWidgetData(),
                ...(
                    property_exists($this, 'filters')
                        ? ['pageFilters' => $this->filters]
                        : []
                ),
            ];
        @endphp

        <style>
            .dashboard-workspace {
                --dashboard-border: rgba(15, 23, 42, 0.10);
                --dashboard-border-strong: rgba(37, 99, 235, 0.22);
                --dashboard-surface: #ffffff;
                --dashboard-surface-soft: #f8fafc;
                --dashboard-surface-strong: #eef2ff;
                --dashboard-panel: #ffffff;
                --dashboard-panel-soft: #f8fafc;
                --dashboard-text: #0f172a;
                --dashboard-heading: #0f172a;
                --dashboard-muted: #64748b;
                --dashboard-muted-strong: #475569;
                --dashboard-shadow: rgba(15, 23, 42, 0.08);
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
            }

            .dark .dashboard-workspace {
                --dashboard-border: rgba(148, 163, 184, 0.18);
                --dashboard-border-strong: rgba(96, 165, 250, 0.34);
                --dashboard-surface: rgba(15, 23, 42, 0.72);
                --dashboard-surface-soft: rgba(15, 23, 42, 0.58);
                --dashboard-surface-strong: rgba(15, 23, 42, 0.92);
                --dashboard-panel: rgba(15, 23, 42, 0.72);
                --dashboard-panel-soft: rgba(15, 23, 42, 0.58);
                --dashboard-text: #f8fafc;
                --dashboard-heading: #f8fafc;
                --dashboard-muted: #94a3b8;
                --dashboard-muted-strong: #cbd5e1;
                --dashboard-shadow: rgba(15, 23, 42, 0.18);
            }

            .dashboard-workspace__hero {
                border: 1px solid var(--dashboard-border);
                border-radius: 1.5rem;
                background:
                    radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 28%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 24%),
                    linear-gradient(180deg, #eff6ff, #dbeafe);
                padding: 1.5rem;
                box-shadow: 0 24px 60px var(--dashboard-shadow);
            }

            .dark .dashboard-workspace__hero {
                background:
                    radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.09), transparent 24%),
                    linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.92));
            }

            .dashboard-workspace__hero-row {
                display: flex;
                gap: 1.25rem;
                justify-content: space-between;
                align-items: flex-end;
                flex-wrap: wrap;
            }

            .dashboard-workspace__hero-main {
                display: flex;
                flex-direction: column;
                gap: 0.85rem;
                max-width: 46rem;
            }

            .dashboard-workspace__hero-title {
                display: flex;
                align-items: center;
                gap: 0.9rem;
            }

            .dashboard-workspace__hero-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 3rem;
                height: 3rem;
                border-radius: 1rem;
                background: rgba(37, 99, 235, 0.12);
                color: #1d4ed8;
                flex-shrink: 0;
            }

            .dark .dashboard-workspace__hero-icon {
                background: rgba(59, 130, 246, 0.12);
                color: rgb(147, 197, 253);
            }

            .dashboard-workspace__hero-copy h2 {
                margin: 0;
                font-size: 2rem;
                line-height: 1.1;
                font-weight: 700;
                color: var(--dashboard-heading);
            }

            .dashboard-workspace__hero-copy p {
                margin: 0.35rem 0 0;
                font-size: 0.95rem;
                line-height: 1.65;
                color: var(--dashboard-muted);
            }

            .dashboard-workspace__hero-pills {
                display: flex;
                flex-wrap: wrap;
                gap: 0.65rem;
            }

            .dashboard-workspace__pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.5rem 0.85rem;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.22);
                background: rgba(37, 99, 235, 0.08);
                color: #1d4ed8;
                font-size: 0.78rem;
                font-weight: 600;
            }

            .dark .dashboard-workspace__pill {
                border-color: rgba(59, 130, 246, 0.28);
                background: rgba(37, 99, 235, 0.12);
                color: #dbeafe;
            }

            .dashboard-workspace__stats {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.85rem;
                min-width: min(100%, 28rem);
            }

            .dashboard-workspace__stat {
                border-radius: 1rem;
                padding: 0.95rem 1rem;
                border: 1px solid var(--dashboard-border);
                background: rgba(255, 255, 255, 0.55);
            }

            .dark .dashboard-workspace__stat {
                background: rgba(15, 23, 42, 0.55);
            }

            .dashboard-workspace__stat-label {
                font-size: 0.7rem;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--dashboard-muted);
            }

            .dashboard-workspace__stat-value {
                margin-top: 0.3rem;
                font-size: 1.75rem;
                line-height: 1;
                font-weight: 700;
                color: var(--dashboard-heading);
            }

            .dashboard-workspace__stat-value.is-success {
                color: #34d399;
            }

            .dashboard-workspace__stat-value.is-warning {
                color: #f59e0b;
            }

            .dashboard-workspace__stat-value.is-danger {
                color: #ef4444;
            }

            .dashboard-workspace__links {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.9rem;
            }

            .dashboard-workspace__link {
                display: flex;
                gap: 0.85rem;
                align-items: flex-start;
                padding: 1rem;
                border-radius: 1rem;
                border: 1px solid var(--dashboard-border);
                background: rgba(255, 255, 255, 0.65);
                color: inherit;
                text-decoration: none;
                transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
            }

            .dashboard-workspace__link:hover {
                transform: translateY(-1px);
                border-color: var(--dashboard-border-strong);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.10);
            }

            .dark .dashboard-workspace__link {
                background: rgba(15, 23, 42, 0.62);
            }

            .dashboard-workspace__link-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2.75rem;
                height: 2.75rem;
                border-radius: 0.9rem;
                background: rgba(37, 99, 235, 0.12);
                color: #1d4ed8;
                flex-shrink: 0;
            }

            .dark .dashboard-workspace__link-icon {
                background: rgba(59, 130, 246, 0.14);
                color: rgb(147, 197, 253);
            }

            .dashboard-workspace__link-title {
                margin: 0;
                font-size: 0.95rem;
                font-weight: 700;
                color: var(--dashboard-heading);
            }

            .dashboard-workspace__link-copy {
                margin: 0.3rem 0 0;
                font-size: 0.87rem;
                line-height: 1.55;
                color: var(--dashboard-muted);
            }

            .dashboard-workspace__link-meta {
                margin-top: 0.55rem;
                color: #1d4ed8;
                font-size: 0.82rem;
                font-weight: 600;
            }

            .dark .dashboard-workspace__link-meta {
                color: #bfdbfe;
            }

            .dashboard-workspace__context {
                display: grid;
                gap: 1.5rem;
                grid-template-columns: minmax(0, 1fr) minmax(0, 24rem);
                align-items: start;
            }

            .dashboard-workspace__panel {
                border-radius: 1.5rem;
                border: 1px solid var(--dashboard-border);
                background: var(--dashboard-panel);
                box-shadow: 0 18px 36px var(--dashboard-shadow);
                overflow: hidden;
            }

            .dashboard-workspace__panel-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                padding: 1.1rem 1.25rem;
                border-bottom: 1px solid var(--dashboard-border);
            }

            .dashboard-workspace__panel-head h3 {
                margin: 0;
                font-size: 1.05rem;
                font-weight: 700;
                color: var(--dashboard-heading);
            }

            .dashboard-workspace__panel-head p {
                margin: 0.35rem 0 0;
                font-size: 0.9rem;
                line-height: 1.6;
                color: var(--dashboard-muted);
            }

            .dashboard-workspace__panel-body {
                padding: 1.25rem;
            }

            .dashboard-workspace__section-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
                flex-wrap: wrap;
            }

            .dashboard-workspace__section-title {
                display: flex;
                gap: 0.9rem;
                align-items: flex-start;
            }

            .dashboard-workspace__section-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2.75rem;
                height: 2.75rem;
                border-radius: 1rem;
                background: rgba(37, 99, 235, 0.12);
                color: #1d4ed8;
                flex-shrink: 0;
            }

            .dark .dashboard-workspace__section-icon {
                background: rgba(59, 130, 246, 0.12);
                color: rgb(147, 197, 253);
            }

            .dashboard-workspace__section-title h3 {
                margin: 0;
                font-size: 1.1rem;
                font-weight: 700;
                color: var(--dashboard-heading);
            }

            .dashboard-workspace__section-title p {
                margin: 0.35rem 0 0;
                font-size: 0.9rem;
                line-height: 1.6;
                color: var(--dashboard-muted);
            }

            .dashboard-workspace__section-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.45rem 0.75rem;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.22);
                background: rgba(37, 99, 235, 0.08);
                color: #1d4ed8;
                font-size: 0.78rem;
                font-weight: 600;
            }

            .dark .dashboard-workspace__section-badge {
                border-color: rgba(59, 130, 246, 0.28);
                background: rgba(37, 99, 235, 0.12);
                color: #dbeafe;
            }

            .dashboard-workspace__widgets.fi-wi {
                gap: 1rem;
            }

            .dashboard-workspace__widgets .fi-wi-widget {
                min-width: 0;
            }

            .dashboard-workspace__widgets .fi-wi-widget > * {
                height: 100%;
            }

            .dashboard-workspace__widgets .fi-section {
                border-radius: 1.25rem;
                border-color: var(--dashboard-border);
                background: var(--dashboard-panel-soft);
                box-shadow: none;
            }

            .dashboard-workspace__attention-overlay {
                position: fixed;
                top: 5.75rem;
                right: 1.5rem;
                z-index: 120;
                width: min(calc(100vw - 2rem), 24rem);
                pointer-events: none;
                isolation: isolate;
            }

            .dashboard-workspace__attention-overlay .dashboard-workspace__widgets.fi-wi {
                display: block;
            }

            .dashboard-workspace__attention-overlay .fi-wi-widget {
                pointer-events: auto;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget,
            .dashboard-workspace__attention-overlay .market-attention-widget__surface,
            .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack,
            .dashboard-workspace__attention-overlay .market-attention-widget__card--toast {
                pointer-events: auto;
            }

            .dashboard-workspace__attention-overlay .fi-section {
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
            }

            .dashboard-workspace__attention-overlay .fi-section-content-ctn,
            .dashboard-workspace__attention-overlay .fi-section-content {
                padding: 0 !important;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__surface {
                padding: 0;
                border: none;
                background: transparent;
                box-shadow: none;
                overflow: visible;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__mesh {
                display: none;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-layout {
                display: block;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-layout > :first-child {
                display: none;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack {
                align-items: stretch;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__card--toast {
                width: 100%;
                max-width: none;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(2) {
                margin-right: 0.5rem;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(3) {
                margin-right: 1rem;
            }

            .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(4) {
                margin-right: 1.5rem;
            }

            @media (max-width: 1279px) {
                .dashboard-workspace__links {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 1023px) {
                .dashboard-workspace__context {
                    grid-template-columns: minmax(0, 1fr);
                }

                .dashboard-workspace__stats {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    min-width: 100%;
                }

                .dashboard-workspace__attention-overlay {
                    top: 5rem;
                    right: 1rem;
                    width: min(calc(100vw - 1.5rem), 22rem);
                }
            }

            @media (max-width: 767px) {
                .dashboard-workspace__links,
                .dashboard-workspace__stats {
                    grid-template-columns: minmax(0, 1fr);
                }

                .dashboard-workspace__attention-overlay {
                    top: auto;
                    right: 0.75rem;
                    bottom: 1rem;
                    left: 0.75rem;
                    width: auto;
                }

                .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(2),
                .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(3),
                .dashboard-workspace__attention-overlay .market-attention-widget__toast-stack .market-attention-widget__card--toast:nth-child(4) {
                    margin-right: 0;
                }
            }
        </style>

        <div class="dashboard-workspace">
            @if ($attentionSection && $attentionSection['widgets'] !== [])
                <div class="dashboard-workspace__attention-overlay">
                    <x-filament-widgets::widgets
                        :columns="1"
                        :data="$widgetData"
                        :widgets="$attentionSection['widgets']"
                        class="dashboard-workspace__widgets"
                    />
                </div>
            @endif

            <section class="dashboard-workspace__hero">
                <div class="dashboard-workspace__hero-row">
                    <div class="dashboard-workspace__hero-main">
                        <div class="dashboard-workspace__hero-title">
                            <div class="dashboard-workspace__hero-icon">
                                <x-filament::icon icon="heroicon-o-home" class="h-6 w-6" />
                            </div>

                            <div class="dashboard-workspace__hero-copy">
                                <h2>{{ $hero['title'] }}</h2>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-workspace__stats">
                        @foreach ($hero['stats'] as $stat)
                            <div class="dashboard-workspace__stat">
                                <div class="dashboard-workspace__stat-label">{{ $stat['label'] }}</div>
                                <div class="dashboard-workspace__stat-value{{ $stat['tone'] !== 'neutral' ? ' is-' . $stat['tone'] : '' }}">
                                    {{ $stat['value'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="dashboard-workspace__links" style="margin-top: 1.25rem;">
                    @foreach ($hero['links'] as $link)
                        <a href="{{ $link['url'] }}" class="dashboard-workspace__link">
                            <div class="dashboard-workspace__link-icon">
                                <x-filament::icon :icon="$link['icon']" class="h-5 w-5" />
                            </div>

                            <div>
                                <p class="dashboard-workspace__link-title">{{ $link['title'] }}</p>
                                <p class="dashboard-workspace__link-copy">{{ $link['description'] }}</p>
                                <div class="dashboard-workspace__link-meta">{{ $link['meta'] }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>

            <div class="dashboard-workspace__context">
                @if ($workspaceHeaderWidgets !== [])
                    <div>
                        <x-filament-widgets::widgets
                            :columns="1"
                            :data="$widgetData"
                            :widgets="$workspaceHeaderWidgets"
                            class="dashboard-workspace__widgets"
                        />
                    </div>
                @endif

                <section class="dashboard-workspace__panel">
                    <div class="dashboard-workspace__panel-head">
                        <div>
                            <h3>Отчётный период</h3>
                            <p>Фильтр влияет на отчётные и финансовые виджеты. Логика данных дашборда не меняется.</p>
                        </div>
                    </div>

                    <div class="dashboard-workspace__panel-body">
                        {{ $this->filtersForm }}
                    </div>
                </section>
            </div>

            @foreach ($contentSections as $section)
                <section class="dashboard-workspace__panel">
                    <div class="dashboard-workspace__panel-head">
                        <div class="dashboard-workspace__section-head">
                            <div class="dashboard-workspace__section-title">
                                <div class="dashboard-workspace__section-icon">
                                    <x-filament::icon :icon="$section['icon']" class="h-5 w-5" />
                                </div>

                                <div>
                                    <h3>{{ $section['title'] }}</h3>
                                    <p>{{ $section['description'] }}</p>
                                </div>
                            </div>

                            <span class="dashboard-workspace__section-badge">
                                <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4" />
                                {{ count($section['widgets']) }} блоков
                            </span>
                        </div>
                    </div>

                    <div class="dashboard-workspace__panel-body">
                        <x-filament-widgets::widgets
                            :columns="$section['columns']"
                            :data="$widgetData"
                            :widgets="$section['widgets']"
                            class="dashboard-workspace__widgets"
                        />
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
