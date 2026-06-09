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
        .space-ops__list {
            display: grid;
            gap: 10px;
        }

        .space-ops__empty {
            font-size: 13px;
            opacity: 0.75;
            padding: 8px 0;
        }

        .space-ops__item {
            display: grid;
            grid-template-columns: minmax(116px, 0.18fr) minmax(0, 1fr) auto;
            gap: 12px;
            align-items: start;
            padding: 12px 14px;
            border: 1px solid #dbe4f0;
            border-radius: 12px;
            background: #fff;
        }

        .dark .space-ops__item {
            border-color: rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.45);
        }

        .space-ops__date {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.35;
        }

        .dark .space-ops__date {
            color: #cbd5e1;
        }

        .space-ops__title {
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.3;
        }

        .dark .space-ops__title {
            color: #f8fafc;
        }

        .space-ops__details {
            color: #475569;
            font-size: 13px;
            line-height: 1.45;
            margin-top: 4px;
        }

        .dark .space-ops__details {
            color: #cbd5e1;
        }

        .space-ops__meta {
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
            margin-top: 6px;
        }

        .dark .space-ops__meta {
            color: #94a3b8;
        }

        .space-ops__badge {
            align-self: start;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            color: #334155;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 6px 9px;
            white-space: nowrap;
        }

        .space-ops__badge--success {
            border-color: #86efac;
            color: #166534;
            background: #f0fdf4;
        }

        .space-ops__badge--warning {
            border-color: #fcd34d;
            color: #92400e;
            background: #fffbeb;
        }

        .space-ops__badge--danger {
            border-color: #fca5a5;
            color: #991b1b;
            background: #fef2f2;
        }

        .space-ops__badge--gray {
            border-color: #cbd5e1;
            color: #475569;
            background: #f8fafc;
        }

        @media (max-width: 760px) {
            .space-ops__item {
                grid-template-columns: minmax(0, 1fr);
            }

            .space-ops__badge {
                justify-self: start;
            }
        }
    </style>
@endonce

<div class="space-ops">
    @if (empty($rows))
        <div class="space-ops__empty">По этому месту пока нет записей внутреннего журнала.</div>
    @else
        <div class="space-ops__list">
            @foreach ($rows as $row)
                <article class="space-ops__item">
                    <div class="space-ops__date">{{ $row['effective_at'] ?? '—' }}</div>
                    <div>
                        <div class="space-ops__title">{{ $row['title'] ?? 'Изменение по месту' }}</div>
                        @if (!empty($row['details']))
                            <div class="space-ops__details">{{ $row['details'] }}</div>
                        @endif
                        <div class="space-ops__meta">
                            Автор: {{ $row['author_name'] ?? '—' }}
                            @if (!empty($row['comment']))
                                · Комментарий: {{ $row['comment'] }}
                            @endif
                        </div>
                    </div>
                    <div class="space-ops__badge space-ops__badge--{{ $row['status_color'] ?? 'gray' }}">
                        {{ $row['status_label'] ?? 'Записано' }}
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
