<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title>Вход в кабинет арендатора</title>

    @php
        $hasViteManifest = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));
    @endphp
    @if ($hasViteManifest)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
    @endif
</head>
<body class="min-h-screen bg-gradient-to-b from-sky-100 via-slate-100 to-slate-200 text-slate-900">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-[2rem] border border-white/80 bg-white/90 backdrop-blur shadow-[0_20px_60px_rgba(15,23,42,0.18)] overflow-hidden">
            <div class="bg-slate-900 px-5 py-5 text-white">
                <p class="text-[11px] uppercase tracking-[0.14em] text-slate-300">Личный кабинет</p>
                <h1 class="mt-1 text-xl font-semibold leading-tight">
                    {{ $marketName ?? config('app.name', 'Market Platform') }}
                </h1>
                <p class="mt-1 text-sm text-slate-300">Вход для арендаторов и сотрудников арендатора</p>
            </div>

            <div class="p-5 space-y-4">
                @if (session('status'))
                    <div class="rounded-2xl bg-sky-50 border border-sky-200 px-4 py-3 text-sm text-sky-900">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('cabinet.login.submit') }}" class="space-y-4">
                    @csrf

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Email</span>
                        <input
                            class="mt-1.5 w-full rounded-2xl border-slate-200 bg-white/80 px-4 py-3 text-base focus:border-slate-400 focus:ring-slate-300"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            placeholder="name@example.com"
                            required
                        >
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Пароль</span>
                        <input
                            class="mt-1.5 w-full rounded-2xl border-slate-200 bg-white/80 px-4 py-3 text-base focus:border-slate-400 focus:ring-slate-300"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="Введите пароль"
                            required
                        >
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-500">
                        <input type="checkbox" name="remember" class="rounded border-slate-300">
                        Запомнить меня
                    </label>

                    <button class="w-full rounded-2xl bg-slate-900 text-white py-3 text-sm font-semibold" type="submit">
                        Войти
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
