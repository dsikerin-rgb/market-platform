@props(['tenant' => null, 'title' => null])

@php
    $viteHot = file_exists(public_path('hot'));
    $viteManifest = file_exists(public_path('build/manifest.json'));
    $useVite = $viteHot || $viteManifest;

    $tenantName = data_get($tenant, 'display_name')
        ?: data_get($tenant, 'name')
        ?: 'Арендатор';

    $marketName = data_get($tenant, 'market.name');
    if (! $marketName && data_get($tenant, 'market_id')) {
        $marketName = \App\Models\Market::query()->whereKey((int) data_get($tenant, 'market_id'))->value('name');
    }
    $marketName = $marketName ?: config('app.name', 'Market Platform');

    $impersonation = session(\App\Services\Cabinet\TenantImpersonationService::SESSION_KEY);
    $isImpersonation = is_array($impersonation) && ! empty($impersonation['impersonator_user_id']);
@endphp

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Кабинет арендатора' }}</title>

    @if ($useVite)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
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
<div class="min-h-screen bg-gradient-to-b from-sky-100/70 via-slate-50 to-slate-100">
    <div class="mx-auto max-w-md min-h-screen relative">
        <header class="sticky top-0 z-30 px-3 pt-3">
            <div class="rounded-3xl border border-white/80 bg-white/85 backdrop-blur shadow-[0_10px_30px_rgba(15,23,42,0.08)] px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="inline-flex items-center rounded-full bg-slate-900 text-white text-[11px] px-2.5 py-1 font-medium">
                            {{ $marketName }}
                        </div>
                        <h1 class="mt-2 text-base font-semibold leading-tight truncate">
                            {{ $tenantName }}
                        </h1>
                        <p class="text-xs text-slate-500">
                            {{ $title ?? 'Кабинет арендатора' }}
                        </p>
                    </div>

                    @if (auth()->check() && (\Illuminate\Support\Facades\Route::has('cabinet.logout') || \Illuminate\Support\Facades\Route::has('cabinet.impersonation.exit')))
                        <div class="shrink-0">
                            @if ($isImpersonation && \Illuminate\Support\Facades\Route::has('cabinet.impersonation.exit'))
                                <form method="POST" action="{{ route('cabinet.impersonation.exit') }}" data-navigate="false">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800"
                                    >
                                        Вернуться
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('cabinet.logout') }}" data-navigate="false">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 hover:text-slate-900"
                                    >
                                        Выйти
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </header>

        @if ($isImpersonation)
            <div class="px-3 pt-2">
                <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                    <div class="font-semibold">
                        Вы вошли как арендатор: {{ $impersonation['tenant_name'] ?? $tenantName }}
                    </div>
                    <div class="text-amber-800/80 mt-0.5">
                        Через администратора: {{ $impersonation['impersonator_name'] ?? ('#' . ($impersonation['impersonator_user_id'] ?? '')) }}
                    </div>
                </div>
            </div>
        @endif

        <main class="px-3 pt-3 pb-36">
            <div class="space-y-3">
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

        @if (\Illuminate\Support\Facades\Route::has('cabinet.requests.create') && ! request()->routeIs('cabinet.requests.create'))
            <a
                href="{{ route('cabinet.requests.create') }}"
                class="fixed right-4 bottom-24 z-30 inline-flex items-center gap-2 rounded-full bg-slate-900 text-white px-4 py-2.5 text-sm font-semibold shadow-xl shadow-slate-900/20"
                aria-label="Создать обращение"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 4a.75.75 0 01.75.75v4.5h4.5a.75.75 0 010 1.5h-4.5v4.5a.75.75 0 01-1.5 0v-4.5h-4.5a.75.75 0 010-1.5h4.5v-4.5A.75.75 0 0110 4z" clip-rule="evenodd" />
                </svg>
                Обращение
            </a>
        @endif

        <nav class="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md z-30 px-3 pb-[calc(env(safe-area-inset-bottom)+10px)] pt-2">
            @php
                $navItem = 'group flex flex-col items-center justify-center gap-1 rounded-2xl px-1 py-2 text-[10px] font-medium transition';
                $navOn = 'bg-slate-900 text-white shadow-sm';
                $navOff = 'text-slate-500 hover:text-slate-900';
                $icon = 'h-5 w-5';
            @endphp
            <div class="grid grid-cols-5 gap-1 rounded-3xl border border-white/70 bg-white/90 backdrop-blur p-2 shadow-[0_12px_30px_rgba(15,23,42,0.18)] safe-pb">
                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.dashboard') ? $navOn : $navOff }}" href="{{ route('cabinet.dashboard') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 11.25L12 3l9.75 8.25M4.5 9.75V20.25h15V9.75M9.75 20.25v-6h4.5v6"/></svg>
                    Главная
                </a>
                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.accruals') || request()->routeIs('cabinet.payments') ? $navOn : $navOff }}" href="{{ route('cabinet.accruals') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12H3m0 0l4-4m-4 4l4 4M3 6h18M3 18h12"/></svg>
                    Финансы
                </a>
                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.requests*') || request()->routeIs('cabinet.customer-chat') ? $navOn : $navOff }}" href="{{ route('cabinet.requests') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h6m5 8l-4-3H6a2 2 0 01-2-2V6a2 2 0 012-2h12a2 2 0 012 2v9a2 2 0 01-2 2v3z"/></svg>
                    Общение
                </a>
                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.documents') ? $navOn : $navOff }}" href="{{ route('cabinet.documents') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a2 2 0 01-2-2V5a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5"/></svg>
                    Документы
                </a>
                <a class="{{ $navItem }} {{ request()->routeIs('cabinet.showcase.*') ? $navOn : $navOff }}" href="{{ route('cabinet.showcase.edit') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M6 7l1 12h10l1-12M9 7V5a3 3 0 016 0v2"/></svg>
                    Витрина
                </a>
            </div>
        </nav>
    </div>
</div>
</body>
</html>
