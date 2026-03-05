<x-cabinet-layout :tenant="auth()->user()->tenant" title="Сообщения покупателей">
    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-900">Чат с покупателями</h2>
            <span class="rounded-full bg-amber-100 text-amber-800 px-2.5 py-1 text-xs font-medium">Демо</span>
        </div>

        <p class="text-sm text-slate-600">
            Здесь будет единый канал сообщений от покупателей. Экран уже подготовлен в мобильном формате.
        </p>

        <div class="space-y-2">
            <div class="flex justify-start">
                <div class="max-w-[85%] rounded-2xl bg-slate-100 border border-slate-200 px-3 py-2.5 text-sm text-slate-700">
                    Здравствуйте! Есть ли в наличии свежие овощи?
                </div>
            </div>
            <div class="flex justify-end">
                <div class="max-w-[85%] rounded-2xl bg-slate-900 text-white px-3 py-2.5 text-sm">
                    Добрый день! Да, сегодня поступление. Подходите на место Э-980/1.
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-dashed border-slate-300 px-3 py-2 text-xs text-slate-500">
            Следующий шаг: подключение Telegram/виджета сайта, чтобы сообщения попадали в этот экран автоматически.
        </div>
    </section>
</x-cabinet-layout>
