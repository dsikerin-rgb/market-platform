@php
    $initials = static function (?string $name): string {
        $name = trim((string) $name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(static fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters));
    };

    $replyWord = static function (int $count): string {
        $lastTwo = $count % 100;
        $last = $count % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return 'ответов';
        }

        return match ($last) {
            1 => 'ответ',
            2, 3, 4 => 'ответа',
            default => 'ответов',
        };
    };
@endphp

<section class="staff-live-feed" wire:poll.30s>
    <style>
        .staff-live-feed {
            display: grid;
            gap: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 1.5rem;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        html.dark .staff-live-feed {
            border-color: rgba(148, 163, 184, 0.16);
            background: rgba(15, 23, 42, 0.74);
            box-shadow: 0 18px 36px rgba(2, 6, 23, 0.24);
        }

        .staff-live-feed__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.1rem 1.25rem 0;
        }

        .staff-live-feed__title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
            color: #0f172a;
            font-size: 1.1rem;
            font-weight: 800;
        }

        html.dark .staff-live-feed__title {
            color: #f8fafc;
        }

        .staff-live-feed__title-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.9rem;
            background: rgba(37, 99, 235, 0.12);
            color: #1d4ed8;
        }

        html.dark .staff-live-feed__title-icon {
            background: rgba(59, 130, 246, 0.14);
            color: #93c5fd;
        }

        .staff-live-feed__copy {
            margin: 0.3rem 0 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.55;
        }

        html.dark .staff-live-feed__copy {
            color: #94a3b8;
        }

        .staff-live-feed__composer {
            margin: 0 1.25rem;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 1.15rem;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            overflow: hidden;
        }

        html.dark .staff-live-feed__composer {
            border-color: rgba(148, 163, 184, 0.16);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.78));
        }

        .staff-live-feed__tabs {
            display: flex;
            gap: 0.3rem;
            padding: 0.65rem 0.75rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.14);
        }

        .staff-live-feed__tab {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 2.1rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: #2563eb;
            color: #fff;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .staff-live-feed__form {
            display: grid;
            gap: 0.75rem;
            padding: 0.8rem;
        }

        .staff-live-feed__textarea {
            width: 100%;
            min-height: 6.25rem;
            resize: vertical;
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 0.95rem;
            background: #fff;
            padding: 0.8rem 0.9rem;
            color: #0f172a;
            font-size: 0.95rem;
            line-height: 1.55;
            outline: none;
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }

        html.dark .staff-live-feed__textarea {
            border-color: rgba(148, 163, 184, 0.20);
            background: rgba(15, 23, 42, 0.86);
            color: #f8fafc;
        }

        .staff-live-feed__textarea:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.18);
        }

        .staff-live-feed__actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .staff-live-feed__tools {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .staff-live-feed__hint {
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.4;
        }

        html.dark .staff-live-feed__hint {
            color: #94a3b8;
        }

        .staff-live-feed__file-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            min-height: 2.5rem;
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 0.85rem;
            background: rgba(248, 250, 252, 0.92);
            padding: 0.55rem 0.85rem;
            color: #334155;
            font-size: 0.86rem;
            font-weight: 800;
            cursor: pointer;
            transition: border-color 150ms ease, background-color 150ms ease, transform 150ms ease;
        }

        .staff-live-feed__file-label:hover,
        .staff-live-feed__file-label:focus-within {
            border-color: rgba(37, 99, 235, 0.28);
            background: #fff;
            transform: translateY(-1px);
        }

        html.dark .staff-live-feed__file-label {
            border-color: rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.72);
            color: #dbeafe;
        }

        .staff-live-feed__file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .staff-live-feed__selected-files {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .staff-live-feed__selected-file {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            gap: 0.35rem;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            padding: 0.35rem 0.6rem;
            color: #1e3a8a;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .staff-live-feed__selected-file span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        html.dark .staff-live-feed__selected-file {
            border-color: rgba(59, 130, 246, 0.22);
            background: rgba(37, 99, 235, 0.12);
            color: #dbeafe;
        }

        .staff-live-feed__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            min-height: 2.5rem;
            border: 0;
            border-radius: 0.85rem;
            background: #2563eb;
            padding: 0.55rem 0.95rem;
            color: #fff;
            font-size: 0.88rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.22);
            transition: background-color 150ms ease, transform 150ms ease, box-shadow 150ms ease;
        }

        .staff-live-feed__button:hover,
        .staff-live-feed__button:focus-visible {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.28);
        }

        .staff-live-feed__error {
            color: #dc2626;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .staff-live-feed__list {
            display: grid;
            gap: 0.85rem;
            padding: 0 1.25rem 1.25rem;
        }

        .staff-live-feed__post {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.85rem;
            padding: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 1.1rem;
            background: rgba(248, 250, 252, 0.88);
        }

        html.dark .staff-live-feed__post {
            border-color: rgba(148, 163, 184, 0.14);
            background: rgba(255, 255, 255, 0.04);
        }

        .staff-live-feed__avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.65rem;
            height: 2.65rem;
            border-radius: 999px;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e3a8a;
            font-size: 0.8rem;
            font-weight: 800;
        }

        html.dark .staff-live-feed__avatar {
            background: linear-gradient(180deg, rgba(30, 64, 175, 0.9), rgba(15, 23, 42, 0.92));
            color: #dbeafe;
        }

        .staff-live-feed__meta {
            display: flex;
            align-items: baseline;
            gap: 0.45rem;
            flex-wrap: wrap;
            min-width: 0;
        }

        .staff-live-feed__author {
            color: #0f172a;
            font-size: 0.92rem;
            font-weight: 800;
        }

        html.dark .staff-live-feed__author {
            color: #f8fafc;
        }

        .staff-live-feed__time {
            color: #64748b;
            font-size: 0.78rem;
        }

        html.dark .staff-live-feed__time {
            color: #94a3b8;
        }

        .staff-live-feed__body {
            margin-top: 0.35rem;
            color: #1e293b;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        html.dark .staff-live-feed__body {
            color: #e2e8f0;
        }

        .staff-live-feed__comments {
            display: grid;
            gap: 0.65rem;
            margin-top: 0.85rem;
            border-top: 1px solid rgba(148, 163, 184, 0.16);
            padding-top: 0.85rem;
        }

        html.dark .staff-live-feed__comments {
            border-top-color: rgba(148, 163, 184, 0.14);
        }

        .staff-live-feed__comments-title {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        html.dark .staff-live-feed__comments-title {
            color: #94a3b8;
        }

        .staff-live-feed__comment {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.55rem;
        }

        .staff-live-feed__comment-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-size: 0.68rem;
            font-weight: 900;
        }

        html.dark .staff-live-feed__comment-avatar {
            background: rgba(14, 165, 233, 0.14);
            color: #bae6fd;
        }

        .staff-live-feed__comment-card {
            min-width: 0;
            border: 1px solid rgba(148, 163, 184, 0.14);
            border-radius: 0.9rem;
            background: rgba(255, 255, 255, 0.68);
            padding: 0.6rem 0.7rem;
        }

        html.dark .staff-live-feed__comment-card {
            border-color: rgba(148, 163, 184, 0.12);
            background: rgba(15, 23, 42, 0.38);
        }

        .staff-live-feed__comment-meta {
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .staff-live-feed__comment-author {
            color: #0f172a;
            font-size: 0.82rem;
            font-weight: 800;
        }

        html.dark .staff-live-feed__comment-author {
            color: #f8fafc;
        }

        .staff-live-feed__comment-body {
            margin-top: 0.25rem;
            color: #334155;
            font-size: 0.86rem;
            line-height: 1.5;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        html.dark .staff-live-feed__comment-body {
            color: #cbd5e1;
        }

        .staff-live-feed__comment-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.55rem;
            align-items: start;
        }

        .staff-live-feed__comment-input {
            width: 100%;
            min-height: 2.5rem;
            resize: vertical;
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 0.85rem;
            background: #fff;
            padding: 0.62rem 0.75rem;
            color: #0f172a;
            font-size: 0.86rem;
            line-height: 1.45;
            outline: none;
        }

        html.dark .staff-live-feed__comment-input {
            border-color: rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.62);
            color: #f8fafc;
        }

        .staff-live-feed__comment-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
        }

        .staff-live-feed__comment-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border: 0;
            border-radius: 0.85rem;
            background: #0f172a;
            color: #fff;
            cursor: pointer;
            transition: background-color 150ms ease, transform 150ms ease;
        }

        .staff-live-feed__comment-button:hover,
        .staff-live-feed__comment-button:focus-visible {
            background: #2563eb;
            transform: translateY(-1px);
        }

        html.dark .staff-live-feed__comment-button {
            background: #2563eb;
        }

        .staff-live-feed__attachments {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.75rem;
        }

        .staff-live-feed__attachment {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            min-width: 0;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 0.9rem;
            background: rgba(255, 255, 255, 0.72);
            padding: 0.65rem;
            color: inherit;
            text-decoration: none;
        }

        html.dark .staff-live-feed__attachment {
            border-color: rgba(148, 163, 184, 0.14);
            background: rgba(15, 23, 42, 0.44);
        }

        .staff-live-feed__attachment-thumb {
            width: 4.5rem;
            height: 3.25rem;
            border-radius: 0.7rem;
            object-fit: cover;
            background: #e2e8f0;
            flex-shrink: 0;
        }

        .staff-live-feed__attachment-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            flex-shrink: 0;
        }

        .staff-live-feed__attachment-name {
            min-width: 0;
            color: #0f172a;
            font-size: 0.88rem;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        html.dark .staff-live-feed__attachment-name {
            color: #f8fafc;
        }

        .staff-live-feed__attachment-meta {
            margin-top: 0.15rem;
            color: #64748b;
            font-size: 0.76rem;
        }

        html.dark .staff-live-feed__attachment-meta {
            color: #94a3b8;
        }

        .staff-live-feed__empty {
            padding: 1.5rem;
            border: 1px dashed rgba(148, 163, 184, 0.22);
            border-radius: 1.1rem;
            color: #64748b;
            text-align: center;
        }

        html.dark .staff-live-feed__empty {
            color: #94a3b8;
        }

        @media (max-width: 767px) {
            .staff-live-feed__head {
                flex-direction: column;
            }

            .staff-live-feed__post {
                grid-template-columns: minmax(0, 1fr);
            }

            .staff-live-feed__comment-form {
                grid-template-columns: minmax(0, 1fr);
            }

            .staff-live-feed__comment-button {
                width: 100%;
            }
        }
    </style>

    <div class="staff-live-feed__head">
        <div>
            <h3 class="staff-live-feed__title">
                <span class="staff-live-feed__title-icon">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
                </span>
                Живая лента
            </h3>
            <p class="staff-live-feed__copy">Сообщения для сотрудников рынка на главном экране.</p>
        </div>
    </div>

    <div class="staff-live-feed__composer">
        <div class="staff-live-feed__tabs">
            <span class="staff-live-feed__tab">
                <x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" class="h-4 w-4" />
                Сообщение
            </span>
        </div>

        <form class="staff-live-feed__form" wire:submit.prevent="createPost">
            <textarea
                class="staff-live-feed__textarea"
                wire:model.defer="body"
                placeholder="Напишите сообщение сотрудникам..."
            ></textarea>

            @error('body')
                <div class="staff-live-feed__error">{{ $message }}</div>
            @enderror

            @error('attachments.*')
                <div class="staff-live-feed__error">{{ $message }}</div>
            @enderror

            @if ($attachments !== [])
                <div class="staff-live-feed__selected-files">
                    @foreach ($attachments as $file)
                        @if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                            <div class="staff-live-feed__selected-file" title="{{ $file->getClientOriginalName() }}">
                                <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4" />
                                <span>{{ $file->getClientOriginalName() }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="staff-live-feed__actions">
                <div class="staff-live-feed__tools">
                    <label class="staff-live-feed__file-label">
                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4" />
                        Вложить файл
                        <input
                            class="staff-live-feed__file-input"
                            type="file"
                            wire:model="attachments"
                            multiple
                        >
                    </label>

                    <div class="staff-live-feed__hint">До 5 файлов, до 20 МБ каждый. Хранение в облаке.</div>
                </div>

                <div class="staff-live-feed__tools">
                    <div class="staff-live-feed__hint" wire:loading wire:target="attachments">Загрузка...</div>
                    <button type="submit" class="staff-live-feed__button" wire:loading.attr="disabled">
                        <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                        Отправить
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="staff-live-feed__list">
        @forelse ($posts as $post)
            @php
                $postAttachments = collect(data_get($post->meta, 'attachments', []))
                    ->filter(static fn ($attachment): bool => is_array($attachment) && filled($attachment['path'] ?? null))
                    ->values();
            @endphp

            <article class="staff-live-feed__post" wire:key="staff-feed-post-{{ $post->id }}">
                <div class="staff-live-feed__avatar">{{ $initials($post->author?->name) }}</div>

                <div>
                    <div class="staff-live-feed__meta">
                        <span class="staff-live-feed__author">{{ $post->author?->name ?? 'Сотрудник' }}</span>
                        <span class="staff-live-feed__time">{{ $post->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                    </div>

                    @if (filled($post->body))
                        <div class="staff-live-feed__body">{{ $post->body }}</div>
                    @endif

                    @if ($postAttachments->isNotEmpty())
                        <div class="staff-live-feed__attachments">
                            @foreach ($postAttachments as $attachment)
                                @php
                                    $path = (string) ($attachment['path'] ?? '');
                                    $url = \App\Support\MarketplaceMediaStorage::url($path);
                                    $name = trim((string) ($attachment['name'] ?? 'Файл'));
                                    $mime = trim((string) ($attachment['mime'] ?? ''));
                                    $size = (int) ($attachment['size'] ?? 0);
                                    $sizeLabel = $size > 0
                                        ? number_format($size / 1024 / 1024, 1, ',', ' ') . ' МБ'
                                        : null;
                                    $isImage = (bool) ($attachment['is_image'] ?? false);
                                    $previewUrl = $isImage ? \App\Support\MarketplaceMediaStorage::previewUrl($path) : null;
                                @endphp

                                @if ($url)
                                    <a class="staff-live-feed__attachment" href="{{ $url }}" target="_blank" rel="noopener">
                                        @if ($isImage && $previewUrl)
                                            <img class="staff-live-feed__attachment-thumb" src="{{ $previewUrl }}" alt="{{ $name }}" loading="lazy">
                                        @else
                                            <span class="staff-live-feed__attachment-icon">
                                                <x-filament::icon icon="heroicon-o-document" class="h-5 w-5" />
                                            </span>
                                        @endif

                                        <span style="min-width: 0;">
                                            <span class="staff-live-feed__attachment-name">{{ $name }}</span>
                                            <span class="staff-live-feed__attachment-meta">
                                                {{ $mime !== '' ? $mime : 'файл' }}@if ($sizeLabel) · {{ $sizeLabel }}@endif
                                            </span>
                                        </span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if ($commentsReady)
                        @php
                            $postComments = $post->comments ?? collect();
                        @endphp

                        <div class="staff-live-feed__comments">
                            @if ($postComments->isNotEmpty())
                                <div class="staff-live-feed__comments-title">
                                    <x-filament::icon icon="heroicon-o-chat-bubble-left" class="h-4 w-4" />
                                    {{ $postComments->count() }} {{ $replyWord($postComments->count()) }}
                                </div>

                                @foreach ($postComments as $comment)
                                    <div class="staff-live-feed__comment" wire:key="staff-feed-comment-{{ $comment->id }}">
                                        <div class="staff-live-feed__comment-avatar">{{ $initials($comment->author?->name) }}</div>

                                        <div class="staff-live-feed__comment-card">
                                            <div class="staff-live-feed__comment-meta">
                                                <span class="staff-live-feed__comment-author">{{ $comment->author?->name ?? 'Сотрудник' }}</span>
                                                <span class="staff-live-feed__time">{{ $comment->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                                            </div>

                                            <div class="staff-live-feed__comment-body">{{ $comment->body }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            <form class="staff-live-feed__comment-form" wire:submit.prevent="createComment({{ $post->id }})">
                                <textarea
                                    class="staff-live-feed__comment-input"
                                    wire:model.defer="commentBodies.{{ $post->id }}"
                                    placeholder="Написать комментарий..."
                                ></textarea>

                                <button
                                    type="submit"
                                    class="staff-live-feed__comment-button"
                                    title="Отправить комментарий"
                                    wire:loading.attr="disabled"
                                    wire:target="createComment({{ $post->id }})"
                                >
                                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                                </button>
                            </form>

                            @error('commentBodies.' . $post->id)
                                <div class="staff-live-feed__error">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="staff-live-feed__empty">В ленте пока нет сообщений.</div>
        @endforelse
    </div>
</section>
