<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Вход в кабинет арендатора</title>

    @php($hasViteManifest = file_exists(public_path('build/manifest.json')))
    @php($hasViteHot = file_exists(public_path('hot')))
    @if ($hasViteManifest || $hasViteHot)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            html, body { height: 100%; }
        </style>
    @endif
</head>

<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute -top-24 -left-16 h-80 w-80 rounded-full bg-sky-200/50 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 -right-16 h-96 w-96 rounded-full bg-indigo-200/40 blur-3xl"></div>

        <main class="relative mx-auto flex min-h-screen w-full max-w-6xl items-center px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid w-full gap-6 lg:grid-cols-[1.05fr_0.95fr]">
                <section class="hidden rounded-3xl border border-slate-200/80 bg-white/80 p-8 shadow-sm backdrop-blur lg:block">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ $marketName ?? config('app.name', 'Market Platform') }}</p>
                    <h1 class="mt-3 text-3xl font-semibold leading-tight">Кабинет арендатора</h1>
                    <p class="mt-4 max-w-md text-sm leading-6 text-slate-600">
                        Следите за начислениями, документами и обращениями в одном окне.
                        Используйте учётные данные, выданные администратором рынка.
                    </p>

                    <div class="mt-8 space-y-3 text-sm text-slate-700">
                        <div class="flex items-start gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="mt-0.5 inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                            <span>Актуальные начисления и оплаты по вашему арендному месту.</span>
                        </div>
                        <div class="flex items-start gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="mt-0.5 inline-block h-2.5 w-2.5 rounded-full bg-sky-500"></span>
                            <span>Переписка с управляющей компанией и история обращений.</span>
                        </div>
                        <div class="flex items-start gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="mt-0.5 inline-block h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                            <span>Документы и договоры в одном месте без бумажной рутины.</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="space-y-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $marketName ?? config('app.name', 'Market Platform') }}</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Вход</p>
                        <h2 class="text-2xl font-semibold">Авторизация</h2>
                        <p class="text-sm text-slate-500">Введите email и пароль вашего аккаунта арендатора.</p>
                    </div>

                    @if (session('status'))
                        <div class="mt-5 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            <ul class="list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('cabinet.login.submit') }}" class="mt-6 space-y-5" novalidate>
                        @csrf

                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-slate-700">Email</span>
                            <input
                                class="w-full rounded-xl border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:ring-sky-500"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                inputmode="email"
                                placeholder="name@example.com"
                                required
                            >
                        </label>

                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-slate-700">Пароль</span>
                            <div class="relative">
                                <input
                                    id="password"
                                    class="w-full rounded-xl border-slate-300 bg-white px-4 py-2.5 pr-24 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:ring-sky-500"
                                    type="password"
                                    name="password"
                                    autocomplete="current-password"
                                    placeholder="Введите пароль"
                                    required
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                                    aria-controls="password"
                                    aria-label="Показать пароль"
                                >
                                    Показать
                                </button>
                            </div>
                        </label>

                        <label class="inline-flex items-center gap-2.5 text-sm text-slate-600">
                            <input type="checkbox" name="remember" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                            Запомнить меня на этом устройстве
                        </label>

                        <button
                            class="inline-flex w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
                            type="submit"
                        >
                            Войти в кабинет
                        </button>
                    </form>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const input = document.getElementById('password');
            const button = document.getElementById('toggle-password');
            if (!input || !button) return;

            button.addEventListener('click', function () {
                const isPassword = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPassword ? 'text' : 'password');
                button.textContent = isPassword ? 'Скрыть' : 'Показать';
                button.setAttribute('aria-label', isPassword ? 'Скрыть пароль' : 'Показать пароль');
            });
        })();
    </script>
</body>
</html>
