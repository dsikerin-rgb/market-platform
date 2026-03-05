<x-cabinet-layout :tenant="$tenant" title="Начисления">
    @php
        $sumWithVat = (float) $accruals->sum('total_with_vat');
    @endphp

    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">Фильтр начислений</h2>
            <a class="text-sm font-medium text-slate-600" href="{{ route('cabinet.payments') }}">К оплате</a>
        </div>

        <form method="GET" class="space-y-3">
            <label class="block">
                <span class="text-xs text-slate-500">Месяц</span>
                <select name="month" class="mt-1.5 w-full rounded-2xl border-slate-200 bg-white px-4 py-3 text-sm">
                    <option value="">Все месяцы</option>
                    @foreach($availableMonths as $month)
                        <option value="{{ $month }}" @selected($month === $selectedMonth)>{{ $month }}</option>
                    @endforeach
                </select>
            </label>

            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="only_debt" value="1" class="rounded border-slate-300" @checked($onlyDebt)>
                Показать только начисления с долгом
            </label>

            <button class="w-full rounded-2xl bg-sky-600 text-white py-3 text-sm font-semibold" type="submit">
                Применить
            </button>
        </form>
    </section>

    <section class="rounded-2xl bg-sky-600 text-white px-4 py-3">
        <p class="text-xs text-slate-300">Сумма по выбранным начислениям</p>
        <p class="mt-1 text-xl font-semibold">{{ number_format($sumWithVat, 0, '.', ' ') }} ₽</p>
    </section>

    <section class="space-y-3">
        @forelse($accruals as $accrual)
            <article class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ $accrual->source_place_name ?: ($accrual->source_place_code ?: 'Торговое место') }}</p>
                        <p class="text-xs text-slate-500">Период: {{ $accrual->period?->format('m.Y') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-base font-semibold text-slate-900">{{ number_format((float) $accrual->total_with_vat, 0, '.', ' ') }} ₽</p>
                        <p class="text-xs text-slate-500">{{ (string) ($accrual->status ?: 'new') }}</p>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @if($accrual->rent_amount)
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Аренда: {{ number_format((float) $accrual->rent_amount, 0, '.', ' ') }} ₽</span>
                    @endif
                    @if($accrual->utilities_amount)
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Коммунальные: {{ number_format((float) $accrual->utilities_amount, 0, '.', ' ') }} ₽</span>
                    @endif
                    @if($accrual->management_fee)
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Управление: {{ number_format((float) $accrual->management_fee, 0, '.', ' ') }} ₽</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-6 text-sm text-slate-500">
                Начислений пока нет.
            </div>
        @endforelse
    </section>
</x-cabinet-layout>
