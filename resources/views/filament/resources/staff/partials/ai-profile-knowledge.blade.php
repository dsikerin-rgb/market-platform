@php
    use App\Models\AiKnowledgeEntry;

    $recordId = (int) ($record?->id ?? 0);
    $entries = $recordId > 0
        ? AiKnowledgeEntry::query()
            ->where(function ($query) use ($recordId) {
                $query
                    ->where('source_user_id', $recordId)
                    ->orWhere('value->responsible_user_id', $recordId);
            })
            ->latest('updated_at')
            ->limit(20)
            ->get()
        : collect();
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="font-semibold text-gray-950 dark:text-white">Связанные знания агента</div>
    <div class="mt-1 text-gray-500 dark:text-gray-400">
        Записи из справочника агента, где сотрудник указан источником или ответственным.
    </div>

    <div class="mt-4 grid gap-3">
        @forelse ($entries as $entry)
            @php
                $value = (array) ($entry->value ?? []);
                $confidence = (int) ($entry->confidence ?? 0);
            @endphp

            <div class="grid gap-2 rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-950">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-semibold text-gray-950 dark:text-white">{{ $entry->label }}</div>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">
                        Доверие: {{ $confidence }}%
                    </span>
                </div>

                <div class="flex flex-wrap gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $entry->dictionary }}</span>
                    @if (filled($value['topic'] ?? null))
                        <span>{{ $value['topic'] }}</span>
                    @endif
                    @if ($entry->last_seen_at)
                        <span>{{ $entry->last_seen_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-md border border-dashed border-gray-300 p-4 text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Связанных записей пока нет.
            </div>
        @endforelse
    </div>
</div>
