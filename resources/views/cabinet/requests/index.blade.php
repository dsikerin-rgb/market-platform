<x-cabinet-layout :tenant="$tenant" title="Мои заявки">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">Мои заявки</h2>
        <a class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm" href="{{ route('cabinet.requests.create') }}">Создать</a>
    </div>

    <div class="space-y-3">
        @forelse($tickets as $ticket)
            <a class="block bg-white rounded-2xl p-4 border shadow-sm" href="{{ route('cabinet.requests.show', $ticket->id) }}">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">{{ $ticket->subject }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $categories[$ticket->category] ?? 'Другое' }} · {{ $ticket->created_at?->format('d.m.Y') }}
                        </p>
                    </div>
                    <span class="text-xs rounded-full px-2 py-1 bg-slate-100 text-slate-500">
                        {{ $ticket->status }}
                    </span>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-2xl p-4 border text-sm text-slate-500">Заявок пока нет.</div>
        @endforelse
    </div>
</x-cabinet-layout>
