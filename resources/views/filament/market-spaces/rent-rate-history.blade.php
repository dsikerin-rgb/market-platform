{{-- resources/views/filament/market-spaces/rent-rate-history.blade.php --}}

@props([
    'items' => [],
    'chart' => [],
    'unitLabel' => '—',
])

@php
    $rows = is_array($items) ? $items : [];
    $points = is_array($chart) ? $chart : [];
    $values = array_map(static fn ($p) => (float) ($p['value'] ?? 0), $points);
    $min = $values ? min($values) : 0;
    $max = $values ? max($values) : 0;
    $range = max(1, $max - $min);

    $width = 600;
    $height = 160;
    $pad = 18;

    $polyline = '';

    if (count($points) >= 2) {
        $stepX = ($width - $pad * 2) / max(1, count($points) - 1);
        $coords = [];
        foreach ($points as $idx => $p) {
            $x = $pad + $idx * $stepX;
            $value = (float) ($p['value'] ?? 0);
            $y = $pad + ($max - $value) * (($height - $pad * 2) / $range);
            $coords[] = round($x, 2) . ',' . round($y, 2);
        }
        $polyline = implode(' ', $coords);
    }

    $formatValue = static function (?float $value): string {
        if ($value === null) {
            return '—';
        }
        $v = round($value, 2);
        $s = number_format($v, 2, ',', ' ');
        $s = preg_replace('/,00$/', '', $s) ?? $s;
        return $s;
    };
@endphp

@once
    <style>
        .rent-history {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .rent-history__chart {
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.08);
            background: rgba(0, 0, 0, 0.02);
            padding: 12px;
        }

        .dark .rent-history__chart {
            border-color: rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.03);
        }

        .rent-history__chart-title {
            font-size: 12px;
            opacity: 0.7;
            margin-bottom: 6px;
        }

        .rent-history__table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .rent-history__table th,
        .rent-history__table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .dark .rent-history__table th,
        .dark .rent-history__table td {
            border-bottom-color: rgba(255, 255, 255, 0.12);
        }

        .rent-history__table th {
            font-weight: 600;
            font-size: 12px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .rent-history__empty {
            font-size: 13px;
            opacity: 0.75;
            padding: 8px 0;
        }
    </style>
@endonce

<div class="rent-history">
    <div class="rent-history__chart">
        <div class="rent-history__chart-title">
            График изменения ставки ({{ $unitLabel }})
        </div>

        @if (count($points) < 2)
            <div class="rent-history__empty">Недостаточно данных для построения графика.</div>
        @else
            <svg viewBox="0 0 {{ $width }} {{ $height }}" width="100%" height="160" aria-label="График ставки">
                <polyline
                    fill="none"
                    stroke="#2563eb"
                    stroke-width="2"
                    points="{{ $polyline }}"
                />
                @foreach ($points as $idx => $point)
                    @php
                        $x = $pad + $idx * (($width - $pad * 2) / max(1, count($points) - 1));
                        $value = (float) ($point['value'] ?? 0);
                        $y = $pad + ($max - $value) * (($height - $pad * 2) / $range);
                    @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#2563eb" />
                @endforeach
            </svg>
        @endif
    </div>

    @if (empty($rows))
        <div class="rent-history__empty">История ставки пока пуста.</div>
    @else
        <table class="rent-history__table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Было</th>
                    <th>Стало</th>
                    <th>Ед.</th>
                    <th>Комментарий</th>
                    <th>Кто изменил</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['changed_at'] ?? '—' }}</td>
                        <td>{{ $formatValue($row['old_value'] ?? null) }}</td>
                        <td>{{ $formatValue($row['new_value'] ?? null) }}</td>
                        <td>{{ $row['unit_label'] ?? '—' }}</td>
                        <td>{{ $row['note'] !== '' ? $row['note'] : '—' }}</td>
                        <td>{{ $row['user_name'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
