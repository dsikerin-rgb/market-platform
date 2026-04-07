{{-- PWA meta-теги для Filament-админки --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

<meta name="theme-color" content="#d97706">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Управление рынком">

<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

{{-- Дополнительный favicon 16x16 (Filament ->favicon() уже добавляет 32x32) --}}
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
