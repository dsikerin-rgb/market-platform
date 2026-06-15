@if ($shouldShow)
    <div
        class="fixed inset-0 z-[9998] flex items-center justify-center bg-slate-950/45 px-4 py-6 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="first-login-welcome-title"
    >
        <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-2xl ring-1 ring-slate-900/10">
            <div class="mb-5 flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>

                <div>
                    <h2 id="first-login-welcome-title" class="text-xl font-semibold text-slate-950">
                        Добро пожаловать в сервис
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Сейчас сервис работает в тестовом режиме. Мы постепенно дорабатываем сценарии, интерфейсы и уведомления, поэтому отдельные функции могут меняться или работать неидеально.
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-slate-700">
                Если заметите ошибку, неточность в данных или появится предложение по улучшению, пожалуйста, отправьте сообщение пользователю <strong>super-admin</strong> внутри сервиса. Так мы быстрее увидим обратную связь и сможем исправить проблему.
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    wire:click="acknowledge"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70"
                >
                    Понятно
                </button>
            </div>
        </div>
    </div>
@endif
