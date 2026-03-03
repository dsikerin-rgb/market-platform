{{-- resources/views/filament/tenants/tenant-summary.blade.php --}}

@props([
    'summary' => [],
])

@php
    $lastPeriodLabel = (string) ($summary['last_period_label'] ?? '—');

    $spacesLast   = (int) ($summary['spaces_last'] ?? 0);
    $sumLast      = (float) ($summary['sum_last'] ?? 0);
    $countPeriod  = (int) ($summary['count_last_period'] ?? 0);
    $countAll     = (int) ($summary['count'] ?? 0);
    $withoutSpace = (int) ($summary['without_space'] ?? 0);

    $formatRub = static function (float $value): string {
        $v = round($value, 2);
        $s = number_format($v, 2, ',', ' ');
        $s = preg_replace('/,00$/', '', $s) ?? $s;
        return $s . ' ₽';
    };

    $formatInt = static function (int $value): string {
        return number_format($value, 0, ',', ' ');
    };

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
        .tenant-summary__label-with-help {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tenant-summary__help-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border-radius: 999px;
            border: 1px solid rgba(0,0,0,.20);
            font-size: 10px;
            font-weight: 700;
            line-height: 1;
            cursor: help;
            opacity: .85;
            user-select: none;
        }
        .dark .tenant-summary__help-icon {
            border-color: rgba(255,255,255,.25);
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
        @endif

    </div>

    @if ($countAll <= 0)
        <div class="tenant-summary__empty">
            Начислений пока нет — сводка появится после импорта tenant_accruals.
        </div>
    @else
        <div class="tenant-summary__grid">
            {{-- Primary: payment for last period --}}
            <div class="tenant-summary__card tenant-summary__span-12">
                <div class="tenant-summary__label tenant-summary__label-with-help">
                    <span>К оплате за {{ $lastPeriodLabel }}</span>
                    <span class="tenant-summary__help-icon" title="Сумма начислений арендатора за выбранный период (с НДС) по данным tenant_accruals." aria-label="Подсказка: сумма к оплате">?</span>
                </div>
                <div class="tenant-summary__value tenant-summary__value--xl">{{ $formatRub($sumLast) }}</div>

                {{-- No duplicate "Период" here: it's already in the badge --}}
                <div class="tenant-summary__inline-kpis">
                    <div class="tenant-summary__inline-kpi">
                        <span>
                            Торговых мест с начислениями
                            <span class="tenant-summary__help-icon" title="Количество уникальных торговых мест, по которым есть начисления в выбранном периоде." aria-label="Подсказка: торговых мест с начислениями">?</span>
                        </span>
                        <span>{{ $formatInt($spacesLast) }}</span>
                    </div>
                    <div class="tenant-summary__inline-kpi">
                        <span>
                            Строк начислений
                            <span class="tenant-summary__help-icon" title="Количество строк начислений за период. На одно торговое место может приходиться несколько строк." aria-label="Подсказка: строк начислений">?</span>
                        </span>
                        <span>{{ $formatInt($countPeriod) }}</span>
                    </div>
                </div>
            </div>

        </div>
    @endif
</div>
