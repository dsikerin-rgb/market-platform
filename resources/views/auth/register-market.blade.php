<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация рынка</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-3xl w-full bg-white shadow-md rounded-lg p-8">
            <h1 class="text-2xl font-bold mb-6">Регистрация рынка и администратора</h1>

            @if ($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('market.register.store') }}" class="space-y-6">
                @csrf

                <div class="border border-gray-200 rounded-lg p-4">
                    <h2 class="text-lg font-semibold mb-4">Рынок</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="market_name" class="block text-sm font-medium text-gray-700">Название рынка *</label>
                            <input id="market_name" name="market_name" type="text" value="{{ old('market_name') }}" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="market_code" class="block text-sm font-medium text-gray-700">Код рынка</label>
                            <input id="market_code" name="market_code" type="text" value="{{ old('market_code') }}" class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="market_address" class="block text-sm font-medium text-gray-700">Адрес</label>
                            <input id="market_address" name="market_address" type="text" value="{{ old('market_address') }}" class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="market_timezone" class="block text-sm font-medium text-gray-700">Часовой пояс *</label>
                            <select id="market_timezone" name="market_timezone" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Выберите часовой пояс</option>
                                @foreach ($timezones as $value => $label)
                                    <option value="{{ $value }}" @selected(old('market_timezone', 'Europe/Moscow') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg p-4">
                    <h2 class="text-lg font-semibold mb-4">Пользователь</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700">Имя / ФИО *</label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">Пароль *</label>
                            <input id="password" name="password" type="password" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Подтверждение пароля *</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required class="mt-1 block w-full rounded border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">Зарегистрироваться</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
