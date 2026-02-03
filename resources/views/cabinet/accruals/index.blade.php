<x-cabinet-layout :tenant="$tenant" title="Начисления">
    <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Начисления</h2>
            <a class="text-sm text-slate-500" href="{{ route('cabinet.payments') }}">Оплатить →</a>
        </div>

        <form method="GET" class="grid gap-3">
            <label class="block">
                <span class="text-xs text-slate-500">Период</span>
                <select name="month" class="mt-1 w-full rounded-xl border-slate-200">
                    <option value="">Все месяцы</option>
                    @foreach($availableMonths as $month)
                        <option value="{{ $month }}" @selected($month === $selectedMonth)>{{ $month }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="only_debt" value="1" class="rounded border-slate-300" @checked($onlyDebt)>
                Только начисления с долгом
            </label>
            <button class="rounded-xl bg-slate-900 text-white py-2 text-sm" type="submit">Применить фильтр</button>
        </form>
    </div>

    <div class="space-y-3">
        @forelse($accruals as $accrual)
            <div class="bg-white rounded-2xl p-4 border shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">{{ $accrual->source_place_name ?: ($accrual->source_place_code ?: 'Торговое место') }}</p>
                        <p class="text-xs text-slate-500">Период: {{ $accrual->period?->format('m.Y') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-base font-semibold">{{ number_format((float) $accrual->total_with_vat, 0, '.', ' ') }} ₽</p>
                        <p class="text-xs text-slate-400">{{ $accrual->status }}</p>
                    </div>
                </div>
                <div class="mt-3 text-xs text-slate-500 space-y-1">
                    @if($accrual->rent_amount)
                        <p>Аренда: {{ number_format((float) $accrual->rent_amount, 0, '.', ' ') }} ₽</p>
                    @endif
                    @if($accrual->utilities_amount)
                        <p>Коммунальные: {{ number_format((float) $accrual->utilities_amount, 0, '.', ' ') }} ₽</p>
                    @endif
                    @if($accrual->management_fee)
                        <p>Управление: {{ number_format((float) $accrual->management_fee, 0, '.', ' ') }} ₽</p>
                    @endif
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-4 border text-sm text-slate-500">Начислений пока нет.</div>
        @endforelse
    </div>
</x-cabinet-layout>
