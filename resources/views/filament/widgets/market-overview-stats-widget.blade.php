<x-filament::section>
    @php
        $hasFormulaModal = filled($formulaModal ?? null);
    @endphp

    <div
        x-data="{
            formulaModal: @js($formulaModal ?? ''),
            modalTitle: '',
            modalIntro: '',
            modalFormula: [],
            modalLinks: [],
            modalFooter: '',
            openFormulaModal(metric, data) {
                this.formulaModal = metric;
                this.modalTitle = data.title || '';
                this.modalIntro = data.intro || '';
                this.modalFormula = data.formula || [];
                this.modalLinks = data.links || [];
                this.modalFooter = data.footer || '';
            },
            closeFormulaModal() {
                this.formulaModal = '';
                this.modalTitle = '';
                this.modalIntro = '';
                this.modalFormula = [];
                this.modalLinks = [];
                this.modalFooter = '';
            }
        }"
        x-init="$watch('formulaModal', value => {
            if (value && @js($hasFormulaModal)) {
                @this.call('getFormulaModalData').then(data => {
                    if (data) {
                        openFormulaModal(value, data);
                    }
                });
            }
        })"
        class="space-y-6"
    >
        {{-- Stats Grid --}}
        <div class="grid gap-4 {{ count($items) > 4 ? 'md:grid-cols-2' : '' }} {{ count($items) > 6 ? 'xl:grid-cols-3' : '' }}">
            @foreach ($items as $stat)
                @php
                    $isFinancial = in_array($stat->getName() ?? '', ['accrued', 'paid', 'debt'], true);
                    $hasModal = $isFinancial && filled($stat->getExtraAttributes()['wire:click'] ?? null);
                @endphp

                <div
                    @if ($hasModal)
                        x-on:click="openFormulaModal('{{ $stat->getExtraAttributes()['title'] ?? '' }}', {{ $stat->getExtraAttributes()['wire:click'] }})"
                        x-on:keydown.enter.prevent="openFormulaModal('{{ $stat->getExtraAttributes()['title'] ?? '' }}', {{ $stat->getExtraAttributes()['wire:click'] }})"
                        x-on:keydown.space.prevent="$event.preventDefault()"
                        role="button"
                        tabindex="0"
                        class="cursor-pointer transition hover:shadow-sm hover:bg-gray-50 dark:hover:bg-white/5 rounded-xl p-4 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
                    @endif
                >
                    <x-filament::widgets.stats-overview-widget.stat
                        :stat="$stat"
                    />
                </div>
            @endforeach
        </div>

        {{-- Formula Modal --}}
        <x-filament::modal
            x-show="formulaModal !== ''"
            x-cloak
            width="2xl"
            :closeable="true"
            :close-by-clicking-away="true"
            :close-by-escaping="true"
            slide-over
        >
            <x-slot name="heading">
                <div class="flex items-center gap-3">
                    <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 ring-1 ring-inset ring-primary-500/20 dark:bg-primary-400/10 dark:text-primary-300">
                        <x-filament::icon icon="heroicon-m-calculator" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 x-text="modalTitle" class="text-lg font-semibold tracking-tight"></h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="modalIntro"></p>
                    </div>
                </div>
            </x-slot>

            <div class="space-y-6">
                {{-- Formula Steps --}}
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Формула расчёта:</h3>
                    <ol class="space-y-2">
                        <template x-for="(step, index) in modalFormula" :key="index">
                            <li class="flex items-start gap-3">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-primary-700 text-xs font-bold dark:bg-primary-900/40 dark:text-primary-300" x-text="index + 1"></span>
                                <span class="flex-1 text-sm text-gray-700 dark:text-gray-300" x-text="step"></span>
                            </li>
                        </template>
                    </ol>
                </div>

                {{-- Quick Links --}}
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Быстрые ссылки:</h3>
                    <div class="space-y-2">
                        <template x-for="link in modalLinks" :key="link.label">
                            <a
                                :href="link.url"
                                class="flex items-start gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 transition hover:border-primary-500 hover:shadow-sm dark:hover:border-primary-400"
                                x-on:click="closeFormulaModal()"
                            >
                                <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-primary-500/10 text-primary-600 ring-1 ring-inset ring-primary-500/20 dark:bg-primary-400/10 dark:text-primary-300">
                                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="link.label"></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="link.description"></div>
                                </div>
                            </a>
                        </template>
                    </div>
                </div>

                {{-- Footer Note --}}
                <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3">
                    <div class="flex items-start gap-2">
                        <x-filament::icon icon="heroicon-m-information-circle" class="h-5 w-5 text-amber-600 dark:text-amber-400 mt-0.5" />
                        <p class="text-sm text-amber-800 dark:text-amber-200" x-text="modalFooter"></p>
                    </div>
                </div>
            </div>

            <x-slot name="footer">
                <div class="flex justify-end gap-3">
                    <x-filament::button
                        x-on:click="closeFormulaModal()"
                        color="gray"
                    >
                        Закрыть
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament::section>
