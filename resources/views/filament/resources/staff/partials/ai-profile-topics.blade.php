@php
    use App\Models\AiUserProfile;

    $profile = $record?->aiProfile instanceof AiUserProfile ? $record->aiProfile : null;
    $rejectedTopics = collect((array) ($profile?->rejected_topics ?? []))->values();
    $facts = (array) ($profile?->facts ?? []);
    $missingFields = [];

    if ($profile) {
        if (blank($profile->job_title)) {
            $missingFields[] = 'Должность';
        }
        if (blank($profile->responsibility_scope)) {
            $missingFields[] = 'Зона ответственности';
        }
        if (! $profile->birth_date) {
            $missingFields[] = 'Дата рождения';
        }
        if (blank($record?->phone ?? null)) {
            $missingFields[] = 'Телефон';
        }
        if (blank($record?->telegram_chat_id ?? null)) {
            $missingFields[] = 'Telegram';
        }
        if (blank($record?->staff_avatar_path ?? null)) {
            $missingFields[] = 'Фото профиля';
        }
    }
@endphp

<div class="grid gap-4">
    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="font-semibold text-gray-950 dark:text-white">Отклонённые темы</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">
            Агент не должен предлагать эти темы без явной просьбы пользователя.
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            @forelse ($rejectedTopics as $topic)
                <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                    {{ $topic['label'] ?? $topic['key'] ?? 'Тема' }}
                </span>
            @empty
                <span class="text-gray-500 dark:text-gray-400">Отклонённых тем пока нет.</span>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="font-semibold text-gray-950 dark:text-white">Что ещё нужно узнать</div>
        <div class="mt-1 text-gray-500 dark:text-gray-400">
            Эти пункты агент может уточнить в коротком знакомстве, если пользователь готов общаться.
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            @forelse ($missingFields as $field)
                <span class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-800 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-200">
                    {{ $field }}
                </span>
            @empty
                <span class="text-gray-500 dark:text-gray-400">Базовый профиль заполнен.</span>
            @endforelse
        </div>
    </div>

    @if ($facts !== [])
        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="font-semibold text-gray-950 dark:text-white">Служебные факты</div>
            <div class="mt-3 grid gap-2">
                @foreach ($facts as $key => $value)
                    <div class="grid gap-1 rounded-md bg-gray-50 p-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $key }}</div>
                        <div class="text-gray-900 dark:text-gray-100">{{ is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
