<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div
        class="aw-shell aw-shell--calendar"
        wire:ignore
        x-data="{
            viewMode: 'list',
            monthLabel: @js($initialMonthLabel),
            defaultMonthLabel: @js($initialMonthLabel),
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
        x-init="updateFromUrl(); window.addEventListener('livewire:navigated', () => updateFromUrl())"
    >
        <section class="aw-hero aw-hero--calendar">
            <div class="aw-hero-stack--calendar">
                <div class="aw-hero-copy aw-hero-copy--calendar">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Календарь</h1>
                            <p class="aw-hero-subheading">
                                Календарь праздников, промо-активностей и внутренних событий рынка.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-calendar-toolbar">
                    <div class="aw-calendar-toolbar__top">
                        <div class="aw-calendar-toolbar__main">
                            <div class="aw-view-switch" role="tablist" aria-label="Режим просмотра календаря">
                                <a
                                    href="{{ $listUrl }}"
                                    class="aw-view-switch__item"
                                    :class="{ 'is-active': viewMode === 'list' }"
                                    :aria-current="viewMode === 'list' ? 'page' : 'false'"
                                >
                                    Список
                                </a>
                                <a
                                    href="{{ $calendarUrl }}"
                                    class="aw-view-switch__item"
                                    :class="{ 'is-active': viewMode === 'calendar' }"
                                    :aria-current="viewMode === 'calendar' ? 'page' : 'false'"
                                >
                                    Календарь
                                </a>
                            </div>

                            <div class="aw-inline-actions aw-inline-actions--calendar">
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
