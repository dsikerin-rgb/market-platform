<div>
    <style>
        .ai-action-log{display:grid;gap:10px}
        .ai-action-log__head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}
        .ai-action-log__title{font-size:16px;font-weight:750;line-height:1.25;color:#0f172a}
        .dark .ai-action-log__title{color:#f8fafc}
        .ai-action-log__copy{margin-top:4px;font-size:13px;line-height:1.45;color:#64748b}
        .dark .ai-action-log__copy{color:#94a3b8}
        .ai-action-log__row{display:grid;grid-template-columns:minmax(116px,.65fr) minmax(140px,.85fr) minmax(160px,1fr) minmax(120px,.7fr) minmax(180px,1.2fr);gap:12px;align-items:start;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:12px;background:rgba(255,255,255,.74)}
        .dark .ai-action-log__row{background:rgba(15,23,42,.44);border-color:rgba(148,163,184,.22)}
        .ai-action-log__meta{font-size:12px;line-height:1.35;color:#64748b}
        .dark .ai-action-log__meta{color:#94a3b8}
        .ai-action-log__action{font-size:14px;font-weight:750;line-height:1.35;color:#0f172a}
        .dark .ai-action-log__action{color:#f8fafc}
        .ai-action-log__event{font-size:13px;font-weight:750;line-height:1.35;color:#0369a1}
        .dark .ai-action-log__event{color:#7dd3fc}
        .ai-action-log__status{display:inline-flex;align-items:center;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(14,165,233,.25);background:rgba(224,242,254,.72);color:#075985}
        .ai-action-log__status--success{border-color:rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
        .ai-action-log__status--pending{border-color:rgba(14,165,233,.25);background:rgba(224,242,254,.72);color:#075985}
        .ai-action-log__status--cancelled{border-color:rgba(148,163,184,.34);background:rgba(241,245,249,.8);color:#475569}
        .ai-action-log__status--failed{border-color:rgba(239,68,68,.25);background:rgba(254,226,226,.75);color:#991b1b}
        .ai-action-log__summary{display:grid;gap:3px;font-size:12px;line-height:1.35;color:#334155}
        .dark .ai-action-log__summary{color:#cbd5e1}
        .ai-action-log__summary strong{font-weight:750;color:#0f172a}
        .dark .ai-action-log__summary strong{color:#f8fafc}
        .ai-action-log__empty{padding:18px;border:1px dashed rgba(148,163,184,.42);border-radius:12px;color:#64748b}
        @media (max-width:900px){.ai-action-log__head{display:block}.ai-action-log__row{grid-template-columns:1fr}}
    </style>

    <div class="ai-action-log__head">
        <div>
            <div class="ai-action-log__title">Журнал действий агента</div>
            <div class="ai-action-log__copy">
                Последние проверки, ссылки, черновики действий и результаты выполнения. Здесь видно, что агент пытался сделать и чем это закончилось.
            </div>
        </div>
    </div>

    <div class="ai-action-log">
        @forelse ($actionLog as $row)
            <div class="ai-action-log__row">
                <div class="ai-action-log__meta">
                    <div>{{ $row['created_at'] }}</div>
                    <div>{{ $row['actor'] }}</div>
                    <div>{{ $row['market'] }}</div>
                </div>

                <div class="ai-action-log__event">{{ $row['event_label'] }}</div>

                <div class="ai-action-log__action">{{ $row['title'] }}</div>

                <div>
                    <span class="ai-action-log__status ai-action-log__status--{{ $row['status'] }}">
                        {{ $row['status_label'] }}
                    </span>
                </div>

                <div class="ai-action-log__summary">
                    @foreach ($row['summary'] as $item)
                        <div><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</div>
                    @endforeach

                    @if (filled($row['result_message']))
                        <div><strong>Результат:</strong> {{ $row['result_message'] }}</div>
                    @endif
                    @if (($row['duration_ms'] ?? 0) > 0)
                        <div><strong>Время:</strong> {{ $row['duration_ms'] }} мс</div>
                    @endif
                    @if (($row['chips_count'] ?? 0) > 0)
                        <div><strong>Ссылок:</strong> {{ $row['chips_count'] }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="ai-action-log__empty">
                Событий агента пока нет.
            </div>
        @endforelse
    </div>
</div>
