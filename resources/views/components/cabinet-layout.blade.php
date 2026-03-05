@props(['tenant' => null, 'title' => null])

@php
    $viteHot = file_exists(public_path('hot'));
    $viteManifest = file_exists(public_path('build/manifest.json'));
    $useVite = $viteHot || $viteManifest;
    $impersonation = session(\App\Services\Cabinet\TenantImpersonationService::SESSION_KEY);
    $isImpersonation = is_array($impersonation) && ! empty($impersonation['impersonator_user_id']);

    // На layout не завязываем реальную привязку, только имя для шапки.
    $tenantName = data_get($tenant, 'display_name')
        ?: data_get($tenant, 'name')
        ?: 'Арендатор';
@endphp

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Кабинет арендатора' }}</title>

    @if ($useVite)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        {{-- FALLBACK: без сборки Vite (staging/demo/local без node) --}}
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            html, body { height: 100%; }
            .safe-pb { padding-bottom: env(safe-area-inset-bottom); }
            .tap { -webkit-tap-highlight-color: transparent; }
        </style>
    @endif

    @stack('head')
</head>

<body class="bg-slate-100 text-slate-900 antialiased tap">
<div class="min-h-screen">

    {{-- Верхняя панель --}}
    <header class="sticky top-0 z-30 bg-white/85 backdrop-blur border-b border-slate-200">
        <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-widest text-slate-400 uppercase">
                    Кабинет арендатора
                </p>
                <h1 class="text-base font-semibold truncate">
                    {{ $tenantName }}
                </h1>
            </div>

            @if (auth()->check() && (\Illuminate\Support\Facades\Route::has('cabinet.logout') || \Illuminate\Support\Facades\Route::has('cabinet.impersonation.exit')))
                <form method="POST" action="{{ ($isImpersonation && \Illuminate\Support\Facades\Route::has('cabinet.impersonation.exit')) ? route('cabinet.impersonation.exit') : route('cabinet.logout') }}" data-navigate="false">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-xl px-3 py-2 text-sm font-medium {{ $isImpersonation ? 'text-amber-700 hover:text-amber-900' : 'text-slate-600 hover:text-slate-900' }} active:scale-[0.99] transition"
                    >
                        Выйти
                    </button>
                </form>
            @endif
        </div>
    </header>

    @if ($isImpersonation)
        <div class="max-w-3xl mx-auto px-4 pt-4">
            <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold">
                            Вы вошли как арендатор: {{ $impersonation['tenant_name'] ?? $tenantName }}
                        </div>
                        <div class="text-amber-800/80">
                            Через администратора: {{ $impersonation['impersonator_name'] ?? ('#' . ($impersonation['impersonator_user_id'] ?? '')) }}
                        </div>
                    </div>

                    @if (\Illuminate\Support\Facades\Route::has('cabinet.impersonation.exit'))
                        <form method="POST" action="{{ route('cabinet.impersonation.exit') }}" data-navigate="false">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700"
                            >
                                Вернуться в админку
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Контент --}}
    <main class="max-w-3xl mx-auto px-4 pt-5 pb-28">
        <div class="space-y-4">

            @if (session('success'))
                <div class="rounded-2xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-900">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-900">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-900">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </main>

    {{-- Нижняя навигация --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 bg-white/90 backdrop-blur border-t border-slate-200 safe-pb">
        <div class="max-w-3xl mx-auto px-4 py-2">
            <div class="grid grid-cols-5 gap-1 text-[11px] font-medium text-center">
                @php
                    $navItem = 'flex flex-col items-center justify-center gap-1 rounded-2xl px-1 py-2 transition active:scale-[0.99]';
                    $navOn = 'text-slate-900 bg-slate-100';
                    $navOff = 'text-slate-500 hover:text-slate-800';
                @endphp

                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.dashboard') ? $navOn : $navOff }}"
                   href="{{ route('cabinet.dashboard') }}">
                    <span class="text-lg leading-none">🏠</span>
                    Главная
                </a>

                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.accruals') ? $navOn : $navOff }}"
                   href="{{ route('cabinet.accruals') }}">
                    <span class="text-lg leading-none">💳</span>
                    Начисления
                </a>

                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.requests*') ? $navOn : $navOff }}"
                   href="{{ route('cabinet.requests') }}">
                    <span class="text-lg leading-none">💬</span>
                    Общение
                </a>

                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.documents') ? $navOn : $navOff }}"
                   href="{{ route('cabinet.documents') }}">
                    <span class="text-lg leading-none">📄</span>
                    Документы
                </a>

                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.showcase.*') ? $navOn : $navOff }}"
                   href="{{ route('cabinet.showcase.edit') }}">
                    <span class="text-lg leading-none">🛍️</span>
                    Витрина
                </a>
            </div>
        </div>
    </nav>

</div>
</body>
</html>
