<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div
        class="aw-shell aw-shell--calendar"
        wire:ignore
        x-data="{
            viewMode: 'list',
            monthLabel: @js($initialMonthLabel),
            defaultMonthLabel: @js($initialMonthLabel),
            isNavigating: false,
            setMode(mode) {
                this.viewMode = mode;
                this.isNavigating = true;
            },
            updateFromUrl() {
                const params = new URLSearchParams(window.location.search);

                this.viewMode = params.get('view') === 'calendar' ? 'calendar' : 'list';

                const rawMonth = params.get('month');

                if (!rawMonth || !/^\d{4}-\d{2}$/.test(rawMonth)) {
                    this.monthLabel = this.defaultMonthLabel;
                    return;
                }

                const [year, month] = rawMonth.split('-').map(Number);
                const parsed = new Date(year, month - 1, 1);

                if (Number.isNaN(parsed.getTime())) {
                    this.monthLabel = this.defaultMonthLabel;
                    return;
                }

                const formatted = parsed.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
                this.monthLabel = formatted.charAt(0).toUpperCase() + formatted.slice(1);
            },
        }"
        x-on:aw-calendar-month-change.window="monthLabel = $event.detail.monthLabel; isNavigating = true"
        x-init="
            updateFromUrl();
            window.addEventListener('livewire:navigating', () => { isNavigating = true; });
            window.addEventListener('livewire:navigated', () => {
                updateFromUrl();
                isNavigating = false;
            });
        "
    >
        <section class="aw-hero aw-hero--calendar">
            <div class="aw-hero-stack--calendar">
                <div class="aw-hero-copy aw-hero-copy--calendar">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">События</h1>
                            <p class="aw-hero-subheading">
                                Праздники, промо-активности и внутренние события рынка в формате списка или календаря.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-calendar-toolbar">
                    <div class="aw-calendar-toolbar__top">
                        <div class="aw-calendar-toolbar__main">
                            <div class="aw-view-switch" role="tablist" aria-label="Режим просмотра событий">
                                <a
                                    href="{{ $listUrl }}"
                                    class="aw-view-switch__item"
                                    wire:navigate
                                    x-on:click="setMode('list')"
                                    :class="{ 'is-active': viewMode === 'list' }"
                                    :aria-current="viewMode === 'list' ? 'page' : 'false'"
                                >
                                    Список
                                </a>
                                <a
                                    href="{{ $calendarUrl }}"
                                    class="aw-view-switch__item"
                                    wire:navigate
                                    x-on:click="setMode('calendar')"
                                    :class="{ 'is-active': viewMode === 'calendar' }"
                                    :aria-current="viewMode === 'calendar' ? 'page' : 'false'"
                                >
                                    Календарь
                                </a>
                            </div>

                            <div class="aw-inline-actions aw-inline-actions--calendar" :class="{ 'is-pending': isNavigating }">
                                <span class="aw-chip aw-chip--calendar-context">
                                    Текущий месяц: <span x-text="monthLabel"></span>
                                </span>
                            </div>
                        </div>

                        @if ($createUrl)
                            <a href="{{ $createUrl }}" class="aw-calendar-cta">
                                <span class="aw-link-icon aw-link-icon--calendar-action">
                                    <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                                </span>
                                <span>Добавить</span>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-filament::section>
