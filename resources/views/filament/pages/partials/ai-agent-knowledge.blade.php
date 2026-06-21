<div>
    <style>
        .ai-knowledge{display:grid;gap:10px}
        .ai-knowledge__head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}
        .ai-knowledge__title{font-size:16px;font-weight:750;line-height:1.25;color:#0f172a}
        .dark .ai-knowledge__title{color:#f8fafc}
        .ai-knowledge__copy{margin-top:4px;font-size:13px;line-height:1.45;color:#64748b}
        .dark .ai-knowledge__copy{color:#94a3b8}
        .ai-knowledge__row{display:grid;grid-template-columns:minmax(170px,1.1fr) minmax(120px,.8fr) minmax(120px,.8fr) minmax(110px,.6fr);gap:12px;align-items:start;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:12px;background:rgba(255,255,255,.74)}
        .dark .ai-knowledge__row{background:rgba(15,23,42,.44);border-color:rgba(148,163,184,.22)}
        .ai-knowledge__label{font-size:14px;font-weight:750;line-height:1.35;color:#0f172a}
        .dark .ai-knowledge__label{color:#f8fafc}
        .ai-knowledge__meta{font-size:12px;line-height:1.4;color:#64748b}
        .dark .ai-knowledge__meta{color:#94a3b8}
        .ai-knowledge__confidence{display:inline-flex;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
        .ai-knowledge__confidence--low{border-color:rgba(245,158,11,.28);background:rgba(254,243,199,.78);color:#92400e}
        .ai-knowledge__empty{padding:18px;border:1px dashed rgba(148,163,184,.42);border-radius:12px;color:#64748b}
        @media (max-width:900px){.ai-knowledge__head{display:block}.ai-knowledge__row{grid-template-columns:1fr}}
    </style>

    <div class="ai-knowledge__head">
        <div>
            <div class="ai-knowledge__title">Справочник знаний агента</div>
            <div class="ai-knowledge__copy">
                Общие знания агента по рынку: кто за что отвечает, источник и уровень доверия.
            </div>
        </div>
    </div>

    <div class="ai-knowledge">
        @forelse ($knowledgeEntries as $row)
            <div class="ai-knowledge__row">
                <div>
                    <div class="ai-knowledge__label">{{ $row['label'] }}</div>
                    <div class="ai-knowledge__meta">
                        {{ $row['dictionary'] }}
                        @if (filled($row['topic']))
                            · {{ $row['topic'] }}
                        @endif
                    </div>
                </div>

                <div class="ai-knowledge__meta">
                    <strong>Ответственный:</strong><br>
                    {{ $row['responsible'] ?: 'Не указан' }}
                </div>

                <div class="ai-knowledge__meta">
                    <strong>Источник:</strong><br>
                    {{ $row['source'] }}<br>
                    {{ $row['updated_at'] }}
                </div>

                <div>
                    <span class="ai-knowledge__confidence {{ ((int) $row['confidence']) < 70 ? 'ai-knowledge__confidence--low' : '' }}">
                        {{ $row['confidence'] }}%
                    </span>
                </div>
            </div>
        @empty
            <div class="ai-knowledge__empty">
                Справочник агента пока пуст.
            </div>
        @endforelse
    </div>
</div>
