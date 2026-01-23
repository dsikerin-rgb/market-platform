{{-- resources/views/filament/tenants/tenant-summary.blade.php --}}

@props([
    'summary' => [],
])

@php
    $lastPeriodLabel = (string) ($summary['last_period_label'] ?? '—');

    $spacesLast   = (int) ($summary['spaces_last'] ?? 0);
    $sumLast      = (float) ($summary['sum_last'] ?? 0);
    $countAll     = (int) ($summary['count'] ?? 0);
    $sumAll       = (float) ($summary['sum_all'] ?? 0);
    $withoutSpace = (int) ($summary['without_space'] ?? 0);

    $isActive = $summary['is_active'] ?? null;
    $isActiveLabel = $isActive === null ? '—' : ($isActive ? 'Активен' : 'Неактивен');

    $formatRub = static function (float $value): string {
        $v = round($value, 2);
        $s = number_format($v, 2, ',', ' ');
        $s = preg_replace('/,00$/', '', $s) ?? $s;
        return $s . ' ₽';
    };

    $formatInt = static function (int $value): string {
        return number_format($value, 0, ',', ' ');
    };

    $dataQualityTitle = $withoutSpace > 0 ? 'Есть строки без привязки' : 'Все строки привязаны';
    $dataQualityHint = $withoutSpace > 0
        ? ('Без market_space_id: ' . $formatInt($withoutSpace) . ' — финансы учтены, но место не определено.')
        : 'Можно строить “Площади” по начислениям без оговорок.';
@endphp

@once
    <style>
        .tenant-summary { display:flex; flex-direction:column; gap:14px; }

        .tenant-summary__badges { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }

        .tenant-summary__badge {
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid rgba(0,0,0,.10);
            background:rgba(0,0,0,.04);
            font-size:12px;
            font-weight:600;
            line-height:1;
            color:inherit;
            white-space:nowrap;
        }
        .dark .tenant-summary__badge {
            border-color: rgba(255,255,255,.12);
            background: rgba(255,255,255,.04);
        }

        .tenant-summary__badge--success {
            border-color: rgba(16,185,129,.30);
            background: rgba(16,185,129,.10);
        }
        .dark .tenant-summary__badge--success {
            border-color: rgba(16,185,129,.35);
            background: rgba(16,185,129,.12);
        }

        .tenant-summary__badge--warning {
            border-color: rgba(245,158,11,.35);
            background: rgba(245,158,11,.12);
        }
        .dark .tenant-summary__badge--warning {
            border-color: rgba(245,158,11,.40);
            background: rgba(245,158,11,.14);
        }

        /* 12-col grid for predictable composition */
        .tenant-summary__grid {
            display:grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap:10px;
        }

        .tenant-summary__card {
            border-radius: 14px;
            border: 1px solid rgba(0,0,0,.10);
            background: rgba(0,0,0,.02);
            padding: 12px 14px;
            min-width: 0;
        }
        .dark .tenant-summary__card {
            border-color: rgba(255,255,255,.12);
            background: rgba(255,255,255,.03);
        }

        .tenant-summary__label {
            font-size: 12px;
            opacity: .75;
            margin-bottom: 6px;
        }

        .tenant-summary__value {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: -0.01em;
            line-height: 1.2;
            word-break: break-word;
        }

        .tenant-summary__value--xl {
            font-size: 28px;
            letter-spacing: -0.02em;
        }

        .tenant-summary__hint {
            font-size: 12px;
            opacity: .70;
            margin-top: 6px;
        }

        .tenant-summary__empty {
            border-radius: 14px;
            border: 1px dashed rgba(0,0,0,.18);
            padding: 12px 14px;
            opacity: .85;
        }
        .dark .tenant-summary__empty {
            border-color: rgba(255,255,255,.18);
        }

        /* Column spans (mobile-first) */
        .tenant-summary__span-12 { grid-column: span 12; }
        .tenant-summary__span-6 { grid-column: span 12; }
        .tenant-summary__span-3 { grid-column: span 12; }

        @media (min-width: 640px) {
            .tenant-summary__span-6 { grid-column: span 6; }
            .tenant-summary__span-3 { grid-column: span 6; }
        }

        @media (min-width: 1024px) {
            .tenant-summary__span-6 { grid-column: span 6; }
            .tenant-summary__span-3 { grid-column: span 3; }
        }

        /* Small visual divider inside primary card */
        .tenant-summary__meta {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top: 8px;
            font-size: 12px;
            opacity: .75;
        }
        .tenant-summary__meta strong { opacity: 1; }

        .tenant-summary__inline-kpis {
            display:flex;
            gap:14px;
            flex-wrap:wrap;
            margin-top: 10px;
        }
        .tenant-summary__inline-kpi {
            display:flex;
            flex-direction:column;
            gap:2px;
            min-width: 140px;
        }
        .tenant-summary__inline-kpi span:first-child {
            font-size: 12px;
            opacity: .70;
        }
        .tenant-summary__inline-kpi span:last-child {
            font-weight: 700;
        }
    </style>
@endonce

<div class="tenant-summary">
    <div class="tenant-summary__badges">
        <span class="tenant-summary__badge">
            Период: <strong>{{ $lastPeriodLabel }}</strong>
        </span>

        @if ($withoutSpace > 0)
            <span class="tenant-summary__badge tenant-summary__badge--warning">
                Есть строки без привязки: <strong>{{ $formatInt($withoutSpace) }}</strong>
            </span>
        @else
            <span class="tenant-summary__badge tenant-summary__badge--success">
                Все строки привязаны
            </span>
        @endif

        @if ($isActive === true)
            <span class="tenant-summary__badge tenant-summary__badge--success">
                Статус: <strong>{{ $isActiveLabel }}</strong>
            </span>
        @elseif ($isActive === false)
            <span class="tenant-summary__badge">
                Статус: <strong>{{ $isActiveLabel }}</strong>
            </span>
        @else
            <span class="tenant-summary__badge">
                Статус: <strong>{{ $isActiveLabel }}</strong>
            </span>
        @endif
    </div>

    @if ($countAll <= 0)
        <div class="tenant-summary__empty">
            Начислений пока нет — сводка появится после импорта tenant_accruals.
        </div>
    @else
        <div class="tenant-summary__grid">
            {{-- Primary: payment for last period --}}
            <div class="tenant-summary__card tenant-summary__span-6">
                <div class="tenant-summary__label">Итого к оплате за период (с НДС)</div>
                <div class="tenant-summary__value tenant-summary__value--xl">{{ $formatRub($sumLast) }}</div>

                {{-- No duplicate "Период" here: it's already in the badge --}}
                <div class="tenant-summary__inline-kpis">
                    <div class="tenant-summary__inline-kpi">
                        <span>Мест за период</span>
                        <span>{{ $formatInt($spacesLast) }}</span>
                    </div>
                    <div class="tenant-summary__inline-kpi">
                        <span>Строк за всё время</span>
                        <span>{{ $formatInt($countAll) }}</span>
                    </div>
                </div>

                <div class="tenant-summary__hint">Источник: tenant_accruals (финансовая “истина”).</div>
            </div>

            {{-- Total all-time --}}
            <div class="tenant-summary__card tenant-summary__span-3">
                <div class="tenant-summary__label">Сумма начислений за всё время</div>
                <div class="tenant-summary__value">{{ $formatRub($sumAll) }}</div>
                <div class="tenant-summary__hint">Накопительно</div>
            </div>

            {{-- Data quality --}}
            <div class="tenant-summary__card tenant-summary__span-3">
                <div class="tenant-summary__label">Качество данных</div>
                <div class="tenant-summary__value">{{ $dataQualityTitle }}</div>
                <div class="tenant-summary__hint">{{ $dataQualityHint }}</div>
            </div>
        </div>
    @endif
</div>
