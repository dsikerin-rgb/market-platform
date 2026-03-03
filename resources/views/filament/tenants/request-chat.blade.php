{{-- resources/views/filament/tenants/request-chat.blade.php --}}

@props([
    'request' => null,
    'ticket' => null,
    'messages' => [],
])

@php
    $items = is_array($messages) ? $messages : [];
    $subject = trim((string) ($request?->subject ?? ''));
    $status = trim((string) ($request?->status ?? ''));
    $statusLabel = match ($status) {
        'new' => 'Новое',
        'in_progress' => 'В работе',
        'resolved' => 'Решено',
        'closed' => 'Закрыто',
        default => $status !== '' ? $status : '—',
    };
@endphp

@once
    <style>
        .tenant-request-chat {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tenant-request-chat__meta {
            font-size: 12px;
            opacity: 0.75;
            line-height: 1.4;
        }

        .tenant-request-chat__list {
            max-height: 360px;
            overflow-y: auto;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            padding: 12px;
            background: rgba(0, 0, 0, 0.015);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .dark .tenant-request-chat__list {
            border-color: rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.03);
        }

        .tenant-request-chat__row {
            display: flex;
            justify-content: flex-start;
        }

        .tenant-request-chat__row.is-mine {
            justify-content: flex-end;
        }

        .tenant-request-chat__bubble {
            max-width: 86%;
            border-radius: 14px;
            padding: 8px 10px;
            background: #eef2ff;
            color: #111827;
        }

        .dark .tenant-request-chat__bubble {
            background: rgba(99, 102, 241, 0.18);
            color: #e5e7eb;
        }

        .tenant-request-chat__bubble.is-tenant {
            background: #f3f4f6;
        }

        .dark .tenant-request-chat__bubble.is-tenant {
            background: rgba(255, 255, 255, 0.08);
        }

        .tenant-request-chat__bubble.is-mine {
            background: #2563eb;
            color: #fff;
        }

        .tenant-request-chat__head {
            font-size: 11px;
            opacity: 0.8;
            margin-bottom: 4px;
        }

        .tenant-request-chat__body {
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.35;
            font-size: 13px;
        }

        .tenant-request-chat__empty {
            font-size: 13px;
            opacity: 0.75;
        }

        .tenant-request-chat__hint {
            font-size: 12px;
            opacity: 0.75;
        }
    </style>
@endonce

<div class="tenant-request-chat">
    <div class="tenant-request-chat__meta">
        <div><strong>Тема:</strong> {{ $subject !== '' ? $subject : '—' }}</div>
        <div><strong>Статус:</strong> {{ $statusLabel }}</div>
    </div>

    <div class="tenant-request-chat__list">
        @forelse($items as $item)
            @php
                $isMine = (bool) ($item['is_mine'] ?? false);
                $isTenantSide = (bool) ($item['is_tenant_side'] ?? false);
            @endphp
            <div class="tenant-request-chat__row {{ $isMine ? 'is-mine' : '' }}">
                <div class="tenant-request-chat__bubble {{ $isMine ? 'is-mine' : '' }} {{ $isTenantSide ? 'is-tenant' : '' }}">
                    <div class="tenant-request-chat__head">
                        {{ (string) ($item['author'] ?? 'Пользователь') }} • {{ (string) ($item['time'] ?? '—') }}
                    </div>
                    <div class="tenant-request-chat__body">{{ (string) ($item['body'] ?? '') }}</div>
                </div>
            </div>
        @empty
            <div class="tenant-request-chat__empty">Сообщений пока нет.</div>
        @endforelse
    </div>

    <div class="tenant-request-chat__hint">Введите ответ ниже и нажмите «Отправить».</div>
</div>
