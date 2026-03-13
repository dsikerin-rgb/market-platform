<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-3xl">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Что показывает эта страница</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Здесь собраны реальные строки обмена 1С по договорам и периодам: сколько начислено, сколько оплачено
                    и какой долг остался на момент расчёта 1С. Это не бухгалтерские проводки по каждой отдельной оплате,
                    а снимки состояния финансового контура.
                </p>
            </div>

            <div class="shrink-0">
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-archive-box"
                    tag="a"
                    :href="\App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index')"
                >
                    Открыть архив начислений
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
