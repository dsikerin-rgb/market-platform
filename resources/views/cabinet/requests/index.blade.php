<x-cabinet-layout :tenant="$tenant" title="Общение с УК">
    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Обращения в управляющую компанию</h2>
                <p class="text-xs text-slate-500 mt-1">Создавайте обращения и отслеживайте ответы.</p>
            </div>
            <a class="shrink-0 rounded-2xl bg-slate-900 text-white px-4 py-2.5 text-xs font-semibold" href="{{ route('cabinet.requests.create') }}">
                Новое
            </a>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
            <a href="{{ route('cabinet.requests.create') }}" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-medium text-slate-700">Обращение в УК</a>
            <a href="{{ route('cabinet.customer-chat') }}" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-medium text-slate-700">Сообщения покупателей</a>
        </div>
    </section>

    <section class="space-y-3">
        @forelse($tickets as $ticket)
            <a class="block rounded-3xl bg-white border border-slate-200 p-4 shadow-sm" href="{{ route('cabinet.requests.show', $ticket->id) }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 break-words">{{ (string) $ticket->subject }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{{ $categories[$ticket->category] ?? 'Другое' }}</span>
                            <span class="text-slate-400">{{ $ticket->created_at?->format('d.m.Y H:i') }}</span>
                        </div>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium bg-slate-900 text-white">
                        {{ (string) $ticket->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-6 text-sm text-slate-500">
                Обращений пока нет.
            </div>
        @endforelse
    </section>
</x-cabinet-layout>
