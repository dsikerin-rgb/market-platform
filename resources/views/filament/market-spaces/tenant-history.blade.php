{{-- resources/views/filament/market-spaces/tenant-history.blade.php --}}

@props([
    'items' => [],
])

@php
    $rows = is_array($items) ? $items : [];
@endphp

@once
    <style>
        .market-history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .market-history-table th,
        .market-history-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .dark .market-history-table th,
        .dark .market-history-table td {
            border-bottom-color: rgba(255, 255, 255, 0.12);
        }

        .market-history-table th {
            font-weight: 600;
            font-size: 12px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .market-history-empty {
            font-size: 13px;
            opacity: 0.75;
            padding: 8px 0;
        }
    </style>
@endonce

@if (empty($rows))
    <div class="market-history-empty">История смены арендаторов пока пуста.</div>
@else
    <div class="market-history">
        <table class="market-history-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Старый арендатор</th>
                    <th>Новый арендатор</th>
                    <th>Кто изменил</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['changed_at'] ?? '—' }}</td>
                        <td>{{ $row['old_label'] ?? '—' }}</td>
                        <td>{{ $row['new_label'] ?? '—' }}</td>
                        <td>{{ $row['user_name'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
