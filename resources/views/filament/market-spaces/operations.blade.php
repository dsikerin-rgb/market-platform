{{-- resources/views/filament/market-spaces/operations.blade.php --}}

@props([
    'items' => [],
    'spaceId' => null,
    'reviewUrl' => null,
])

@php
    $rows = is_array($items) ? $items : [];

    $stringValue = static function (mixed $value): ?string {
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        if (is_scalar($value)) {
            $normalized = trim((string) $value);

            return $normalized !== '' ? $normalized : null;
        }

        return null;
    };

    $formatMoney = static function (mixed $value): ?string {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, ',', ' ') . ' ₽';
    };

    $formatArea = static function (mixed $value): ?string {
        if (! is_numeric($value)) {
            return null;
        }

        $formatted = number_format((float) $value, 2, ',', ' ');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return $formatted . ' м²';
    };

    $unitLabel = static function (mixed $unit) use ($stringValue): ?string {
        return match ($stringValue($unit)) {
            'per_sqm_month' => 'за м² в месяц',
            'per_space_month' => 'за место в месяц',
            default => $stringValue($unit),
        };
    };

    $statusLabel = static function (mixed $status) use ($stringValue): ?string {
        return match ($stringValue($status)) {
            'vacant', 'free' => 'свободно',
            'occupied' => 'занято',
            'reserved' => 'зарезервировано',
            'maintenance' => 'на обслуживании',
            default => $stringValue($status),
        };
    };

    $humanizePayload = static function (mixed $summary) use ($stringValue, $formatMoney, $formatArea, $unitLabel, $statusLabel): string {
        $raw = is_string($summary) ? trim($summary) : '';

        if ($raw === '') {
            return '—';
        }

        if (! str_starts_with($raw, '{') && ! str_starts_with($raw, '[')) {
            return $raw;
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return $raw;
        }

        $parts = [];

        if (($payload['deleted_with_map_shapes'] ?? false) === true) {
            $parts[] = 'Место удалено вместе с фигурой карты';

            if (($payload['deleted_shapes_count'] ?? null) !== null) {
                $parts[] = 'фигур удалено: ' . (int) $payload['deleted_shapes_count'];
            }
        }

        if (($payload['is_active'] ?? null) === false) {
            $parts[] = 'Место упразднено';
        } elseif (($payload['is_active'] ?? null) === true) {
            $parts[] = 'Место возвращено в активные';
        }

        if (($value = $stringValue($payload['number'] ?? null)) !== null) {
            $parts[] = 'обозначение: ' . $value;
        }

        if (($value = $stringValue($payload['display_name'] ?? null)) !== null) {
            $parts[] = 'название: ' . $value;
        }

        if (($value = $formatArea($payload['area_sqm'] ?? null)) !== null) {
            $parts[] = 'площадь: ' . $value;
        }

        if (($value = $stringValue($payload['activity_type'] ?? null)) !== null) {
            $parts[] = 'вид деятельности: ' . $value;
        }

        if (($value = $statusLabel($payload['status'] ?? null)) !== null) {
            $parts[] = 'статус: ' . $value;
        }

        if (($value = $formatMoney($payload['rent_rate'] ?? null)) !== null) {
            $parts[] = 'ставка: ' . $value;
        }

        if (($value = $formatMoney($payload['from_rent_rate'] ?? null)) !== null) {
            $parts[] = 'было: ' . $value;
        }

        if (($value = $unitLabel($payload['unit'] ?? null)) !== null) {
            $parts[] = 'единица ставки: ' . $value;
        }

        if (($value = $formatMoney($payload['amount'] ?? null)) !== null) {
            $parts[] = 'сумма: ' . $value;
        }

        if (($value = $formatMoney($payload['amount_delta'] ?? null)) !== null) {
            $parts[] = 'корректировка: ' . $value;
        }

        if (($value = $stringValue($payload['period'] ?? null)) !== null) {
            $parts[] = 'период: ' . $value;
        }

        if (($payload['closed'] ?? null) === true) {
            $parts[] = 'период закрыт';
        }

        if (($value = $stringValue($payload['reason'] ?? null)) !== null) {
            $parts[] = 'комментарий: ' . $value;
        }

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        $fallback = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, ['market_space_id', 'from_tenant_id', 'to_tenant_id', 'location_id'], true)) {
                continue;
            }

            $display = $stringValue($value);
            if ($display !== null) {
                $fallback[] = str_replace('_', ' ', (string) $key) . ': ' . $display;
            }
        }

        return $fallback !== [] ? implode('; ', $fallback) : 'Изменение зафиксировано';
    };
@endphp

@once
    <style>
        .space-ops__table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .space-ops__table th,
        .space-ops__table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .dark .space-ops__table th,
        .dark .space-ops__table td {
            border-bottom-color: rgba(255, 255, 255, 0.12);
        }

        .space-ops__table th {
            font-weight: 600;
            font-size: 12px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .space-ops__empty {
            font-size: 13px;
            opacity: 0.75;
            padding: 8px 0;
        }

        .space-ops__review-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .space-ops__review-summary {
            margin-bottom: 4px;
        }

        .space-ops__review-meta {
            font-size: 12px;
            opacity: 0.75;
        }
    </style>
@endonce

<div class="space-ops">
    @if (empty($rows))
        <div class="space-ops__empty">По этому месту ещё нет записей внутреннего журнала.</div>
    @else
        <table class="space-ops__table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Тип</th>
                    <th>Статус</th>
                    <th>Данные</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['effective_at'] ?? '—' }}</td>
                        <td>{{ $row['type'] ?? '—' }}</td>
                        <td>{{ $row['status'] ?? '—' }}</td>
                        <td>
                            @if (!empty($row['is_review']))
                                <div class="space-ops__review">
                                    <div class="space-ops__review-title">
                                        {{ $row['review_decision_label'] ?? 'Ревизионное решение' }}
                                    </div>
                                    @if (!empty($row['summary']))
                                        <div class="space-ops__review-summary">{{ $humanizePayload($row['summary']) }}</div>
                                    @endif
                                    @if (!empty($row['review_observed_tenant_name']))
                                        <div class="space-ops__review-meta">Фактический арендатор: {{ $row['review_observed_tenant_name'] }}</div>
                                    @endif
                                    @if (!empty($row['review_reason']))
                                        <div class="space-ops__review-meta">Комментарий: {{ $row['review_reason'] }}</div>
                                    @endif
                                    <div class="space-ops__review-meta">Автор: {{ $row['author_name'] ?? '—' }}</div>
                                </div>
                            @else
                                {{ $humanizePayload($row['summary'] ?? '—') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
