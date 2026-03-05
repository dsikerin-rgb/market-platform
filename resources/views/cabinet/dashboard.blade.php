<x-cabinet-layout :tenant="$tenant" title="Главная">
    @php
        $latestPeriodLabel = $latestPeriod
            ? \Illuminate\Support\Carbon::parse($latestPeriod)->translatedFormat('F Y')
            : 'Без периода';
    @endphp

    <section class="rounded-3xl bg-slate-900 text-white p-4 shadow-lg shadow-slate-900/20">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.14em] text-slate-300">Добро пожаловать</p>
                <h2 class="mt-1 text-xl font-semibold leading-tight">{{ $tenant->display_name ?? $tenant->name }}</h2>
                <p class="mt-1 text-sm text-slate-300">Основные данные по начислениям и обращениям в одном месте.</p>
            </div>
            <div class="rounded-2xl bg-white/10 px-3 py-2 text-xs text-slate-200">
                {{ $latestPeriodLabel }}
            </div>
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3">
        <article class="rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
            <p class="text-xs text-slate-500">Текущая сумма начислений</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($totalDebt, 0, '.', ' ') }} ₽</p>
        </article>
        <article class="rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
            <p class="text-xs text-slate-500">За последний месяц</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($monthAccruals, 0, '.', ' ') }} ₽</p>
        </article>
        <article class="rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
            <p class="text-xs text-slate-500">Открытые обращения</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) $openRequestsCount }}</p>
        </article>
        <article class="rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
            <p class="text-xs text-slate-500">Документы / места</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) $documentsCount }} / {{ (int) $spacesCount }}</p>
        </article>
    </section>

    <section class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Быстрые действия</h3>
        </div>
        <div class="p-3 grid grid-cols-2 gap-2">
            <a href="{{ route('cabinet.accruals') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Начисления</a>
            <a href="{{ route('cabinet.payments') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Оплата</a>
            <a href="{{ route('cabinet.requests') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Обращения</a>
            <a href="{{ route('cabinet.documents') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Документы</a>
            <a href="{{ route('cabinet.spaces') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Торговые места</a>
            <a href="{{ route('cabinet.showcase.edit') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Моя витрина</a>
        </div>
    </section>

    <section class="rounded-2xl border border-dashed border-slate-300 bg-white/70 px-4 py-3 text-xs text-slate-600">
        Совет: удобнее всего работать через телефон. Добавьте страницу на главный экран, чтобы кабинет открывался как мобильное приложение.
    </section>
</x-cabinet-layout>
