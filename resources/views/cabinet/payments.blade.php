<x-cabinet-layout :tenant="$tenant" title="Финансы">
    @php
        $formatMoney = static function (float $value, bool $withSign = false): string {
            $prefix = '';
            if ($withSign && abs($value) > 0.009) {
                $prefix = $value > 0 ? '+' : '-';
            }

            return $prefix . number_format(abs($value), 2, ',', ' ') . ' ₽';
        };

        $periodLabel = $selectedPeriod->translatedFormat('F Y');
        $periodRangeLabel = $periodFrom->format('d.m.Y') . ' — ' . $periodTo->format('d.m.Y');
        $latestImportLabel = $latestImportAt
            ? \Illuminate\Support\Carbon::parse($latestImportAt)->format('d.m.Y H:i')
            : 'Нет данных';
        $statusClass = match ($summary['status']) {
            'debt' => 'from-rose-500 to-red-600',
            'credit' => 'from-emerald-500 to-teal-600',
            'empty' => 'from-slate-500 to-slate-700',
            default => 'from-sky-500 to-blue-600',
        };
        $statusPillClass = match ($summary['status']) {
            'debt' => 'bg-rose-50 text-rose-700 border-rose-200',
            'credit' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'empty' => 'bg-slate-50 text-slate-700 border-slate-200',
            default => 'bg-sky-50 text-sky-700 border-sky-200',
        };
        $balanceLabel = $summary['balance'] > 0.009
            ? $formatMoney((float) $summary['balance'])
            : ($summary['balance'] < -0.009 ? $formatMoney((float) $summary['balance']) : '0,00 ₽');
        $spaceLabel = static function ($space): string {
            if (! $space) {
                return 'Без места';
            }

            $label = trim((string) ($space->display_name ?: ($space->number ?: $space->code)));

            return $label !== '' ? $label : 'Торговое место';
        };
        $contractLabel = static function ($contract, ?string $fallback = null): string {
            $number = trim((string) ($contract?->number ?? $fallback ?? ''));

            return $number !== '' ? $number : 'Без договора';
        };
        $accrualBasis = static function ($accrual): string {
            foreach ([
                $accrual->line_description ?? null,
                $accrual->service_name ?? null,
                $accrual->purpose ?? null,
                $accrual->activity_type ?? null,
            ] as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }

            return 'Начисление за период';
        };
    @endphp

    <section class="rounded-3xl bg-gradient-to-br {{ $statusClass }} text-white p-4 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.14em] text-white/70">Финансы 1С</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight">{{ $summary['statusLabel'] }}</h2>
                <p class="mt-1 text-sm text-white/80">{{ $summary['statusCaption'] }}</p>
            </div>

            <div class="rounded-3xl bg-white/15 px-4 py-3 backdrop-blur md:min-w-56">
                <p class="text-xs text-white/70">Итог по ОСВ</p>
                <p class="mt-1 text-2xl font-semibold">{{ $balanceLabel }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-4">
        <div class="grid gap-3 md:grid-cols-[minmax(16rem,22rem)_1fr] md:items-end">
            <form method="GET" class="space-y-1.5">
                <label for="cabinet-finance-month" class="block text-xs font-semibold text-slate-500">Период</label>
                <select
                    id="cabinet-finance-month"
                    name="month"
                    class="w-full rounded-2xl border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900"
                    onchange="this.form.submit()"
                >
                    @forelse($availablePeriods as $period)
                        <option value="{{ $period->format('Y-m') }}" @selected($period->format('Y-m') === $selectedPeriod->format('Y-m'))>
                            {{ $period->translatedFormat('F Y') }}
                        </option>
                    @empty
                        <option value="{{ $selectedPeriod->format('Y-m') }}">{{ $periodLabel }}</option>
                    @endforelse
                </select>
            </form>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <p class="text-xs text-slate-500">Период ОСВ</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $periodRangeLabel }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <p class="text-xs text-slate-500">Начислено</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $formatMoney((float) $summary['accrued']) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <p class="text-xs text-slate-500">Оплачено</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $formatMoney((float) $summary['paid']) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                    <p class="text-xs text-slate-500">Обновлено</p>
                    <p class="mt-1 text-sm font-semibold text-slate-900">{{ $latestImportLabel }}</p>
                </div>
            </div>
        </div>

        @if($firstPeriodLabel)
            <p class="text-xs text-slate-500">Доступны периоды не ранее {{ $firstPeriodLabel }}.</p>
        @endif

        <div class="rounded-2xl border {{ $statusPillClass }} px-3 py-2 text-sm">
            ОСВ показывает итоговый остаток. Начисления и оплаты ниже являются расшифровкой движения за выбранный период.
        </div>
    </section>

    <section class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-slate-900">По договорам</h3>
                <p class="text-xs text-slate-500">Сводка ОСВ по договорам и документам расчётов.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ (int) $summary['settlementRowsCount'] }}
            </span>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($settlementRows as $row)
                @php
                    $rowNet = (float) $row->closing_debit - (float) $row->closing_credit;
                    $rowStatus = $rowNet > 0.009 ? 'Долг' : ($rowNet < -0.009 ? 'Переплата' : 'Закрыто');
                    $rowStatusClass = $rowNet > 0.009
                        ? 'bg-rose-50 text-rose-700'
                        : ($rowNet < -0.009 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600');
                    $contract = $row->tenantContract;
                @endphp

                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $contractLabel($contract, $row->contract_name) }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $spaceLabel($contract?->marketSpace) }}
                                @if($row->organization_name)
                                    · {{ $row->organization_name }}
                                @endif
                            </p>
                            @if($row->settlement_document_name)
                                <p class="mt-1 text-xs text-slate-600 line-clamp-2">{{ $row->settlement_document_name }}</p>
                            @endif
                        </div>

                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $rowStatusClass }}">{{ $rowStatus }}</span>
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <p class="text-xs text-slate-500">Начислено</p>
                            <p class="font-semibold text-slate-900">{{ $formatMoney((float) $row->turnover_debit) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Оплачено</p>
                            <p class="font-semibold text-slate-900">{{ $formatMoney((float) $row->turnover_credit) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Итог</p>
                            <p class="font-semibold text-slate-900">{{ $formatMoney($rowNet) }}</p>
                        </div>
                    </div>
                </article>
            @empty
                <div class="px-4 py-6 text-sm text-slate-500">
                    По выбранному периоду ОСВ 1С для арендатора не найдена.
                </div>
            @endforelse
        </div>
    </section>

    <section class="grid gap-3 lg:grid-cols-2">
        <div class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Начисления</h3>
                        <p class="text-xs text-slate-500">Что было начислено за выбранный месяц.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        {{ $formatMoney((float) $summary['accrualRowsTotal']) }}
                    </span>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($accruals as $accrual)
                    @php
                        $amount = (float) ($accrual->total_with_vat ?? $accrual->total_no_vat ?? 0);
                    @endphp

                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">{{ $spaceLabel($accrual->marketSpace) }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $contractLabel($accrual->tenantContract) }} · {{ $accrual->period?->format('m.Y') }}
                                </p>
                                <p class="mt-1 text-xs text-slate-600 line-clamp-2">{{ $accrualBasis($accrual) }}</p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-slate-900">{{ $formatMoney($amount) }}</p>
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-6 text-sm text-slate-500">
                        Начислений за выбранный период нет.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="rounded-3xl bg-white border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Оплаты</h3>
                        <p class="text-xs text-slate-500">Платежи, учтённые в 1С за выбранный месяц.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        {{ $formatMoney((float) $summary['paymentRowsTotal']) }}
                    </span>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($payments as $payment)
                    @php
                        $contract = $payment->tenantContract;
                        $purpose = trim((string) ($payment->purpose ?? ''));
                    @endphp

                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900">
                                    {{ $payment->payment_date?->format('d.m.Y') ?? 'Дата не указана' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $contractLabel($contract) }}
                                    @if($payment->document_number)
                                        · платёж {{ $payment->document_number }}
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-slate-500">{{ $spaceLabel($contract?->marketSpace) }}</p>
                                @if($purpose !== '')
                                    <p class="mt-1 text-xs text-slate-600 line-clamp-2">{{ $purpose }}</p>
                                @endif
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-emerald-700">{{ $formatMoney((float) $payment->amount) }}</p>
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-6 text-sm text-slate-500">
                        Оплат за выбранный период нет.
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</x-cabinet-layout>
