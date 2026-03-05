<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Маркетплейс') - {{ $market->name ?? 'Экоярмарка' }}</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --text: #11203b;
            --muted: #5d6b86;
            --line: #dbe6f4;
            --brand: #0a84d6;
            --brand-2: #10b2d8;
            --accent: #ff8a00;
            --ok: #18a957;
            --danger: #d64045;
            --radius: 16px;
            --shadow: 0 8px 24px rgba(13, 40, 78, .08);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--bg); color: var(--text); font-family: "Segoe UI", "Inter", Arial, sans-serif; }
        a { color: inherit; text-decoration: none; }
        .mp-shell { max-width: 1380px; margin: 0 auto; padding: 16px 16px 92px; }
        .mp-top {
            background: linear-gradient(140deg, #ffffff, #eef6ff);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 14px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 10px;
            z-index: 50;
        }
        .mp-top-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .mp-logo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 900;
            letter-spacing: .2px;
        }
        .mp-logo-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            box-shadow: 12px 0 0 #14c566, 6px -10px 0 #ff4f7a;
            margin-right: 16px;
        }
        .mp-market-chip {
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 13px;
            background: #e7f5ff;
            border: 1px solid #b8dffd;
            white-space: nowrap;
            max-width: 55vw;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mp-actions { display: flex; align-items: center; gap: 8px; }
        .mp-btn {
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--text);
            border-radius: 12px;
            padding: 9px 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: .18s ease;
        }
        .mp-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(10, 132, 214, .15); }
        .mp-btn-brand {
            background: linear-gradient(140deg, var(--brand), var(--brand-2));
            border-color: transparent;
            color: #fff;
        }
        .mp-search-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
        }
        .mp-search {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            padding: 6px;
            border: 1px solid #c8dcf5;
            border-radius: 14px;
            background: #fff;
        }
        .mp-search input {
            border: none;
            outline: none;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 15px;
            color: var(--text);
            background: transparent;
        }
        .mp-cats {
            display: flex;
            gap: 8px;
            overflow: auto;
            padding: 10px 2px 2px;
            margin: 10px 0 0;
            scrollbar-width: thin;
        }
        .mp-cat {
            border: 1px solid #c9dcf3;
            background: #fff;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            white-space: nowrap;
            color: #24446d;
        }
        .mp-main { margin-top: 16px; display: grid; gap: 16px; }
        .mp-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: 0 6px 20px rgba(21, 53, 95, .06);
            padding: 16px;
        }
        .mp-card h2, .mp-card h3 { margin: 0 0 10px; }
        .mp-muted { color: var(--muted); }
        .mp-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }
        .mp-flash {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .mp-flash-success { background: #ecfbf2; border-color: #9fe0bb; color: #0f6d38; }
        .mp-flash-error { background: #fff2f2; border-color: #ffb2b2; color: #8c1c1f; }
        .mp-bottom {
            position: fixed;
            left: 10px;
            right: 10px;
            bottom: 10px;
            z-index: 60;
            display: none;
            padding: 8px;
            border-radius: 18px;
            border: 1px solid rgba(172, 199, 228, .8);
            background: rgba(255, 255, 255, .95);
            backdrop-filter: blur(16px);
            box-shadow: 0 8px 30px rgba(16, 53, 97, .18);
        }
        .mp-bottom nav {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 6px;
        }
        .mp-bottom a {
            border-radius: 12px;
            text-align: center;
            padding: 8px 4px;
            font-size: 12px;
            color: #50627f;
            font-weight: 600;
        }
        .mp-bottom a.is-active {
            color: #fff;
            background: linear-gradient(140deg, var(--brand), var(--brand-2));
        }
        .mp-page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .mp-page-title { margin: 0; font-size: clamp(22px, 3.6vw, 34px); line-height: 1.1; }
        .mp-page-sub { margin: 4px 0 0; color: var(--muted); }
        .mp-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #bed9f7;
            color: #1c4d81;
            background: #f0f8ff;
            border-radius: 999px;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        @media (max-width: 1100px) { .mp-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 820px) {
            .mp-shell { padding: 12px 12px 96px; }
            .mp-top { position: static; padding: 12px; border-radius: 16px; }
            .mp-search-row { grid-template-columns: 1fr; }
            .mp-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mp-actions .mp-btn span { display: none; }
            .mp-actions .mp-btn { padding: 9px 10px; }
            .mp-bottom { display: block; }
        }
        @media (max-width: 560px) {
            .mp-grid { grid-template-columns: 1fr; }
            .mp-market-chip { max-width: 38vw; }
            .mp-logo-label { max-width: 34vw; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }
    </style>
    @stack('head')
</head>
<body>
@php($marketRouteKey = filled($market->slug ?? null) ? (string) $market->slug : (string) $market->id)
<div class="mp-shell">
    <header class="mp-top">
        <div class="mp-top-line">
            <a href="{{ route('marketplace.home', ['marketSlug' => $marketRouteKey]) }}" class="mp-logo">
                <span class="mp-logo-dot"></span>
                <span class="mp-logo-label">МАРКЕТПЛЕЙС ЭКОЯРМАРКИ</span>
            </a>
            <div class="mp-actions">
                <a class="mp-btn" href="{{ route('marketplace.map', ['marketSlug' => $marketRouteKey]) }}">🗺 <span>Карта</span></a>
                <a class="mp-btn" href="{{ route('marketplace.announcements', ['marketSlug' => $marketRouteKey]) }}">📢 <span>Анонсы</span></a>
                @if($marketplaceCurrentUserIsBuyer)
                    <a class="mp-btn" href="{{ route('marketplace.buyer.chats', ['marketSlug' => $marketRouteKey]) }}">
                        💬 <span>Чаты{{ $marketplaceChatUnreadCount > 0 ? ' (' . $marketplaceChatUnreadCount . ')' : '' }}</span>
                    </a>
                    <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.buyer.dashboard', ['marketSlug' => $marketRouteKey]) }}">
                        👤 <span>Кабинет</span>
                    </a>
                @else
                    <a class="mp-btn mp-btn-brand" href="{{ route('marketplace.login', ['marketSlug' => $marketRouteKey]) }}">
                        👤 <span>Войти</span>
                    </a>
                @endif
            </div>
        </div>
        <div class="mp-search-row">
            <form class="mp-search" method="get" action="{{ route('marketplace.catalog', ['marketSlug' => $marketRouteKey]) }}">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Поиск товаров, магазинов, предложений">
                <button class="mp-btn mp-btn-brand" type="submit">Найти</button>
            </form>
            @if($marketplaceCurrentUserIsBuyer)
                <form method="post" action="{{ route('marketplace.logout', ['marketSlug' => $marketRouteKey]) }}">
                    @csrf
                    <button class="mp-btn" type="submit">Выйти</button>
                </form>
            @endif
        </div>
        @if(isset($topCategories) && $topCategories->count() > 0)
            <div class="mp-cats">
                @foreach($topCategories as $cat)
                    <a class="mp-cat" href="{{ route('marketplace.catalog', ['marketSlug' => $marketRouteKey, 'category' => $cat->slug]) }}">
                        {{ $cat->icon ? $cat->icon . ' ' : '' }}{{ $cat->name }}
                    </a>
                @endforeach
            </div>
        @endif
    </header>

    <main class="mp-main">
        @if(session('success'))
            <div class="mp-flash mp-flash-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="mp-flash mp-flash-error">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')
    </main>
</div>

<div class="mp-bottom">
    <nav>
        <a class="{{ request()->routeIs('marketplace.home') ? 'is-active' : '' }}" href="{{ route('marketplace.home', ['marketSlug' => $marketRouteKey]) }}">Главная</a>
        <a class="{{ request()->routeIs('marketplace.catalog') ? 'is-active' : '' }}" href="{{ route('marketplace.catalog', ['marketSlug' => $marketRouteKey]) }}">Каталог</a>
        <a class="{{ request()->routeIs('marketplace.map') ? 'is-active' : '' }}" href="{{ route('marketplace.map', ['marketSlug' => $marketRouteKey]) }}">Карта</a>
        <a class="{{ request()->routeIs('marketplace.buyer.chats*') ? 'is-active' : '' }}" href="{{ $marketplaceCurrentUserIsBuyer ? route('marketplace.buyer.chats', ['marketSlug' => $marketRouteKey]) : route('marketplace.login', ['marketSlug' => $marketRouteKey]) }}">
            Общение
        </a>
        <a class="{{ request()->routeIs('marketplace.buyer.*') ? 'is-active' : '' }}" href="{{ $marketplaceCurrentUserIsBuyer ? route('marketplace.buyer.dashboard', ['marketSlug' => $marketRouteKey]) : route('marketplace.login', ['marketSlug' => $marketRouteKey]) }}">
            Кабинет
        </a>
    </nav>
</div>
@stack('scripts')
</body>
</html>
