{{-- resources/views/filament/market-spaces/group-composition.blade.php --}}

@once
    <style>
        .group-composition {
            font-family: inherit;
            font-size: 14px;
        }

        .group-composition__wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .group-composition__table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }

        .group-composition__thead {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        .group-composition__th {
            padding: 10px 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .group-composition__tbody {
            background: #ffffff;
        }

        .group-composition__tr {
            border-bottom: 1px solid #f3f4f6;
        }

        .group-composition__tr:last-child {
            border-bottom: none;
        }

        .group-composition__tr:hover {
            background: #f9fafb;
        }

        .group-composition__td {
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.35;
            color: #374151;
            vertical-align: middle;
        }

        .group-composition__slot {
            width: 60px;
            white-space: nowrap;
        }

        .group-composition__number {
            width: 110px;
            font-weight: 600;
            white-space: nowrap;
        }

        .group-composition__name {
            min-width: 180px;
        }

        .group-composition__tenant {
            min-width: 140px;
        }

        .group-composition__status {
            width: 120px;
            white-space: nowrap;
        }

        .group-composition__action {
            width: 90px;
            text-align: right;
            white-space: nowrap;
        }

        .group-composition__link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 6px;
            background: #db2777;
            color: #ffffff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
            text-decoration: none;
        }

        .group-composition__link:hover {
            background: #be185d;
            color: #ffffff;
            text-decoration: none;
        }

        .group-composition__empty {
            padding: 24px 16px;
            border: 1px dashed #d1d5db;
            border-radius: 10px;
            color: #6b7280;
            font-size: 13px;
            text-align: center;
            background: #f9fafb;
        }
    </style>
@endonce

<div class="group-composition">
    @if ($hasChildren)
        <div class="group-composition__wrapper">
            <table class="group-composition__table">
                <thead class="group-composition__thead">
                    <tr>
                        <th class="group-composition__th group-composition__slot">Слот</th>
                        <th class="group-composition__th group-composition__number">Номер</th>
                        <th class="group-composition__th group-composition__name">Название</th>
                        <th class="group-composition__th group-composition__tenant">Арендатор</th>
                        <th class="group-composition__th group-composition__status">Занятость</th>
                        <th class="group-composition__th group-composition__action">Действие</th>
                    </tr>
                </thead>
                <tbody class="group-composition__tbody">
                    @foreach ($children as $child)
                        <tr class="group-composition__tr">
                            <td class="group-composition__td group-composition__slot">{{ $child['slot'] }}</td>
                            <td class="group-composition__td group-composition__number">{{ $child['number'] }}</td>
                            <td class="group-composition__td group-composition__name">{{ $child['display_name'] }}</td>
                            <td class="group-composition__td group-composition__tenant">{{ $child['tenant_name'] }}</td>
                            <td class="group-composition__td group-composition__status">
                                <x-filament::badge :color="$child['status_color']">
                                    {{ $child['status_label'] }}
                                </x-filament::badge>
                            </td>
                            <td class="group-composition__td group-composition__action">
                                <a
                                    href="{{ $child['edit_url'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="group-composition__link"
                                >
                                    Открыть
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="group-composition__empty">В группе пока нет дочерних мест.</div>
    @endif
</div>
