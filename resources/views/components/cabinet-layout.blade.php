@props(['tenant', 'title' => null])
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Кабинет арендатора' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen flex flex-col">
        <header class="bg-white border-b">
            <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Кабинет арендатора</p>
                    <h1 class="text-lg font-semibold">{{ $tenant->display_name ?? 'Арендатор' }}</h1>
                </div>
                <form method="POST" action="{{ route('cabinet.logout') }}">
                    @csrf
                    <button class="text-sm text-slate-500 hover:text-slate-700" type="submit">Выйти</button>
                </form>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-3xl mx-auto px-4 py-6 space-y-6">
                @if(session('success'))
                    <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
                        {{ session('success') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>

        <nav class="bg-white border-t">
            <div class="max-w-3xl mx-auto px-4 py-3 grid grid-cols-5 gap-2 text-xs text-center">
                <a class="flex flex-col items-center gap-1 {{ request()->routeIs('cabinet.dashboard') ? 'text-slate-900' : 'text-slate-500' }}" href="{{ route('cabinet.dashboard') }}">
                    <span class="text-lg">🏠</span>
                    Главная
                </a>
                <a class="flex flex-col items-center gap-1 {{ request()->routeIs('cabinet.accruals') ? 'text-slate-900' : 'text-slate-500' }}" href="{{ route('cabinet.accruals') }}">
                    <span class="text-lg">💳</span>
                    Начисления
                </a>
                <a class="flex flex-col items-center gap-1 {{ request()->routeIs('cabinet.requests*') ? 'text-slate-900' : 'text-slate-500' }}" href="{{ route('cabinet.requests') }}">
                    <span class="text-lg">🛠️</span>
                    Заявки
                </a>
                <a class="flex flex-col items-center gap-1 {{ request()->routeIs('cabinet.documents') ? 'text-slate-900' : 'text-slate-500' }}" href="{{ route('cabinet.documents') }}">
                    <span class="text-lg">📄</span>
                    Документы
                </a>
                <a class="flex flex-col items-center gap-1 {{ request()->routeIs('cabinet.showcase.*') ? 'text-slate-900' : 'text-slate-500' }}" href="{{ route('cabinet.showcase.edit') }}">
                    <span class="text-lg">🛍️</span>
                    Витрина
                </a>
            </div>
        </nav>
    </div>
</body>
</html>
