<x-cabinet-layout :tenant="$tenant" title="Мои торговые места">
    <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-2">
        <h2 class="text-lg font-semibold">Договор аренды</h2>
        @if($contract)
            <div class="text-sm text-slate-600 space-y-1">
                <p>Номер: <strong>{{ $contract->number ?? '—' }}</strong></p>
                <p>Срок: {{ $contract->starts_at?->format('d.m.Y') ?? '—' }} — {{ $contract->ends_at?->format('d.m.Y') ?? '—' }}</p>
                <p>Статус: {{ $contract->status ?? '—' }}</p>
            </div>
        @else
            <p class="text-sm text-slate-500">Данные договора пока не загружены. Можно использовать документ «Договор» в разделе документов.</p>
        @endif
    </div>

    <div class="space-y-3">
        @forelse($spaces as $space)
            <div class="bg-white rounded-2xl p-4 border shadow-sm">
                <p class="text-sm font-medium">{{ $space->number ?? $space->name ?? 'Торговое место' }}</p>
                <div class="mt-2 text-xs text-slate-500 space-y-1">
                    <p>Площадь: {{ $space->area_sqm ?? '—' }} м²</p>
                    <p>Ставка: {{ $space->rent_rate_value ? number_format((float) $space->rent_rate_value, 0, '.', ' ') . ' ₽' : '—' }}</p>
                    <p>Статус: {{ $space->status ?? '—' }}</p>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-4 border text-sm text-slate-500">Торговых мест пока нет.</div>
        @endforelse
    </div>
</x-cabinet-layout>
