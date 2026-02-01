{{-- resources/views/filament/market-spaces/operations.blade.php --}}

@props([
    'items' => [],
    'spaceId' => null,
])

@php
    $rows = is_array($items) ? $items : [];
    $period = request()->query('period');
    $query = is_string($period) ? ['period' => $period] : [];
@endphp

@once
    <style>
        .space-ops__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .space-ops__btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.12);
            background: rgba(0, 0, 0, 0.03);
            font-size: 13px;
            text-decoration: none;
        }

        .dark .space-ops__btn {
            border-color: rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
        }

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
    <div class="space-ops__actions">
        <a class="space-ops__btn" href="{{ route('filament.admin.resources.operations.create', array_merge(['type' => 'tenant_switch', 'entity_type' => 'market_space', 'entity_id' => $spaceId], $query)) }}">
            Сменить арендатора
        </a>
        <a class="space-ops__btn" href="{{ route('filament.admin.resources.operations.create', array_merge(['type' => 'rent_rate_change', 'entity_type' => 'market_space', 'entity_id' => $spaceId], $query)) }}">
            Изменить ставку
        </a>
        <a class="space-ops__btn" href="{{ route('filament.admin.resources.operations.create', array_merge(['type' => 'electricity_input', 'entity_type' => 'market_space', 'entity_id' => $spaceId], $query)) }}">
            Ввести электроэнергию
        </a>
    </div>

    @if (empty($rows))
        <div class="space-ops__empty">Операции по месту пока не зафиксированы.</div>
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
