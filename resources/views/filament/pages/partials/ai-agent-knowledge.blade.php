<div>
    <style>
        .ai-knowledge{display:grid;gap:10px}
        .ai-knowledge__head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}
        .ai-knowledge__title{font-size:16px;font-weight:750;line-height:1.25;color:#0f172a}
        .dark .ai-knowledge__title{color:#f8fafc}
        .ai-knowledge__copy{margin-top:4px;font-size:13px;line-height:1.45;color:#64748b}
        .dark .ai-knowledge__copy{color:#94a3b8}
        .ai-knowledge__row{display:grid;grid-template-columns:minmax(220px,1.2fr) minmax(220px,1.1fr) minmax(150px,.75fr) minmax(130px,.65fr) minmax(180px,.8fr);gap:12px;align-items:start;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:12px;background:rgba(255,255,255,.74)}
        .ai-knowledge__row--inactive{opacity:.72}
        .dark .ai-knowledge__row{background:rgba(15,23,42,.44);border-color:rgba(148,163,184,.22)}
        .ai-knowledge__label{font-size:14px;font-weight:750;line-height:1.35;color:#0f172a}
        .dark .ai-knowledge__label{color:#f8fafc}
        .ai-knowledge__meta{font-size:12px;line-height:1.4;color:#64748b}
        .dark .ai-knowledge__meta{color:#94a3b8}
        .ai-knowledge__badge{display:inline-flex;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(59,130,246,.22);background:rgba(219,234,254,.66);color:#1d4ed8}
        .ai-knowledge__status{display:inline-flex;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(148,163,184,.28);background:rgba(241,245,249,.82);color:#475569}
        .ai-knowledge__status--approved{border-color:rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
        .ai-knowledge__status--rejected{border-color:rgba(239,68,68,.24);background:rgba(254,226,226,.74);color:#991b1b}
        .ai-knowledge__status--stale{border-color:rgba(245,158,11,.28);background:rgba(254,243,199,.78);color:#92400e}
        .ai-knowledge__confidence{display:inline-flex;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
        .ai-knowledge__confidence--medium{border-color:rgba(59,130,246,.24);background:rgba(219,234,254,.72);color:#1d4ed8}
        .ai-knowledge__confidence--low{border-color:rgba(245,158,11,.28);background:rgba(254,243,199,.78);color:#92400e}
        .ai-knowledge__actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
        .ai-knowledge__edit{grid-column:1/-1;display:grid;grid-template-columns:minmax(220px,.8fr) minmax(280px,1.4fr) minmax(100px,.35fr) auto;gap:10px;align-items:end;padding-top:10px;border-top:1px solid rgba(148,163,184,.2)}
        .ai-knowledge__field{display:grid;gap:5px;font-size:12px;font-weight:700;color:#475569}
        .dark .ai-knowledge__field{color:#cbd5e1}
        .ai-knowledge__input{width:100%;border:1px solid rgba(148,163,184,.38);border-radius:10px;background:#fff;padding:8px 10px;font-size:13px;line-height:1.35;color:#0f172a}
        .dark .ai-knowledge__input{background:rgba(15,23,42,.7);border-color:rgba(148,163,184,.32);color:#f8fafc}
        .ai-knowledge__empty{padding:18px;border:1px dashed rgba(148,163,184,.42);border-radius:12px;color:#64748b}
        @media (max-width:900px){.ai-knowledge__head{display:block}.ai-knowledge__row,.ai-knowledge__edit{grid-template-columns:1fr}}
    </style>

    <div class="ai-knowledge__head">
        <div>
            <div class="ai-knowledge__title">Справочник знаний агента</div>
            <div class="ai-knowledge__copy">
                Общие знания агента по рынку: ответственность, правила, процессы, термины, источник, уровень доверия и проверка super-admin.
            </div>
        </div>
    </div>

    <div class="ai-knowledge">
        @forelse ($knowledgeEntries as $row)
            <div class="ai-knowledge__row {{ in_array($row['status'], ['rejected', 'stale'], true) ? 'ai-knowledge__row--inactive' : '' }}">
                <div>
                    <div class="ai-knowledge__label">{{ $row['label'] }}</div>
                    <div class="ai-knowledge__meta">
                        <span class="ai-knowledge__badge">{{ $row['dictionary_label'] }}</span>
                        <span class="ai-knowledge__status ai-knowledge__status--{{ $row['status'] }}">{{ $row['status_label'] }}</span>
                        @if (filled($row['topic']))
                            · {{ $row['topic'] }}
                        @elseif (filled($row['subject']))
                            · {{ $row['subject'] }}
                        @endif
                    </div>
                </div>

                <div class="ai-knowledge__meta">
                    @if (filled($row['responsible']))
                        <strong>Ответственный:</strong><br>
                        {{ $row['responsible'] }}
                    @else
                        <strong>Факт:</strong><br>
                        {{ $row['fact'] ?: 'Не указан' }}
                    @endif
                </div>

                <div class="ai-knowledge__meta">
                    <strong>Источник:</strong><br>
                    {{ $row['source'] }}<br>
                    {{ $row['updated_at'] }}
                    @if (filled($row['reviewed_by']))
                        <br><strong>Проверил:</strong> {{ $row['reviewed_by'] }}
                        @if (filled($row['reviewed_at']))
                            · {{ $row['reviewed_at'] }}
                        @endif
                    @endif
                    @if (filled($row['authority_reason']))
                        <br>{{ $row['authority_reason'] }}
                    @endif
                </div>

                <div>
                    <span class="ai-knowledge__confidence {{ ((int) $row['confidence']) < 60 ? 'ai-knowledge__confidence--low' : (((int) $row['confidence']) < 80 ? 'ai-knowledge__confidence--medium' : '') }}">
                        {{ $row['confidence_label'] }} · {{ $row['confidence'] }}%
                    </span>
                </div>

                <div class="ai-knowledge__actions">
                    @if ($row['status'] !== 'approved')
                        <x-filament::button type="button" size="xs" color="success" wire:click="approveKnowledge({{ $row['id'] }})">
                            Подтвердить
                        </x-filament::button>
                    @endif

                    @if ($row['status'] !== 'draft')
                        <x-filament::button type="button" size="xs" color="gray" wire:click="returnKnowledgeToDraft({{ $row['id'] }})">
                            В черновик
                        </x-filament::button>
                    @endif

                    @if ($row['status'] !== 'stale')
                        <x-filament::button type="button" size="xs" color="warning" wire:click="markKnowledgeStale({{ $row['id'] }})">
                            Устарело
                        </x-filament::button>
                    @endif

                    @if ($row['status'] !== 'rejected')
                        <x-filament::button type="button" size="xs" color="danger" wire:click="rejectKnowledge({{ $row['id'] }})">
                            Отклонить
                        </x-filament::button>
                    @endif

                    <x-filament::button type="button" size="xs" color="primary" wire:click="editKnowledge({{ $row['id'] }})">
                        Править
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        size="xs"
                        color="gray"
                        wire:click="deleteKnowledge({{ $row['id'] }})"
                        wire:confirm="Удалить знание агента? Это действие нельзя отменить."
                    >
                        Удалить
                    </x-filament::button>
                </div>

                @if ($editingKnowledgeId === $row['id'])
                    <div class="ai-knowledge__edit">
                        <label class="ai-knowledge__field">
                            Название
                            <input class="ai-knowledge__input" type="text" wire:model.defer="knowledgeEditData.label">
                        </label>

                        <label class="ai-knowledge__field">
                            Факт
                            <input class="ai-knowledge__input" type="text" wire:model.defer="knowledgeEditData.fact">
                        </label>

                        <label class="ai-knowledge__field">
                            Доверие, %
                            <input class="ai-knowledge__input" type="number" min="1" max="100" step="1" wire:model.defer="knowledgeEditData.confidence">
                        </label>

                        <div class="ai-knowledge__actions">
                            <x-filament::button type="button" size="xs" color="success" wire:click="saveKnowledgeEdit">
                                Сохранить
                            </x-filament::button>
                            <x-filament::button type="button" size="xs" color="gray" wire:click="cancelKnowledgeEdit">
                                Отмена
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="ai-knowledge__empty">
                Справочник агента пока пуст.
            </div>
        @endforelse
    </div>
</div>
