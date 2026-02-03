<x-cabinet-layout :tenant="$tenant" title="Новая заявка">
    <form method="POST" action="{{ route('cabinet.requests.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div class="bg-white rounded-2xl p-4 border shadow-sm space-y-3">
            <label class="block">
                <span class="text-sm text-slate-600">Тема</span>
                <input class="mt-1 w-full rounded-xl border-slate-200" type="text" name="subject" value="{{ old('subject') }}" required>
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Категория</span>
                <select name="category" class="mt-1 w-full rounded-xl border-slate-200" required>
                    @foreach($categories as $value => $label)
                        <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea class="mt-1 w-full rounded-xl border-slate-200" name="description" rows="5" required>{{ old('description') }}</textarea>
            </label>
            <label class="block">
                <span class="text-sm text-slate-600">Фото/вложения (до 3 файлов)</span>
                <input class="mt-1 w-full" type="file" name="attachments[]" multiple>
            </label>
        </div>
        <button class="w-full rounded-xl bg-slate-900 text-white py-2 text-sm" type="submit">Отправить заявку</button>
    </form>
</x-cabinet-layout>
