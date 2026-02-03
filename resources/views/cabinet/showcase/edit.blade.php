<x-cabinet-layout :tenant="$tenant" title="Моя витрина">
    <form method="POST" action="{{ route('cabinet.showcase.update') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-3">
            <label class="block">
                <span class="text-sm text-slate-600">Название</span>
                <input class="mt-1 w-full rounded-xl border-slate-200" type="text" name="title" value="{{ old('title', $showcase->title ?? $tenant->display_name) }}">
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea class="mt-1 w-full rounded-xl border-slate-200" name="description" rows="4">{{ old('description', $showcase->description ?? '') }}</textarea>
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Телефон</span>
                <input class="mt-1 w-full rounded-xl border-slate-200" type="text" name="phone" value="{{ old('phone', $showcase->phone ?? '') }}">
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Telegram</span>
                <input class="mt-1 w-full rounded-xl border-slate-200" type="text" name="telegram" value="{{ old('telegram', $showcase->telegram ?? '') }}" placeholder="@username или ссылка">
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Сайт</span>
                <input class="mt-1 w-full rounded-xl border-slate-200" type="text" name="website" value="{{ old('website', $showcase->website ?? '') }}">
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Фото (до 5 шт)</span>
                <input class="mt-1 w-full" type="file" name="photos[]" multiple>
            </label>
        </div>

        @if(! empty($showcase?->photos))
            <div class="grid grid-cols-2 gap-3">
                @foreach($showcase->photos as $photo)
                    <div class="bg-white rounded-2xl border shadow-sm overflow-hidden">
                        <img class="w-full h-32 object-cover" src="{{ \Illuminate\Support\Facades\Storage::url($photo) }}" alt="Фото витрины">
                    </div>
                @endforeach
            </div>
        @endif

        <button class="w-full rounded-xl bg-slate-900 text-white py-2 text-sm" type="submit">Сохранить витрину</button>

        <div class="text-xs text-slate-500 text-center">
            Публичная ссылка: <a class="underline" href="{{ route('cabinet.showcase.public', $tenant->slug) }}" target="_blank" rel="noreferrer">/v/{{ $tenant->slug }}</a>
        </div>
    </form>
</x-cabinet-layout>
