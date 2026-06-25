@php
    $title = \App\Filament\Resources\MarketDocumentResource::documentTitleLabel($record);
    $typeLabel = (string) ($typeMeta['label'] ?? 'Файл');
    $folderLabel = $record->folder?->displayName() ?: 'В корне';
    $uploadedBy = trim((string) ($record->uploadedBy?->name ?: $record->uploadedBy?->email ?: 'Не указано'));
    $owner = trim((string) ($record->owner?->name ?: $record->owner?->email ?: ''));
    $market = trim((string) ($record->market?->name ?: ''));
    $state = filled($record->archived_at) ? 'В корзине' : 'На диске';
    $activityEvents = $activityEvents ?? collect();

    $properties = [
        ['label' => 'Тип', 'value' => $typeLabel],
        ['label' => 'Размер', 'value' => $record->fileSizeLabel()],
        ['label' => 'Раздел', 'value' => $record->visibilityLabel()],
        ['label' => 'Папка', 'value' => $folderLabel],
        ['label' => 'Добавлен', 'value' => $record->created_at?->format('d.m.Y H:i') ?? 'Не указано'],
        ['label' => 'Загрузил', 'value' => $uploadedBy],
        ['label' => 'Состояние', 'value' => $state],
    ];

    if ($owner !== '') {
        $properties[] = ['label' => 'Владелец', 'value' => $owner];
    }

    if ($market !== '') {
        $properties[] = ['label' => 'Рынок', 'value' => $market];
    }
@endphp

<div class="space-y-5 text-sm text-gray-700 dark:text-gray-200">
    <div class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white/80 p-4 dark:border-gray-700 dark:bg-gray-900/70">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950 dark:text-sky-300">
            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            <div class="break-words text-base font-semibold leading-snug text-gray-950 dark:text-white">{{ $title }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $typeLabel }} · {{ $record->fileSizeLabel() }}</div>
        </div>
    </div>

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($properties as $property)
            <div class="rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 dark:border-gray-700 dark:bg-gray-900/55">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $property['label'] }}</dt>
                <dd class="mt-1 break-words text-sm leading-snug text-gray-950 dark:text-white">{{ $property['value'] }}</dd>
            </div>
        @endforeach
    </dl>

    @if (($canViewActivityLog ?? false) && $activityEvents->isNotEmpty())
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white/80 p-4 dark:border-gray-700 dark:bg-gray-900/70">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Последние действия</h3>

                @if (filled($activityLogUrl ?? null))
                    <a href="{{ $activityLogUrl }}" class="text-xs font-medium text-sky-600 hover:text-sky-700 dark:text-sky-300 dark:hover:text-sky-200">
                        Открыть журнал
                    </a>
                @endif
            </div>

            <div class="space-y-2">
                @foreach ($activityEvents as $event)
                    @php
                        $actor = trim((string) ($event->actor?->name ?: $event->actor?->email ?: 'Система'));
                    @endphp
                    <div class="flex items-start justify-between gap-3 border-t border-gray-100 pt-2 first:border-t-0 first:pt-0 dark:border-gray-800">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $event->actionLabel() }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $actor }}</div>
                        </div>
                        <div class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                            {{ $event->created_at?->format('d.m.Y H:i') ?? '' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif (($canViewActivityLog ?? false) && filled($activityLogUrl ?? null))
        <a href="{{ $activityLogUrl }}" class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700 dark:text-sky-300 dark:hover:text-sky-200">
            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
            <span>Открыть журнал действий</span>
        </a>
    @endif
</div>
