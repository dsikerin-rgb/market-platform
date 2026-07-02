{{-- PWA meta-теги для Filament-админки --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

<meta name="theme-color" content="#d97706">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Управление рынком">

<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

@php($adminFaviconUrl = app(\App\Support\MarketBrandAssets::class)->faviconUrlForCurrentAdmin())

{{-- Дополнительный favicon для PWA/старых браузеров (Filament ->favicon() уже добавляет основной link). --}}
<link rel="shortcut icon" href="{{ $adminFaviconUrl }}">
