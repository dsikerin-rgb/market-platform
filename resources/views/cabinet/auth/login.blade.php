<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — Кабинет арендатора</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-sm p-6 space-y-6">
            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-slate-400">Кабинет арендатора</p>
                <h1 class="text-2xl font-semibold">Вход</h1>
                <p class="text-sm text-slate-500">Используйте email и пароль, выданные администратором.</p>
            </div>

            @if($errors->any())
                <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('cabinet.login.submit') }}" class="space-y-4">
                @csrf
                <label class="block">
                    <span class="text-sm text-slate-600">Email</span>
                    <input class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-300" type="email" name="email" value="{{ old('email') }}" required>
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Пароль</span>
                    <input class="mt-1 w-full rounded-xl border-slate-200 focus:border-slate-400 focus:ring-slate-300" type="password" name="password" required>
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-500">
                    <input type="checkbox" name="remember" class="rounded border-slate-300">
                    Запомнить меня
                </label>
                <button class="w-full rounded-xl bg-slate-900 text-white py-2.5 text-sm font-medium" type="submit">Войти</button>
            </form>
        </div>
    </div>
</body>
</html>
