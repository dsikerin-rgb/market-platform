<x-filament-panels::page>
    <div style="display: grid; gap: 12px;">
        <x-filament::section>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="font-size: 20px; font-weight: 700; text-transform: capitalize;">
                    {{ $monthLabel }}
                </div>

                <div style="display: flex; gap: 8px; align-items: center;">
                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        onclick="window.location='{{ $prevMonthUrl }}'"
                    >
                        ← Предыдущий
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        onclick="window.location='{{ $nextMonthUrl }}'"
                    >
                        Следующий →
                    </x-filament::button>
                </div>
            </div>

            <div style="margin-top: 12px; overflow-x: auto;">
                <div style="min-width: 980px; border: 1px solid rgba(148, 163, 184, .22); border-radius: 12px; overflow: hidden;">
                    <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); background: rgba(148, 163, 184, .08);">
                        @foreach ($weekdays as $weekday)
                            <div style="padding: 10px 12px; font-size: 12px; font-weight: 700;">
                                {{ $weekday }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($weeks as $week)
                        <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-top: 1px solid rgba(148, 163, 184, .16);">
                            @foreach ($week as $day)
                                <div
                                    style="
                                        min-height: 136px;
                                        padding: 10px;
                                        border-right: 1px solid rgba(148, 163, 184, .14);
                                        background: {{ $day['is_current_month'] ? 'transparent' : 'rgba(148, 163, 184, .05)' }};
                                    "
                                >
                                    <div
                                        style="
                                            display: inline-flex;
                                            align-items: center;
                                            justify-content: center;
                                            width: 26px;
                                            height: 26px;
                                            border-radius: 999px;
                                            font-size: 12px;
                                            font-weight: 700;
                                            background: {{ $day['is_today'] ? 'rgba(239, 68, 68, .22)' : 'transparent' }};
                                        "
                                    >
                                        {{ $day['day'] }}
                                    </div>

                                    <div style="margin-top: 8px; display: grid; gap: 6px;">
                                        @foreach ($day['events'] as $event)
                                            @if ($event['url'])
                                                <a
                                                    href="{{ $event['url'] }}"
                                                    style="
                                                        display: block;
                                                        border-radius: 8px;
                                                        border: 1px solid rgba(148, 163, 184, .20);
                                                        padding: 6px 8px;
                                                        text-decoration: none;
                                                        line-height: 1.3;
                                                        font-size: 12px;
                                                        color: {{ $event['is_holiday'] ? '#ef4444' : 'inherit' }};
                                                    "
                                                    title="{{ $event['title'] }}"
                                                >
                                                    {{ $event['title'] }}
                                                </a>
                                            @else
                                                <div
                                                    style="
                                                        display: block;
                                                        border-radius: 8px;
                                                        border: 1px solid rgba(148, 163, 184, .20);
                                                        padding: 6px 8px;
                                                        line-height: 1.3;
                                                        font-size: 12px;
                                                        color: {{ $event['is_holiday'] ? '#ef4444' : 'inherit' }};
                                                    "
                                                    title="{{ $event['title'] }}"
                                                >
                                                    {{ $event['title'] }}
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
