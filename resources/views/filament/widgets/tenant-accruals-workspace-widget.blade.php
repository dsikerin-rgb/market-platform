<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell aw-shell--accruals">
        <section class="aw-hero aw-hero--accruals">
            <div class="aw-hero-stack--accruals">
                <div class="aw-hero-copy aw-hero-copy--accruals">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Начисления</h1>
                            <p class="aw-hero-subheading">
                                Реестр начислений из 1С и исторического импорта. Основная работа ниже: проверка связей с договорами,
                                строк без договора и проблемных начислений.
                            </p>
                        </div>
                    </div>
                </div>

                @if ($hasData && $latestPeriodLabel)
                    <div class="aw-inline-actions aw-inline-actions--accruals">
                        <span class="aw-chip aw-chip--accruals-context">
                            Последний период: {{ $latestPeriodLabel }}
                        </span>
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-filament::section>
