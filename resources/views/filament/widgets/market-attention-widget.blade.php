<x-filament::section>
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-base font-semibold text-gray-950 dark:text-white">
                Требует внимания
            </div>

            @isset($marketName)
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Рынок: {{ $marketName }}
                </div>
            @endisset
        </div>
    </div>

    @if ($items === [])
        <div class="mt-4 rounded-xl border border-success-200 bg-success-50 px-4 py-4 text-sm text-success-800 dark:border-success-900/40 dark:bg-success-950/30 dark:text-success-200">
            <div class="font-medium">{{ $emptyHeading ?? 'Критичных сигналов нет' }}</div>
            @if (filled($emptyDescription ?? null))
                <div class="mt-1">{{ $emptyDescription }}</div>
            @endif
        </div>
    @else
        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($items as $item)
                @php
                    $tone = $item['tone'] ?? 'gray';

                    $toneClasses = match ($tone) {
                        'danger' => 'border-danger-200 bg-danger-50 dark:border-danger-900/40 dark:bg-danger-950/20',
                        'warning' => 'border-warning-200 bg-warning-50 dark:border-warning-900/40 dark:bg-warning-950/20',
                        'success' => 'border-success-200 bg-success-50 dark:border-success-900/40 dark:bg-success-950/20',
                        default => 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/40',
                    };

                    $badgeClasses = match ($tone) {
                        'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-200',
                        'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-200',
                        'success' => 'bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-200',
                        default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
                    };
                @endphp

                <div class="rounded-xl border p-4 {{ $toneClasses }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                {{ $item['title'] }}
                            </div>
                            <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ $item['value'] }}
                            </div>
                        </div>

                        <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeClasses }}">
                            Требует решения
                        </span>
                    </div>

                    <div class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ $item['description'] }}
                    </div>

                    <div class="mt-4">
                        <a
                            href="{{ $item['action_url'] }}"
                            class="inline-flex items-center gap-2 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                        >
                            {{ $item['action_label'] }}
                            <x-filament::icon
                                icon="heroicon-m-arrow-top-right-on-square"
                                class="h-4 w-4"
                            />
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament::section>
