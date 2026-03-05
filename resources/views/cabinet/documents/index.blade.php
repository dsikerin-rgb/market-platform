<x-cabinet-layout :tenant="$tenant" title="Документы">
    <section class="space-y-3">
        @forelse($documents as $document)
            <article class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ (string) $document->type }}</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900 break-words">{{ (string) $document->title }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $document->document_date?->format('d.m.Y') ?? 'Без даты' }}</p>
                    </div>
                    <a
                        class="shrink-0 inline-flex items-center gap-1 rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                        href="{{ route('cabinet.documents.download', $document->id) }}"
                    >
                        Скачать
                    </a>
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-6 text-sm text-slate-500">
                Документов пока нет.
            </div>
        @endforelse
    </section>
</x-cabinet-layout>
