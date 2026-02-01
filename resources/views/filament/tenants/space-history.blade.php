{{-- resources/views/filament/tenants/space-history.blade.php --}}

@props([
    'items' => [],
])

@php
    $rows = is_array($items) ? $items : [];
@endphp

@once
    <style>
        .tenant-space-history {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .tenant-space-history th,
        .tenant-space-history td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .dark .tenant-space-history th,
        .dark .tenant-space-history td {
            border-bottom-color: rgba(255, 255, 255, 0.12);
        }

        .tenant-space-history th {
            font-weight: 600;
            font-size: 12px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .tenant-space-history__empty {
            font-size: 13px;
            opacity: 0.75;
            padding: 8px 0;
        }
    </style>
@endonce

@if (empty($rows))
    <div class="tenant-space-history__empty">История аренды мест пока пуста.</div>
@else
    <table class="tenant-space-history">
        <thead>
            <tr>
                <th>Дата</th>
                <th>Место</th>
                <th>Событие</th>
                <th>Кто изменил</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['changed_at'] ?? '—' }}</td>
                    <td>{{ $row['space_label'] ?? '—' }}</td>
                    <td>{{ $row['event'] ?? '—' }}</td>
                    <td>{{ $row['user_name'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
