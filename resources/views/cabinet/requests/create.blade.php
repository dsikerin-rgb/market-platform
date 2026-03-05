<x-cabinet-layout :tenant="$tenant" title="Новое обращение">
    <form method="POST" action="{{ route('cabinet.requests.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <section class="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-slate-900">Создать обращение</h2>
                <span class="inline-flex items-center rounded-full bg-sky-50 border border-sky-200 px-2.5 py-1 text-[11px] font-medium text-sky-700">
                    Ответ обычно в течение дня
                </span>
            </div>

            <label class="block">
                <span class="text-sm text-slate-600">Тема</span>
                <input
                    class="mt-1.5 w-full rounded-2xl border-2 border-sky-300 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:ring-2 focus:ring-sky-100"
                    type="text"
                    name="subject"
                    value="{{ old('subject') }}"
                    placeholder="Коротко опишите суть вопроса"
                    required
                >
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Категория</span>
                <select
                    name="category"
                    class="mt-1.5 w-full rounded-2xl border border-sky-200 bg-white px-4 py-3 text-sm text-slate-900 focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                    required
                >
                    @foreach($categories as $value => $label)
                        <option value="{{ $value }}" @selected(old('category', $defaultCategory ?? 'other') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Торговое место</span>
                <select
                    name="market_space_id"
                    class="mt-1.5 w-full rounded-2xl border border-sky-200 bg-white px-4 py-3 text-sm text-slate-900 focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                >
                    <option value="">Без привязки</option>
                    @foreach($spaces as $space)
                        @php
                            $spaceLabel = trim((string) ($space->code ?: $space->number ?: $space->display_name ?: ('#' . $space->id)));
                            $spaceName = trim((string) ($space->display_name ?? ''));
                        @endphp
                        <option value="{{ $space->id }}" @selected((string) old('market_space_id') === (string) $space->id)>
                            {{ $spaceLabel }}{{ $spaceName !== '' ? ' · ' . $spaceName : '' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-sm text-slate-600">Описание</span>
                <textarea
                    class="mt-1.5 w-full rounded-2xl border border-sky-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                    name="description"
                    rows="5"
                    placeholder="Опишите проблему подробнее: что произошло, где и когда"
                    required
                >{{ old('description') }}</textarea>
            </label>

            <div class="rounded-2xl border border-dashed border-sky-300 bg-sky-50/40 p-3">
                <label class="block">
                    <span class="text-sm text-slate-700">Фото / вложения</span>
                    <p class="mt-1 text-xs text-slate-500">До 3 файлов, каждый до 5 МБ</p>
                    <input class="mt-2 w-full text-sm text-slate-700 file:mr-3 file:rounded-xl file:border-0 file:bg-sky-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-sky-700" type="file" name="attachments[]" multiple>
                </label>
            </div>
        </section>

        <button class="w-full rounded-2xl bg-sky-600 text-white py-3 text-sm font-semibold shadow-[0_8px_22px_rgba(2,132,199,0.35)]" type="submit">
            Отправить обращение
        </button>
    </form>
</x-cabinet-layout>
