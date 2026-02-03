<x-cabinet-layout :tenant="auth()->user()->tenant" title="Чат с покупателями">
    <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-4">
        <h2 class="text-lg font-semibold">Чат с покупателями (демо)</h2>
        <p class="text-sm text-slate-500">Экран для будущей коммуникации с покупателями. Варианты интеграции: Telegram, чат на сайте.</p>

        <div class="space-y-3">
            <div class="flex justify-start">
                <div class="bg-slate-100 rounded-2xl p-3 max-w-xs text-sm">Здравствуйте! Есть ли в наличии свежие овощи?</div>
            </div>
            <div class="flex justify-end">
                <div class="bg-slate-900 text-white rounded-2xl p-3 max-w-xs text-sm">Добрый день! Да, сегодня завезли — приходите.</div>
            </div>
            <div class="flex justify-start">
                <div class="bg-slate-100 rounded-2xl p-3 max-w-xs text-sm">Отлично, спасибо!</div>
            </div>
        </div>

        <div class="rounded-xl border border-dashed border-slate-300 p-3 text-xs text-slate-500">
            Интеграция в разработке: кнопка «Написать» на витрине будет вести в Telegram или на чат формы.
        </div>
    </div>
</x-cabinet-layout>
