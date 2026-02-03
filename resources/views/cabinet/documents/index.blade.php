<x-cabinet-layout :tenant="$tenant" title="Документы">
    <div class="space-y-3">
        @forelse($documents as $document)
            <div class="bg-white rounded-2xl p-4 border shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-400 uppercase">{{ $document->type }}</p>
                        <p class="text-sm font-medium">{{ $document->title }}</p>
                        <p class="text-xs text-slate-500">{{ $document->document_date?->format('d.m.Y') ?? 'Без даты' }}</p>
                    </div>
                    <a class="rounded-xl border px-3 py-1 text-sm" href="{{ route('cabinet.documents.download', $document->id) }}">Скачать</a>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-4 border text-sm text-slate-500">Документов пока нет.</div>
        @endforelse
    </div>
</x-cabinet-layout>
