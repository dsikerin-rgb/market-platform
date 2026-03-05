<x-cabinet-layout :tenant="$tenant" title="Новое обращение">
    <form method="POST" action="{{ route('cabinet.requests.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-3">
            <h2 class="text-base font-semibold text-slate-900">Создать обращение</h2>

            <label class="block">
                <span class="text-sm text-slate-600">Тема</span>
                <input class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm" type="text" name="subject" value="{{ old('subject') }}" required>
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Категория</span>
                <select name="category" class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm" required>
                    @foreach($categories as $value => $label)
                        <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea class="mt-1.5 w-full rounded-2xl border-slate-200 px-4 py-3 text-sm" name="description" rows="5" required>{{ old('description') }}</textarea>
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Фото / вложения (до 3 файлов)</span>
                <input class="mt-1.5 w-full text-sm" type="file" name="attachments[]" multiple>
            </label>
        </section>

        <button class="w-full rounded-2xl bg-slate-900 text-white py-3 text-sm font-semibold" type="submit">
            Отправить обращение
        </button>
    </form>
</x-cabinet-layout>
