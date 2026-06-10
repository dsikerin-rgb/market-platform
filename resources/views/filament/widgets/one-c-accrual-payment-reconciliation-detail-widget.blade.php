<x-filament::section>
    <x-slot name="heading">
        Детальная сверка начислений и оплат 1С
    </x-slot>

    <x-slot name="description">
        {{ $monthLabel }} · по арендаторам и договорам
    </x-slot>

    @php
        $formatMoney = static fn (float $value): string => number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ') . ' ₽';
        $statusClasses = [
            'debt' => 'bg-danger-500/10 text-danger-700 ring-danger-500/20 dark:bg-danger-400/10 dark:text-danger-200 dark:ring-danger-400/20',
            'overpaid' => 'bg-warning-500/10 text-warning-700 ring-warning-500/20 dark:bg-warning-400/10 dark:text-warning-200 dark:ring-warning-400/20',
            'closed' => 'bg-success-500/10 text-success-700 ring-success-500/20 dark:bg-success-400/10 dark:text-success-200 dark:ring-success-400/20',
        ];
    @endphp

    <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Начислено</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $formatMoney((float) $summary['accrued']) }}</div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Оплачено</div>
                <div class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $formatMoney((float) $summary['paid']) }}</div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Разница</div>
                <div class="mt-1 text-lg font-semibold tabular-nums {{ ((float) $summary['delta']) > 0.009 ? 'text-danger-600 dark:text-danger-300' : (((float) $summary['delta']) < -0.009 ? 'text-warning-600 dark:text-warning-300' : 'text-success-600 dark:text-success-300') }}">
                    {{ $formatMoney((float) $summary['delta']) }}
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Строки</div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                    {{ number_format((int) $summary['rows_count'], 0, ',', ' ') }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    долг: {{ $summary['debt_count'] }} · переплата: {{ $summary['overpaid_count'] }} · закрыто: {{ $summary['closed_count'] }}
                </div>
            </div>
        </div>

        @if (filled($emptyReason))
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                {{ $emptyReason }}
            </div>
        @elseif (count($rows) === 0)
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                За выбранный месяц нет начислений или оплат из 1С.
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Арендатор</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Договор</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Начислено</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Оплачено</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Разница</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-950">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="max-w-xs px-4 py-3 align-top">
                                    @if ($row['tenant_url'])
                                        <a href="{{ $row['tenant_url'] }}" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                                            {{ $row['tenant_name'] }}
                                        </a>
                                    @else
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['tenant_name'] }}</span>
                                    @endif
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        начислений: {{ $row['accrual_rows'] }} · оплат: {{ $row['payment_rows'] }}
                                    </div>
                                </td>

                                <td class="max-w-xs px-4 py-3 align-top">
                                    @if ($row['contract_url'])
                                        <a href="{{ $row['contract_url'] }}" class="text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                                            {{ $row['contract_label'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-200">{{ $row['contract_label'] }}</span>
                                    @endif
                                    @if (filled($row['contract_external_id']))
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">1С: {{ $row['contract_external_id'] }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right align-top font-medium tabular-nums text-gray-950 dark:text-white">
                                    {{ $formatMoney((float) $row['accrued']) }}
                                </td>
                                <td class="px-4 py-3 text-right align-top font-medium tabular-nums text-gray-950 dark:text-white">
                                    {{ $formatMoney((float) $row['paid']) }}
                                </td>
                                <td class="px-4 py-3 text-right align-top font-semibold tabular-nums {{ ((float) $row['delta']) > 0.009 ? 'text-danger-600 dark:text-danger-300' : (((float) $row['delta']) < -0.009 ? 'text-warning-600 dark:text-warning-300' : 'text-success-600 dark:text-success-300') }}">
                                    {{ $formatMoney((float) $row['delta']) }}
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusClasses[$row['status']] ?? $statusClasses['closed'] }}">
                                        {{ $row['status_label'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($hasMoreRows)
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Показаны первые {{ $rowLimit }} строк по величине расхождения, скрыто ещё {{ number_format($hiddenRowsCount, 0, ',', ' ') }}.
                </div>
            @endif
        @endif
    </div>
</x-filament::section>
