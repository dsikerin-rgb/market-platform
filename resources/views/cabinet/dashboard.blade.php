<x-cabinet-layout :tenant="$tenant" title="Главная">

    {{-- KPI cards --}}
    <section class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-2xl bg-white border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                        Текущий долг
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">
                        {{ number_format($totalDebt, 0, '.', ' ') }} ₽
                    </div>
                    <div class="mt-1 text-sm text-slate-500">
                        Суммарно по аренде
                    </div>
                </div>

                <div class="shrink-0 rounded-2xl bg-slate-100 border border-slate-200 px-3 py-2 text-lg leading-none">
                    ₽
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                        За месяц
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">
                        {{ number_format($monthAccruals, 0, '.', ' ') }} ₽
                    </div>

                    <div class="mt-1 text-sm text-slate-500">
                        @if($latestPeriod)
                            Период: {{ \Illuminate\Support\Carbon::parse($latestPeriod)->format('m.Y') }}
                        @else
                            Период не выбран
                        @endif
                    </div>
                </div>

                <div class="shrink-0 rounded-2xl bg-slate-100 border border-slate-200 px-3 py-2 text-lg leading-none">
                    📅
                </div>
            </div>
        </div>
    </section>

    {{-- Section title --}}
    <div class="px-1 pt-2">
        <div class="text-xs font-semibold tracking-wide text-slate-400 uppercase">
            Разделы
        </div>
    </div>

    {{-- Mobile list --}}
    <section class="overflow-hidden rounded-2xl bg-white border border-slate-200 shadow-sm divide-y divide-slate-100">
        @php
            $cellBase = 'flex items-center gap-3 px-4 py-3 transition tap';
            $cellHover = 'hover:bg-slate-50 active:bg-slate-100';
            $icoBase = 'h-10 w-10 rounded-2xl bg-slate-100 border border-slate-200 flex items-center justify-center text-lg leading-none shrink-0';
            $chev = 'shrink-0 text-slate-300 text-2xl leading-none';
        @endphp

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.accruals') }}">
            <div class="{{ $icoBase }}">💳</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Начисления</div>
                <div class="text-sm text-slate-500 truncate">История начислений и оплата</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.requests') }}">
            <div class="{{ $icoBase }}">🛠️</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Мои заявки</div>
                <div class="text-sm text-slate-500 truncate">Создавайте обращения и ведите диалог</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.documents') }}">
            <div class="{{ $icoBase }}">📄</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Документы</div>
                <div class="text-sm text-slate-500 truncate">Договоры, акты и прочее</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.spaces') }}">
            <div class="{{ $icoBase }}">🏪</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Торговые места</div>
                <div class="text-sm text-slate-500 truncate">Ваши точки и договор аренды</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.customer-chat') }}">
            <div class="{{ $icoBase }}">💬</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Чат с покупателями</div>
                <div class="text-sm text-slate-500 truncate">Демо-экран с примером диалога</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>

        <a class="{{ $cellBase }} {{ $cellHover }}" href="{{ route('cabinet.showcase.edit') }}">
            <div class="{{ $icoBase }}">🛍️</div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900">Моя витрина</div>
                <div class="text-sm text-slate-500 truncate">Визитка арендатора и фото</div>
            </div>
            <div class="{{ $chev }}">›</div>
        </a>
    </section>

</x-cabinet-layout>
