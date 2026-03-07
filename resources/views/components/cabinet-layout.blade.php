@props(['tenant' => null, 'title' => null])

@php
    use App\Models\User;
    use App\Services\Auth\PortalAccessService;
    use App\Services\Cabinet\TenantImpersonationService;
    use Illuminate\Support\Facades\Route;

    $viteHot = file_exists(public_path('hot'));
    $viteManifest = file_exists(public_path('build/manifest.json'));
    $useVite = $viteHot || (! app()->environment('local') && $viteManifest);

    $tenantName = data_get($tenant, 'display_name')
        ?: data_get($tenant, 'name')
        ?: 'Арендатор';

    $marketName = data_get($tenant, 'market.name');
    if (! $marketName && data_get($tenant, 'market_id')) {
        $marketName = \App\Models\Market::query()->whereKey((int) data_get($tenant, 'market_id'))->value('name');
    }
    $marketName = $marketName ?: config('app.name', 'Market Platform');

    $impersonation = session(TenantImpersonationService::SESSION_KEY);
    $isImpersonation = is_array($impersonation) && ! empty($impersonation['impersonator_user_id']);
    $isDashboard = request()->routeIs('cabinet.dashboard');
    $unreadCommunicationCount = 0;

    $authUser = auth()->user();
    $portalAccess = app(PortalAccessService::class);
    $portalUser = $authUser instanceof User ? $authUser : null;
    $authUserName = $portalUser ? trim((string) ($portalUser->name ?? '')) : '';
    $showAuthUserName = $authUserName !== '' && mb_strtolower($authUserName) !== mb_strtolower($tenantName);

    $cabinetCurrentUserCanUseBuyer = false;
    $cabinetCurrentUserCanSellPublicly = false;
    $cabinetMarketplaceUrl = null;

    if ($portalUser && ! $isImpersonation) {
        $userMarket = $portalAccess->resolveUserMarket($portalUser);
        $cabinetCurrentUserCanUseBuyer = $portalAccess->canUseMarketplaceBuyer($portalUser, $userMarket);
        $cabinetCurrentUserCanSellPublicly = $portalAccess->canSellOnMarketplace($portalUser, $userMarket);

        if ($cabinetCurrentUserCanUseBuyer) {
            $marketRouteKey = $portalAccess->resolveUserMarketRouteKey($portalUser);
            if ($marketRouteKey !== null) {
                $cabinetMarketplaceUrl = route('marketplace.buyer.dashboard', ['marketSlug' => $marketRouteKey]);
            }
        }
    }

    $showSellerShadowState = $portalUser && ! $isImpersonation && ! $cabinetCurrentUserCanSellPublicly;

    if ($portalUser && (int) ($portalUser->tenant_id ?? 0) > 0) {
        try {
            $seenAt = (string) session('cabinet.communication_seen_at', '1970-01-01 00:00:00');
            $tenantId = (int) $portalUser->tenant_id;
            $marketId = (int) ($portalUser->market_id ?? 0);

            $unreadCommunicationCount = \App\Models\TicketComment::query()
                ->where('user_id', '<>', (int) $portalUser->id)
                ->where('created_at', '>', $seenAt)
                ->whereHas('ticket', function ($query) use ($tenantId, $marketId) {
                    $query->where('tenant_id', $tenantId)
                        ->when($marketId > 0, fn ($q) => $q->where('market_id', $marketId));
                })
                ->count();
        } catch (\Throwable) {
            $unreadCommunicationCount = 0;
        }
    }
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

    <style>
        :root {
            --cabinet-shell-max-width: 28rem;
            --cabinet-content-bottom: 10rem;
        }

        body[data-device='mobile'] {
            --cabinet-shell-max-width: 28rem;
            --cabinet-content-bottom: 10rem;
        }

        body[data-device='tablet'] {
            --cabinet-shell-max-width: 64rem;
            --cabinet-content-bottom: 2.5rem;
        }

        body[data-device='desktop'] {
            --cabinet-shell-max-width: 76rem;
            --cabinet-content-bottom: 2.75rem;
        }

        .cabinet-shell {
            width: 100%;
            max-width: var(--cabinet-shell-max-width);
            margin-inline: auto;
        }

        .cabinet-main {
            padding-bottom: var(--cabinet-content-bottom);
        }

        .cabinet-top-nav {
            display: none;
        }

        .cabinet-top-nav__link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            border-radius: 0.9rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(248, 250, 252, 0.85);
            color: rgb(51 65 85);
            font-size: 0.95rem;
            font-weight: 600;
            transition: 0.18s ease;
        }

        .cabinet-top-nav__link:hover {
            background: rgba(241, 245, 249, 0.98);
        }

        .cabinet-top-nav__link.is-active {
            border-color: rgba(2, 132, 199, 0.45);
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.14), rgba(56, 189, 248, 0.18));
            color: rgb(14 116 144);
            box-shadow: 0 8px 22px rgba(14, 165, 233, 0.14);
        }

        .cabinet-bottom-nav {
            padding-bottom: calc(env(safe-area-inset-bottom) + 8px);
        }

        .cabinet-bottom-nav__inner {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.25rem;
            padding: 0.375rem;
            border-radius: 1.25rem;
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.35);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
            .cabinet-bottom-nav__inner {
                background: rgba(255, 255, 255, 0.88);
            }
        }

        .cabinet-bottom-nav__item {
            min-width: 0;
            overflow: hidden;
            height: 56px;
            padding: 4px 2px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            font-size: 11px;
            line-height: 1;
            font-weight: 600;
            text-align: center;
        }

        .cabinet-bottom-nav__icon {
            width: 18px;
            height: 18px;
            flex: 0 0 auto;
        }

        .cabinet-bottom-nav__label {
            display: block;
            width: 100%;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (max-width: 360px) {
            .cabinet-bottom-nav__item {
                height: 52px;
                font-size: 10px;
            }

            .cabinet-bottom-nav__icon {
                width: 17px;
                height: 17px;
            }
        }

        @media (min-width: 768px) {
            .cabinet-top-nav {
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 0.5rem;
                margin-top: 1rem;
            }

            .cabinet-bottom-nav {
                display: none;
            }
        }
    </style>

    @stack('head')
</head>

<body class="bg-slate-100 text-slate-900 antialiased tap overflow-hidden" data-device="mobile">
<div class="h-screen bg-gradient-to-b from-sky-200/65 via-slate-100 to-slate-200">
    <div class="cabinet-shell h-screen relative flex flex-col overflow-hidden">
        <header class="sticky top-0 z-30 px-3 pt-3">
            <div class="rounded-3xl border border-slate-200/90 bg-white/95 backdrop-blur shadow-[0_10px_24px_rgba(15,23,42,0.10)] px-4 py-3 md:px-5 md:py-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        @if($isDashboard)
                            <div class="inline-flex max-w-full items-center rounded-full border border-sky-800/30 bg-sky-700 text-sky-50 text-[11px] px-2.5 py-1 font-semibold truncate shadow-sm">
                                {{ $marketName }}
                            </div>
                            <h1 class="mt-2 text-base font-semibold leading-tight truncate md:text-xl">
                                {{ $tenantName }}
                            </h1>
                            @if ($showAuthUserName)
                                <p class="mt-1 text-sm text-slate-700 truncate">{{ $authUserName }}</p>
                            @endif
                            @if ($showSellerShadowState)
                                <p class="mt-2 text-xs font-semibold text-amber-700">
                                    Публикация на маркетплейсе приостановлена: нет активного договора аренды.
                                </p>
                            @endif
                        @else
                            <h1 class="text-base font-semibold leading-tight truncate md:text-xl">
                                {{ $title ?? 'Кабинет арендатора' }}
                            </h1>
                            @if ($showSellerShadowState)
                                <p class="mt-2 text-xs font-semibold text-amber-700">
                                    Публикация на маркетплейсе приостановлена: нет активного договора аренды.
                                </p>
                            @endif
                        @endif
                    </div>

                    @if (auth()->check() && (Route::has('cabinet.logout') || Route::has('cabinet.impersonation.exit')))
                        <div class="shrink-0 flex items-center gap-2">
                            @if ($cabinetMarketplaceUrl)
                                <a
                                    href="{{ $cabinetMarketplaceUrl }}"
                                    class="inline-flex h-10 items-center justify-center rounded-xl border border-sky-300 bg-sky-50 px-3.5 text-sky-700 text-sm font-semibold"
                                >
                                    Покупки
                                </a>
                            @endif

                            @if (Route::has('cabinet.requests.create'))
                                <a
                                    href="{{ route('cabinet.requests.create', ['category' => 'help']) }}"
                                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-sky-300 bg-sky-50 text-sky-700 text-base font-bold"
                                    aria-label="Помощь"
                                    title="Помощь"
                                >?</a>
                            @endif

                            @if ($isImpersonation)
                                @if (Route::has('cabinet.impersonation.exit'))
                                    <form method="POST" action="{{ route('cabinet.impersonation.exit') }}" data-navigate="false">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-amber-300 bg-amber-100 px-3.5 text-amber-900 text-sm font-semibold"
                                            aria-label="Вернуться в админку"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 10l-5 5m0 0l5 5m-5-5h12a4 4 0 004-4V4" />
                                            </svg>
                                            <span>Выход</span>
                                        </button>
                                    </form>
                                @endif
                            @else
                                <form method="POST" action="{{ route('cabinet.logout') }}" data-navigate="false">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 text-slate-700 hover:text-slate-900 text-sm font-semibold"
                                        aria-label="Выйти"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1" />
                                        </svg>
                                        <span>Выход</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>

                <nav class="cabinet-top-nav">
                    <a
                        href="{{ route('cabinet.dashboard') }}"
                        @class(['cabinet-top-nav__link', 'is-active' => request()->routeIs('cabinet.dashboard')])
                    >Главная</a>
                    <a
                        href="{{ route('cabinet.accruals') }}"
                        @class(['cabinet-top-nav__link', 'is-active' => request()->routeIs('cabinet.accruals') || request()->routeIs('cabinet.payments')])
                    >Финансы</a>
                    <a
                        href="{{ route('cabinet.requests') }}"
                        @class(['cabinet-top-nav__link', 'is-active' => request()->routeIs('cabinet.requests*') || request()->routeIs('cabinet.customer-chat')])
                    >Общение</a>
                    <a
                        href="{{ route('cabinet.documents') }}"
                        @class(['cabinet-top-nav__link', 'is-active' => request()->routeIs('cabinet.documents')])
                    >Документы</a>
                    <a
                        href="{{ route('cabinet.showcase.edit') }}"
                        @class(['cabinet-top-nav__link', 'is-active' => request()->routeIs('cabinet.showcase.*')])
                    >Витрина</a>
                </nav>
            </div>
        </header>

        <main class="cabinet-main flex-1 overflow-y-auto px-3 pt-3 pb-40 md:pb-8">
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

        <nav class="cabinet-bottom-nav fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md z-30 px-3 pt-2 md:hidden">
            <div class="cabinet-bottom-nav__inner rounded-3xl shadow-[0_12px_30px_rgba(15,23,42,0.18)] safe-pb">
                <a
                    href="{{ route('cabinet.dashboard') }}"
                    @class([
                        'cabinet-bottom-nav__item group rounded-2xl transition-colors transition-shadow duration-200',
                        'bg-sky-600 text-white ring-1 ring-sky-700/70 shadow-[0_4px_14px_rgba(2,132,199,0.35)]' => request()->routeIs('cabinet.dashboard'),
                        'text-slate-700 hover:text-slate-900 hover:bg-slate-100/90' => ! request()->routeIs('cabinet.dashboard'),
                    ])
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="cabinet-bottom-nav__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 11.25L12 3l9.75 8.25M4.5 9.75V20.25h15V9.75M9.75 20.25v-6h4.5v6"/></svg>
                    <span class="cabinet-bottom-nav__label">Главная</span>
                </a>
                <a
                    href="{{ route('cabinet.accruals') }}"
                    @class([
                        'cabinet-bottom-nav__item group rounded-2xl transition-colors transition-shadow duration-200',
                        'bg-sky-600 text-white ring-1 ring-sky-700/70 shadow-[0_4px_14px_rgba(2,132,199,0.35)]' => request()->routeIs('cabinet.accruals') || request()->routeIs('cabinet.payments'),
                        'text-slate-700 hover:text-slate-900 hover:bg-slate-100/90' => ! (request()->routeIs('cabinet.accruals') || request()->routeIs('cabinet.payments')),
                    ])
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="cabinet-bottom-nav__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12H3m0 0l4-4m-4 4l4 4M3 6h18M3 18h12"/></svg>
                    <span class="cabinet-bottom-nav__label">Финансы</span>
                </a>
                <a
                    href="{{ route('cabinet.requests') }}"
                    @class([
                        'cabinet-bottom-nav__item group rounded-2xl transition-colors transition-shadow duration-200',
                        'bg-sky-600 text-white ring-1 ring-sky-700/70 shadow-[0_4px_14px_rgba(2,132,199,0.35)]' => request()->routeIs('cabinet.requests*') || request()->routeIs('cabinet.customer-chat'),
                        'text-slate-700 hover:text-slate-900 hover:bg-slate-100/90' => ! (request()->routeIs('cabinet.requests*') || request()->routeIs('cabinet.customer-chat')),
                    ])
                >
                    <span class="relative inline-flex">
                        <svg xmlns="http://www.w3.org/2000/svg" class="cabinet-bottom-nav__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h6m5 8l-4-3H6a2 2 0 01-2-2V6a2 2 0 012-2h12a2 2 0 012 2v9a2 2 0 01-2 2v3z"/></svg>
                        @if($unreadCommunicationCount > 0)
                            <span class="absolute -top-1.5 -right-2 inline-flex min-w-[1rem] h-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-semibold text-white leading-none">
                                {{ $unreadCommunicationCount > 99 ? '99+' : $unreadCommunicationCount }}
                            </span>
                        @endif
                    </span>
                    <span class="cabinet-bottom-nav__label">Общение</span>
                </a>
                <a
                    href="{{ route('cabinet.documents') }}"
                    @class([
                        'cabinet-bottom-nav__item group rounded-2xl transition-colors transition-shadow duration-200',
                        'bg-sky-600 text-white ring-1 ring-sky-700/70 shadow-[0_4px_14px_rgba(2,132,199,0.35)]' => request()->routeIs('cabinet.documents'),
                        'text-slate-700 hover:text-slate-900 hover:bg-slate-100/90' => ! request()->routeIs('cabinet.documents'),
                    ])
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="cabinet-bottom-nav__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a2 2 0 01-2-2V5a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5"/></svg>
                    <span class="cabinet-bottom-nav__label">Документы</span>
                </a>
                <a
                    href="{{ route('cabinet.showcase.edit') }}"
                    @class([
                        'cabinet-bottom-nav__item group rounded-2xl transition-colors transition-shadow duration-200',
                        'bg-sky-600 text-white ring-1 ring-sky-700/70 shadow-[0_4px_14px_rgba(2,132,199,0.35)]' => request()->routeIs('cabinet.showcase.*'),
                        'text-slate-700 hover:text-slate-900 hover:bg-slate-100/90' => ! request()->routeIs('cabinet.showcase.*'),
                    ])
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="cabinet-bottom-nav__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M6 7l1 12h10l1-12M9 7V5a3 3 0 016 0v2"/></svg>
                    <span class="cabinet-bottom-nav__label">Витрина</span>
                </a>
            </div>
        </nav>
    </div>
</div>
<script>
    (function () {
        function resolveDevice() {
            const width = window.innerWidth || document.documentElement.clientWidth || 0;
            if (width <= 767) return 'mobile';
            if (width <= 1199) return 'tablet';
            return 'desktop';
        }

        function applyDeviceFlag() {
            document.body.dataset.device = resolveDevice();
        }

        applyDeviceFlag();
        window.addEventListener('resize', applyDeviceFlag, { passive: true });
        window.addEventListener('orientationchange', applyDeviceFlag, { passive: true });
    })();
</script>
</body>
</html>
