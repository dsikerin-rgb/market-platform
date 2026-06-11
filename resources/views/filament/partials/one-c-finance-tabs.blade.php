@php
    $active = $active ?? 'documents';
    $compact = (bool) ($compact ?? false);
    $tabs = [
        'accruals' => [
            'label' => 'Начисления',
            'url' => \App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index'),
        ],
        'documents' => [
            'label' => 'Документы',
            'url' => \App\Filament\Pages\OneCReconciliation::getUrl(),
        ],
        'settlements' => [
            'label' => 'Расчёты',
            'url' => \App\Filament\Pages\OneCSettlements::getUrl(),
        ],
    ];
@endphp

<style>
    .onec-tabs-wrap {
        margin-bottom: 1rem;
    }

    .onec-tabs-wrap--compact {
        margin-bottom: -2.25rem;
    }

    .onec-tabs {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .5rem;
        border: 1px solid #dbe5ef;
        border-radius: 1.25rem;
        background: #fff;
        box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
        overflow-x: auto;
        max-width: 100%;
    }

    .onec-tab-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: .65rem 1.1rem;
        border-radius: .95rem;
        color: #475569;
        font-size: .95rem;
        font-weight: 700;
        line-height: 1.2;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
    }

    .onec-tab-link:hover {
        background: #f8fafc;
        color: #0f172a;
    }

    .onec-tab-link.is-active {
        background: rgba(14, 165, 233, .12);
        color: #0f172a;
        box-shadow: inset 0 0 0 1px rgba(14, 165, 233, .16);
    }
</style>

<div class="onec-tabs-wrap {{ $compact ? 'onec-tabs-wrap--compact' : '' }}">
    <nav class="onec-tabs" aria-label="Отчёты 1С">
        @foreach ($tabs as $key => $tab)
            <a
                href="{{ $tab['url'] }}"
                class="onec-tab-link {{ $active === $key ? 'is-active' : '' }}"
                @if ($active === $key) aria-current="page" @endif
            >
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
