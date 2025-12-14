<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-xl border bg-white p-6">
            <div class="text-lg font-semibold">Шаблоны отчётов</div>
            <div class="mt-1 text-sm text-gray-600">Настройка типов отчётов, расписаний и получателей.</div>

            <a href="{{ $this->getTemplateUrl() }}"
               class="mt-4 inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold border">
                Открыть
            </a>
        </div>

        <div class="rounded-xl border bg-white p-6">
            <div class="text-lg font-semibold">Запуски отчётов</div>
            <div class="mt-1 text-sm text-gray-600">История запусков, статусы, файлы и ошибки.</div>

            <a href="{{ $this->getRunsUrl() }}"
               class="mt-4 inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold border">
                Открыть
            </a>
        </div>
    </div>
</x-filament-panels::page>
