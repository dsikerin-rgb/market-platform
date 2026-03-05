<x-cabinet-layout :tenant="$tenant" title="Обращение">
    <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-2">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs text-slate-500">{{ $categories[$ticket->category] ?? 'Другое' }}</p>
                <h2 class="text-lg font-semibold text-slate-900">{{ (string) $ticket->subject }}</h2>
            </div>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium bg-slate-900 text-white">{{ (string) $ticket->status }}</span>
        </div>
        <p class="text-sm text-slate-700">{{ (string) $ticket->description }}</p>
        <p class="text-xs text-slate-400">Создано {{ $ticket->created_at?->format('d.m.Y H:i') }}</p>
    </section>

    @if($attachments->isNotEmpty())
        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Вложения</h3>
            <ul class="space-y-2">
                @foreach($attachments as $file)
                    <li>
                        <a class="inline-flex items-center rounded-xl bg-slate-100 px-3 py-1.5 text-sm text-slate-700" href="{{ \Illuminate\Support\Facades\Storage::url($file->file_path) }}" target="_blank" rel="noreferrer">
                            {{ (string) $file->original_name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="space-y-2">
        @forelse($comments as $comment)
            @php
                $mine = (int) ($comment->user_id ?? 0) === (int) auth()->id();
            @endphp
            <article class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[88%] rounded-2xl px-3.5 py-3 border {{ $mine ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border-slate-200' }}">
                    <p class="text-[11px] {{ $mine ? 'text-slate-300' : 'text-slate-500' }}">
                        {{ $comment->user?->name ?? 'Администрация' }} · {{ $comment->created_at?->format('d.m.Y H:i') }}
                    </p>
                    <p class="mt-1 text-sm whitespace-pre-line">{{ (string) $comment->body }}</p>
                </div>
            </article>
        @empty
            <div class="rounded-2xl bg-white border border-slate-200 px-4 py-5 text-sm text-slate-500">
                Комментариев пока нет.
            </div>
        @endforelse
    </section>

    <form method="POST" action="{{ route('cabinet.requests.comment', $ticket->id) }}" enctype="multipart/form-data" class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
        @csrf
        <label class="block">
            <span class="text-sm text-slate-600">Ваш ответ</span>
            <textarea class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm" name="body" rows="4" required>{{ old('body') }}</textarea>
        </label>
        <label class="block">
            <span class="text-sm text-slate-600">Вложения (до 3 файлов)</span>
            <input class="mt-1.5 w-full text-sm" type="file" name="attachments[]" multiple>
        </label>
        <button class="w-full rounded-2xl bg-slate-900 text-white py-3 text-sm font-semibold" type="submit">Отправить</button>
    </form>
</x-cabinet-layout>
