<x-cabinet-layout :tenant="$tenant" title="Заявка">
    <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-2">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-slate-500">{{ $categories[$ticket->category] ?? 'Другое' }}</p>
                <h2 class="text-lg font-semibold">{{ $ticket->subject }}</h2>
            </div>
            <span class="text-xs rounded-full px-2 py-1 bg-slate-100 text-slate-500">{{ $ticket->status }}</span>
        </div>
        <p class="text-sm text-slate-600">{{ $ticket->description }}</p>
        <p class="text-xs text-slate-400">Создано {{ $ticket->created_at?->format('d.m.Y H:i') }}</p>
    </div>

    @if($attachments->isNotEmpty())
        <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-2">
            <h3 class="text-sm font-medium">Вложения</h3>
            <ul class="text-sm text-slate-600 space-y-1">
                @foreach($attachments as $file)
                    <li>
                        <a class="text-slate-500 underline" href="{{ \Illuminate\Support\Facades\Storage::url($file->file_path) }}" target="_blank" rel="noreferrer">
                            {{ $file->original_name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-3">
        @forelse($comments as $comment)
            <div class="bg-white rounded-2xl p-4 border shadow-sm">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>{{ $comment->user?->name ?? 'Администрация' }}</span>
                    <span>{{ $comment->created_at?->format('d.m.Y H:i') }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-700">{{ $comment->body }}</p>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-4 border text-sm text-slate-500">Комментариев пока нет.</div>
        @endforelse
    </div>

    <form method="POST" action="{{ route('cabinet.requests.comment', $ticket->id) }}" enctype="multipart/form-data" class="bg-white rounded-2xl p-4 border shadow-sm space-y-3">
        @csrf
        <label class="block">
            <span class="text-sm text-slate-600">Ответ</span>
            <textarea class="mt-1 w-full rounded-xl border-slate-200" name="body" rows="4" required>{{ old('body') }}</textarea>
        </label>
        <label class="block">
            <span class="text-sm text-slate-600">Вложения (до 3 файлов)</span>
            <input class="mt-1 w-full" type="file" name="attachments[]" multiple>
        </label>
        <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm" type="submit">Отправить</button>
    </form>
</x-cabinet-layout>
