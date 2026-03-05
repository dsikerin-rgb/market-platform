<x-cabinet-layout :tenant="$tenant" title="Торговые места">
    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-2">
        <h2 class="text-base font-semibold text-slate-900">Договор аренды</h2>
        @if($contract)
            <div class="text-sm text-slate-600 space-y-1">
                <p>Номер: <strong>{{ $contract->number ?? '—' }}</strong></p>
                <p>Срок: {{ $contract->starts_at?->format('d.m.Y') ?? '—' }} — {{ $contract->ends_at?->format('d.m.Y') ?? '—' }}</p>
                <p>Статус: {{ $contract->status ?? '—' }}</p>
            </div>
        @else
            <p class="text-sm text-slate-500">
                Данные договора пока не загружены. Можно использовать раздел «Документы» для просмотра договора.
            </p>
        @endif
    </section>

    <section class="space-y-3">
        @forelse($spaces as $space)
            <article class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-900">{{ $space->number ?? $space->name ?? 'Торговое место' }}</p>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-xl bg-slate-50 px-2.5 py-2 text-slate-600">
                        <p class="text-slate-400">Площадь</p>
                        <p class="font-semibold text-slate-800">{{ $space->area_sqm ?? '—' }} м²</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-2.5 py-2 text-slate-600">
                        <p class="text-slate-400">Ставка</p>
                        <p class="font-semibold text-slate-800">{{ $space->rent_rate_value ? number_format((float) $space->rent_rate_value, 0, '.', ' ') . ' ₽' : '—' }}</p>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-6 text-sm text-slate-500">
                Торговых мест пока нет.
            </div>
        @endforelse
    </section>
</x-cabinet-layout>
