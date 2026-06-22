<div>
    <style>
        .ai-action-log{display:grid;gap:14px}
        .ai-action-log__head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}
        .ai-action-log__title{font-size:18px;font-weight:800;line-height:1.22;color:#0f172a}
        .dark .ai-action-log__title{color:#f8fafc}
        .ai-action-log__copy{margin-top:5px;max-width:760px;font-size:13px;line-height:1.45;color:#64748b}
        .dark .ai-action-log__copy{color:#94a3b8}
        .ai-action-log__tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .ai-action-log__filters{display:grid;grid-template-columns:minmax(220px,1fr) 170px 190px auto auto;gap:10px;align-items:center;padding:12px;border:1px solid rgba(148,163,184,.22);border-radius:14px;background:rgba(248,250,252,.78)}
        .dark .ai-action-log__filters{border-color:rgba(148,163,184,.2);background:rgba(15,23,42,.34)}
        .ai-action-log__field{height:40px;border:1px solid rgba(148,163,184,.34);border-radius:10px;background:#fff;padding:0 12px;font-size:13px;color:#0f172a;outline:none}
        .dark .ai-action-log__field{background:#0f172a;border-color:rgba(148,163,184,.24);color:#f8fafc}
        .ai-action-log__button{display:inline-flex;align-items:center;justify-content:center;height:40px;border-radius:10px;border:1px solid rgba(14,165,233,.28);background:rgba(224,242,254,.8);color:#075985;padding:0 13px;font-size:13px;font-weight:750;white-space:nowrap}
        .ai-action-log__button--muted{border-color:rgba(148,163,184,.34);background:rgba(241,245,249,.82);color:#475569}
        .dark .ai-action-log__button{background:rgba(14,165,233,.14);color:#7dd3fc}
        .dark .ai-action-log__button--muted{background:rgba(30,41,59,.72);color:#cbd5e1}
        .ai-action-log__row{display:grid;grid-template-columns:minmax(160px,.7fr) minmax(220px,1.1fr) minmax(280px,1.5fr);gap:14px;align-items:start;padding:16px;border:1px solid rgba(148,163,184,.24);border-radius:16px;background:rgba(255,255,255,.82);box-shadow:0 12px 34px rgba(15,23,42,.04)}
        .dark .ai-action-log__row{background:rgba(15,23,42,.44);border-color:rgba(148,163,184,.22)}
        .ai-action-log__meta{display:grid;gap:5px;font-size:12px;line-height:1.35;color:#64748b}
        .dark .ai-action-log__meta{color:#94a3b8}
        .ai-action-log__event{font-size:13px;font-weight:800;line-height:1.35;color:#0369a1}
        .dark .ai-action-log__event{color:#7dd3fc}
        .ai-action-log__action{margin-top:4px;font-size:15px;font-weight:800;line-height:1.34;color:#0f172a}
        .dark .ai-action-log__action{color:#f8fafc}
        .ai-action-log__status{display:inline-flex;align-items:center;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:800;border:1px solid rgba(14,165,233,.25);background:rgba(224,242,254,.72);color:#075985}
        .ai-action-log__status--success{border-color:rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
        .ai-action-log__status--pending{border-color:rgba(14,165,233,.25);background:rgba(224,242,254,.72);color:#075985}
        .ai-action-log__status--cancelled{border-color:rgba(148,163,184,.34);background:rgba(241,245,249,.8);color:#475569}
        .ai-action-log__status--failed{border-color:rgba(239,68,68,.25);background:rgba(254,226,226,.75);color:#991b1b}
        .ai-action-log__summary{display:grid;gap:5px;margin-top:10px;font-size:12px;line-height:1.38;color:#334155}
        .dark .ai-action-log__summary{color:#cbd5e1}
        .ai-action-log__summary strong{font-weight:800;color:#0f172a}
        .dark .ai-action-log__summary strong{color:#f8fafc}
        .ai-action-log__conversation{display:grid;gap:9px}
        .ai-action-log__conversation-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
        .ai-action-log__conversation-title{font-size:13px;font-weight:800;color:#0f172a}
        .dark .ai-action-log__conversation-title{color:#f8fafc}
        .ai-action-log__conversation-link{font-size:12px;font-weight:800;color:#0284c7;text-decoration:none}
        .ai-action-log__context{font-size:12px;line-height:1.35;color:#64748b}
        .ai-action-log__messages{display:grid;gap:7px}
        .ai-action-log__message{border:1px solid rgba(148,163,184,.22);border-radius:12px;background:rgba(248,250,252,.86);padding:9px 10px}
        .dark .ai-action-log__message{background:rgba(30,41,59,.48);border-color:rgba(148,163,184,.18)}
        .ai-action-log__message--target{border-color:rgba(14,165,233,.38);background:rgba(224,242,254,.72)}
        .dark .ai-action-log__message--target{background:rgba(14,165,233,.14)}
        .ai-action-log__message-meta{display:flex;justify-content:space-between;gap:12px;font-size:11px;font-weight:800;line-height:1.25;color:#64748b}
        .ai-action-log__message-body{margin-top:5px;font-size:13px;line-height:1.42;color:#0f172a}
        .dark .ai-action-log__message-body{color:#e2e8f0}
        .ai-action-log__chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
        .ai-action-log__chip{display:inline-flex;align-items:center;border-radius:999px;border:1px solid rgba(14,165,233,.28);background:rgba(224,242,254,.8);color:#075985;padding:4px 9px;font-size:12px;font-weight:800;text-decoration:none}
        .ai-action-log__empty{padding:18px;border:1px dashed rgba(148,163,184,.42);border-radius:12px;color:#64748b}
        .ai-action-log__section{display:grid;gap:12px;margin-top:18px}
        .ai-action-log__section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px}
        .ai-action-log__section-title{font-size:16px;font-weight:800;line-height:1.25;color:#0f172a}
        .dark .ai-action-log__section-title{color:#f8fafc}
        .ai-action-log__section-copy{margin-top:4px;font-size:13px;line-height:1.4;color:#64748b}
        .ai-action-log__counter{display:inline-flex;align-items:center;border-radius:999px;background:rgba(15,23,42,.06);padding:4px 9px;font-size:12px;font-weight:800;color:#475569}
        .dark .ai-action-log__counter{background:rgba(148,163,184,.14);color:#cbd5e1}
        @media (max-width:1100px){.ai-action-log__filters{grid-template-columns:1fr 1fr}.ai-action-log__row{grid-template-columns:1fr}}
        @media (max-width:640px){.ai-action-log__head{display:block}.ai-action-log__filters{grid-template-columns:1fr}.ai-action-log__tools{margin-top:10px}.ai-action-log__conversation-head{display:block}}
    </style>

    <div class="ai-action-log__head">
        <div>
            <div class="ai-action-log__title">Журнал ИИ-агента</div>
            <div class="ai-action-log__copy">
                Здесь видны проверки, ссылки, подготовленные действия и результат выполнения. Для событий, связанных с чатом, показывается короткий фрагмент переписки, чтобы было понятно, почему агент сделал именно это.
            </div>
        </div>

        <div class="ai-action-log__tools">
            <button type="button" class="ai-action-log__button ai-action-log__button--muted" wire:click="refreshActionLog">
                Обновить
            </button>
        </div>
    </div>

    <div class="ai-action-log__filters">
        <input
            type="search"
            class="ai-action-log__field"
            placeholder="Поиск по сотруднику, действию или переписке"
            wire:model.live.debounce.350ms="actionLogFilters.search"
        >

        <select class="ai-action-log__field" wire:model.live="actionLogFilters.status">
            <option value="">Все статусы</option>
            <option value="success">Выполнено</option>
            <option value="pending">Ждёт подтверждения</option>
            <option value="failed">Не выполнено</option>
            <option value="cancelled">Отменено</option>
        </select>

        <select class="ai-action-log__field" wire:model.live="actionLogFilters.event_type">
            <option value="">Все события</option>
            <option value="tool_call">Проверил данные</option>
            <option value="action_prepared">Подготовил действие</option>
            <option value="action_denied">Отклонил действие</option>
            <option value="action_cancelled">Отменил действие</option>
        </select>

        <button type="button" class="ai-action-log__button ai-action-log__button--muted" wire:click="resetActionLogFilters">
            Сбросить
        </button>
    </div>

    <div class="ai-action-log">
        @forelse ($actionLog as $row)
            <div class="ai-action-log__row" wire:key="ai-action-log-row-{{ $row['id'] }}">
                <div class="ai-action-log__meta">
                    <span class="ai-action-log__status ai-action-log__status--{{ $row['status'] }}">
                        {{ $row['status_label'] }}
                    </span>
                    <div>{{ $row['created_at'] }}</div>
                    <div>{{ $row['actor'] }}</div>
                    <div>{{ $row['market'] }}</div>
                    @if (filled($row['tool']))
                        <div>Инструмент: {{ $row['tool'] }}</div>
                    @endif
                    @if (($row['duration_ms'] ?? 0) > 0)
                        <div>Время: {{ $row['duration_ms'] }} мс</div>
                    @endif
                </div>

                <div>
                    <div class="ai-action-log__event">{{ $row['event_label'] }}</div>
                    <div class="ai-action-log__action">{{ $row['title'] }}</div>

                    <div class="ai-action-log__summary">
                        @foreach ($row['summary'] as $item)
                            <div><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</div>
                        @endforeach

                        @if (filled($row['result_message']))
                            <div><strong>Результат:</strong> {{ $row['result_message'] }}</div>
                        @endif
                    </div>

                    @if (! empty($row['chips']))
                        <div class="ai-action-log__chips">
                            @foreach ($row['chips'] as $chip)
                                <a class="ai-action-log__chip" href="{{ $chip['url'] }}" target="_blank" rel="noopener">
                                    {{ $chip['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="ai-action-log__conversation">
                    <div class="ai-action-log__conversation-head">
                        <div>
                            <div class="ai-action-log__conversation-title">
                                {{ $row['conversation_title'] }}
                            </div>
                            @if (filled($row['context_page_label']))
                                <div class="ai-action-log__context">
                                    Страница: {{ $row['context_page_label'] }}
                                </div>
                            @endif
                        </div>

                        @if (filled($row['context_page_url']))
                            <a class="ai-action-log__conversation-link" href="{{ $row['context_page_url'] }}">
                                Открыть страницу
                            </a>
                        @endif
                    </div>

                    @if (($row['conversation_messages_count'] ?? 0) > 0)
                        <div class="ai-action-log__context">
                            Сообщений в диалоге: {{ $row['conversation_messages_count'] }}. Ниже показан ближайший фрагмент.
                        </div>
                    @endif

                    @if (! empty($row['conversation_preview']))
                        <div class="ai-action-log__messages">
                            @foreach ($row['conversation_preview'] as $message)
                                <div class="ai-action-log__message {{ $message['is_target'] ? 'ai-action-log__message--target' : '' }}">
                                    <div class="ai-action-log__message-meta">
                                        <span>{{ $message['author'] }}</span>
                                        <span>{{ $message['created_at'] }}</span>
                                    </div>
                                    <div class="ai-action-log__message-body">{{ $message['body'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @elseif (! empty($row['message_preview']))
                        <div class="ai-action-log__messages">
                            <div class="ai-action-log__message ai-action-log__message--target">
                                <div class="ai-action-log__message-meta">
                                    <span>{{ $row['message_preview']['author'] }}</span>
                                    <span>{{ $row['message_preview']['created_at'] }}</span>
                                </div>
                                <div class="ai-action-log__message-body">{{ $row['message_preview']['body'] }}</div>
                            </div>
                        </div>
                    @else
                        <div class="ai-action-log__context">
                            Для этого события нет привязанного сообщения. Обычно так бывает у служебных проверок или старых записей.
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="ai-action-log__empty">
                Событий агента пока нет.
            </div>
        @endforelse
    </div>

    <div class="ai-action-log__section">
        <div class="ai-action-log__section-head">
            <div>
                <div class="ai-action-log__section-title">Переписки ИИ-агента</div>
                <div class="ai-action-log__section-copy">
                    Последние диалоги с агентом сохраняются отдельно от событий действий. Фильтр поиска выше также ищет по тексту сообщений.
                </div>
            </div>

            <span class="ai-action-log__counter">{{ count($conversationLog ?? []) }}</span>
        </div>

        <div class="ai-action-log">
            @forelse (($conversationLog ?? []) as $conversation)
                <div class="ai-action-log__row" wire:key="ai-conversation-log-row-{{ $conversation['id'] }}">
                    <div class="ai-action-log__meta">
                        <div>{{ $conversation['updated_at'] }}</div>
                        <div>{{ $conversation['actor'] }}</div>
                        <div>{{ $conversation['market'] }}</div>
                        <div>Сообщений: {{ $conversation['messages_count'] }}</div>
                    </div>

                    <div>
                        <div class="ai-action-log__event">Диалог</div>
                        <div class="ai-action-log__action">{{ $conversation['title'] }}</div>

                        <div class="ai-action-log__summary">
                            @if (filled($conversation['context_page_label']))
                                <div><strong>Страница:</strong> {{ $conversation['context_page_label'] }}</div>
                            @endif

                            @if (filled($conversation['context_page_url']))
                                <div>
                                    <a class="ai-action-log__conversation-link" href="{{ $conversation['context_page_url'] }}">
                                        Открыть страницу
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="ai-action-log__messages">
                        @forelse (($conversation['messages'] ?? []) as $message)
                            <div class="ai-action-log__message">
                                <div class="ai-action-log__message-meta">
                                    <span>{{ $message['author'] }}</span>
                                    <span>{{ $message['created_at'] }}</span>
                                </div>
                                <div class="ai-action-log__message-body">{{ $message['body'] }}</div>
                            </div>
                        @empty
                            <div class="ai-action-log__context">
                                В этом диалоге пока нет сообщений.
                            </div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="ai-action-log__empty">
                    Переписок с агентом пока нет.
                </div>
            @endforelse
        </div>
    </div>
</div>
