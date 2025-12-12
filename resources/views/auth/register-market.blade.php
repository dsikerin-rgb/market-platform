<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация рынка</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
</head>

<body class="min-h-screen bg-gradient-to-b from-gray-100 to-indigo-50">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-2xl">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Создание рынка</h1>
                        <p class="mt-1 text-sm text-gray-600">Создадим рынок и первого администратора за 1 минуту.</p>
                    </div>

                    <a href="/admin/login" class="text-sm text-indigo-600 hover:text-indigo-700">
                        Войти
                    </a>
                </div>

                <!-- Steps -->
                <div class="mt-5 flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-600 text-white text-sm font-semibold">1</span>
                        <span class="text-sm font-medium text-gray-800">Рынок</span>
                    </div>
                    <div class="h-px flex-1 bg-gray-200"></div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-200 text-gray-700 text-sm font-semibold">2</span>
                        <span class="text-sm font-medium text-gray-800">Администратор</span>
                    </div>
                </div>
            </div>

            <!-- Card -->
            <div class="bg-white shadow-lg border border-gray-200 rounded-2xl overflow-hidden">
                @if ($errors->any())
                    <div class="px-6 py-4 bg-red-50 border-b border-red-100">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5">
                                <svg class="w-5 h-5 text-red-600" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v4.5a.75.75 0 001.5 0v-4.5zM10 14.5a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-red-800">Проверьте поля формы</p>
                                <p class="mt-1 text-sm text-red-700">Ошибки подсвечены под соответствующими полями.</p>
                            </div>
                        </div>
                    </div>
                @endif

                @php
                    $controlClasses = 'mt-1 block w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 leading-6 text-gray-900 placeholder-gray-500 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200';
                @endphp

                <form method="POST" action="{{ route('market.register.store') }}" class="px-6 py-6">
                    @csrf

                    <!-- Section: Market -->
                    <div>
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Данные рынка</h2>
                            <span class="text-xs text-gray-500">Шаг 1</span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="market_name" class="block text-sm font-medium text-gray-700">
                                    Название рынка <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="market_name"
                                    name="market_name"
                                    type="text"
                                    value="{{ old('market_name') }}"
                                    required
                                    placeholder="Например: Экоярмарка ВДНХ"
                                    class="{{ $controlClasses }}"
                                >
                                @error('market_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label for="market_address" class="block text-sm font-medium text-gray-700">
                                    Адрес
                                </label>
                                <input
                                    id="market_address"
                                    name="market_address"
                                    type="text"
                                    value="{{ old('market_address') }}"
                                    placeholder="Город, улица, дом (необязательно)"
                                    class="{{ $controlClasses }}"
                                >
                                @error('market_address')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Advanced -->
                        <div class="mt-4">
                            <details class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800">
                                    Дополнительно
                                    <span class="ml-2 text-xs font-normal text-gray-500">(можно оставить по умолчанию)</span>
                                </summary>

                                <div class="mt-4">
                                    <label for="market_timezone" class="block text-sm font-medium text-gray-700">
                                        Часовой пояс <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="market_timezone"
                                        name="market_timezone"
                                        required
                                        class="{{ $controlClasses }}"
                                    >
                                        @foreach ($timezones as $value => $label)
                                            <option value="{{ $value }}" @selected(old('market_timezone', 'Europe/Moscow') === $value)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Нужен для корректных дат/отчётов/расписаний и дедлайнов заявок.
                                    </p>
                                    @error('market_timezone')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="my-8 border-t border-gray-200"></div>

                    <!-- Section: User -->
                    <div>
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Администратор</h2>
                            <span class="text-xs text-gray-500">Шаг 2</span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="name" class="block text-sm font-medium text-gray-700">
                                    Имя / ФИО <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    value="{{ old('name') }}"
                                    required
                                    placeholder="Например: Иван Петров"
                                    class="{{ $controlClasses }}"
                                >
                                @error('name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="md:col-span-2">
                                <label for="email" class="block text-sm font-medium text-gray-700">
                                    Email <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    value="{{ old('email') }}"
                                    required
                                    placeholder="you@company.ru"
                                    class="{{ $controlClasses }}"
                                    autocomplete="email"
                                >
                                <p class="mt-1 text-xs text-gray-500">На этот email вы будете входить в панель управления.</p>
                                @error('email')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">
                                    Пароль <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    class="{{ $controlClasses }}"
                                    autocomplete="new-password"
                                >
                                <p class="mt-1 text-xs text-gray-500">Минимум 8 символов.</p>
                                @error('password')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                                    Подтверждение <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    class="{{ $controlClasses }}"
                                    autocomplete="new-password"
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <p class="text-xs text-gray-500">
                            Нажимая «Создать рынок», вы создаёте рынок и первого администратора.
                        </p>

                        <button
                            type="submit"
                            onclick="this.disabled=true; this.innerText='Создаём…'; this.form.submit();"
                            class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            Создать рынок
                        </button>
                    </div>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-gray-500">
                Если вы уже регистрировались — используйте вход в админку.
            </p>
        </div>
    </div>
</body>
</html>
