<x-filament::section>
    <x-slot name="heading">
        Документы 1С
    </x-slot>

    <x-slot name="description">
        {{ $periodLabel }} · последние {{ $rowLimit }} документов начислений и оплат
    </x-slot>

    @php
        $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $formatDate = static function (?string $value): string {
            if (blank($value)) {
                return '—';
            }

            try {
                return \Carbon\CarbonImmutable::parse($value)->format('d.m.Y');
            } catch (\Throwable) {
                return (string) $value;
            }
        };
        $typeClasses = [
            'accrual' => 'bg-primary-500/10 text-primary-700 ring-primary-500/20 dark:bg-primary-400/10 dark:text-primary-200 dark:ring-primary-400/20',
            'payment' => 'bg-success-500/10 text-success-700 ring-success-500/20 dark:bg-success-400/10 dark:text-success-200 dark:ring-success-400/20',
        ];
    @endphp

    <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Начисления</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $formatMoney((float) $summary['accrued']) }}</div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Оплаты</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $formatMoney((float) $summary['paid']) }}</div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Документы</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ number_format((int) $summary['rows_count'], 0, ',', ' ') }}
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Состав</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ number_format((int) $summary['accrual_count'], 0, ',', ' ') }} / {{ number_format((int) $summary['payment_count'], 0, ',', ' ') }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">начисления / оплаты</div>
            </div>
        </div>

        @if (filled($fullUrl))
            <div>
                <a
                    href="{{ $fullUrl }}"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950"
                >
                    Открыть журнал документов
                </a>
            </div>
        @endif

        @if (filled($emptyReason))
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                {{ $emptyReason }}
            </div>
        @elseif (count($rows) === 0)
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                За выбранный период нет документов из 1С.
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Дата</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Тип</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Документ</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Арендатор</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Договор</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Сумма</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-950">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 align-top tabular-nums text-gray-700 dark:text-gray-200">
                                    {{ $formatDate($row['document_date']) }}
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset {{ $typeClasses[$row['type']] ?? $typeClasses['accrual'] }}">
                                        {{ $row['type_label'] }}
                                    </span>
                                </td>
                                <td class="max-w-xs px-4 py-3 align-top text-gray-700 dark:text-gray-200">
                                    {{ $row['document_number'] }}
                                </td>
                                <td class="max-w-sm px-4 py-3 align-top">
                                    @if ($row['tenant_url'])
                                        <a href="{{ $row['tenant_url'] }}" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                                            {{ $row['tenant_name'] }}
                                        </a>
                                    @else
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['tenant_name'] }}</span>
                                    @endif
                                </td>
                                <td class="max-w-sm px-4 py-3 align-top">
                                    @if ($row['contract_url'])
                                        <a href="{{ $row['contract_url'] }}" class="text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                                            {{ $row['contract_label'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-200">{{ $row['contract_label'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right align-top font-medium tabular-nums text-gray-950 dark:text-white">
                                    {{ $formatMoney((float) $row['amount']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($hasMoreRows)
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Показаны первые {{ $rowLimit }} документов, скрыто ещё {{ number_format($hiddenRowsCount, 0, ',', ' ') }}.
                </div>
            @endif
        @endif
    </div>
</x-filament::section>
