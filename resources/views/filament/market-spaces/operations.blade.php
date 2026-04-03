{{-- resources/views/filament/market-spaces/operations.blade.php --}}

@props([
    'items' => [],
    'spaceId' => null,
    'reviewUrl' => null,
])

@php
    $rows = is_array($items) ? $items : [];
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
                        <td>{{ $row['summary'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
