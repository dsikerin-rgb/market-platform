<x-cabinet-layout :tenant="$tenant" title="Главная">
    @php
        $latestPeriodLabel = $latestPeriod
            ? \Illuminate\Support\Carbon::parse($latestPeriod)->translatedFormat('F Y')
            : 'Без периода';
    @endphp

    <a
        href="{{ $canViewFinance ? route('cabinet.payments') : route('cabinet.showcase.edit') }}"
        class="block rounded-3xl bg-gradient-to-br from-sky-200 via-blue-200 to-cyan-200 text-slate-900 p-4 shadow-sm border border-sky-300/70"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Добро пожаловать</p>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $canViewFinance ? 'Начисления, оплаты и итог по ОСВ в одном месте.' : 'Можно помочь арендатору с витриной, товарами, фото, описаниями и общением.' }}
                </p>
            </div>
            <div class="rounded-2xl bg-white/92 border border-sky-200 px-3 py-2 text-xs text-slate-700 shadow-sm">
                {{ $latestPeriodLabel }}
            </div>
        </div>
    </a>

    <section class="grid grid-cols-2 gap-3">
        @if($canViewFinance)
            <a href="{{ route('cabinet.payments') }}" class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Текущая сумма начислений</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($totalDebt, 0, '.', ' ') }} ₽</p>
            </a>
        @else
            <a href="{{ route('cabinet.showcase.edit') }}" class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Витрина</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">Оформление</p>
            </a>
        @endif
        @if($canViewFinance)
            <a href="{{ route('cabinet.documents') }}" class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Документы / места</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) $documentsCount }} / {{ (int) $spacesCount }}</p>
            </a>
        @else
            <a href="{{ route('cabinet.spaces') }}" class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Торговые места</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) $spacesCount }}</p>
            </a>
        @endif
        @if($canViewFinance)
            <div class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Обеспечительный платёж (76.06)</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ number_format((float) ($securityDepositAmount ?? 0), 0, '.', ' ') }} ₽</p>
            </div>
        @else
            <a href="{{ route('cabinet.products.index') }}" class="block rounded-2xl bg-white border border-slate-200 p-3 shadow-sm">
                <p class="text-xs text-slate-500">Товары</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">Редактирование</p>
            </a>
        @endif
    </section>

    <section class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Быстрые действия</h3>
        </div>
        <div class="p-3 grid grid-cols-2 gap-2">
            @if($canViewFinance)
                <a href="{{ route('cabinet.payments') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Финансы</a>
                <a href="{{ route('cabinet.accruals') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Начисления</a>
            @endif
            <a href="{{ route('cabinet.requests') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Общение</a>
            @if($canViewFinance)
                <a href="{{ route('cabinet.documents') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Документы</a>
            @endif
            <a href="{{ route('cabinet.spaces') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Торговые места</a>
            <a href="{{ route('cabinet.products.index') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Товары</a>
            <a href="{{ route('cabinet.showcase.edit') }}" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-3 text-sm font-medium text-slate-800">Моя витрина</a>
        </div>
    </section>
</x-cabinet-layout>
