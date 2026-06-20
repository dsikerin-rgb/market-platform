<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <div class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <span style="font-weight: 900; letter-spacing: -0.04em;">G</span>
                        </div>

                        <div>
                            <h2 class="aw-hero-heading">Настройки ИИ-агента</h2>
                            <p class="aw-hero-subheading">
                                Управляйте промптом, историей диалога и безопасным доступом агента к данным рынка.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Провайдер</div>
                        <div class="aw-stat-value" style="font-size: 1.25rem;">GigaChat</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Доступ</div>
                        <div class="aw-stat-value" style="font-size: 1.25rem;">Роли и журнал</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="aw-panel">
            <div class="aw-panel-head">
                <div>
                    <h3 class="aw-panel-title">Журнал действий агента</h3>
                    <p class="aw-panel-copy">
                        Последние черновики задач, событий, напоминаний и сообщений, которые агент подготовил для подтверждения.
                    </p>
                </div>
            </div>

            <div class="aw-panel-body">
                <style>
                    .ai-action-log{display:grid;gap:10px}
                    .ai-action-log__row{display:grid;grid-template-columns:minmax(116px,.7fr) minmax(150px,1fr) minmax(120px,.8fr) minmax(180px,1.2fr);gap:12px;align-items:start;padding:12px 14px;border:1px solid rgba(148,163,184,.24);border-radius:12px;background:rgba(255,255,255,.74)}
                    .dark .ai-action-log__row{background:rgba(15,23,42,.44);border-color:rgba(148,163,184,.22)}
                    .ai-action-log__meta{font-size:12px;line-height:1.35;color:#64748b}
                    .dark .ai-action-log__meta{color:#94a3b8}
                    .ai-action-log__title{font-size:14px;font-weight:750;line-height:1.35;color:#0f172a}
                    .dark .ai-action-log__title{color:#f8fafc}
                    .ai-action-log__status{display:inline-flex;align-items:center;width:max-content;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:750;border:1px solid rgba(14,165,233,.25);background:rgba(224,242,254,.72);color:#075985}
                    .ai-action-log__status--confirmed{border-color:rgba(16,185,129,.25);background:rgba(220,252,231,.72);color:#166534}
                    .ai-action-log__status--cancelled{border-color:rgba(148,163,184,.34);background:rgba(241,245,249,.8);color:#475569}
                    .ai-action-log__status--failed{border-color:rgba(239,68,68,.25);background:rgba(254,226,226,.75);color:#991b1b}
                    .ai-action-log__summary{display:grid;gap:3px;font-size:12px;line-height:1.35;color:#334155}
                    .dark .ai-action-log__summary{color:#cbd5e1}
                    .ai-action-log__summary strong{font-weight:750;color:#0f172a}
                    .dark .ai-action-log__summary strong{color:#f8fafc}
                    .ai-action-log__empty{padding:18px;border:1px dashed rgba(148,163,184,.42);border-radius:12px;color:#64748b}
                    @media (max-width:900px){.ai-action-log__row{grid-template-columns:1fr}}
                </style>

                <div class="ai-action-log">
                    @forelse ($this->actionLog as $row)
                        <div class="ai-action-log__row">
                            <div class="ai-action-log__meta">
                                <div>{{ $row['created_at'] }}</div>
                                <div>{{ $row['actor'] }}</div>
                                <div>{{ $row['market'] }}</div>
                            </div>

                            <div class="ai-action-log__title">{{ $row['title'] }}</div>

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
                            </div>
                        </div>
                    @empty
                        <div class="ai-action-log__empty">
                            Подготовленных действий пока нет.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <form wire:submit.prevent="save" class="aw-panel">
            <div class="aw-panel-head">
                <div>
                    <h3 class="aw-panel-title">Параметры консультанта</h3>
                    <p class="aw-panel-copy">
                        Изменения применяются к ИИ-чату в модалке "Диалоги". Проверки данных не меняют записи и ограничены текущим рынком.
                    </p>
                </div>
            </div>

            <div class="aw-panel-body">
                {{ $this->form }}
            </div>

            <div class="aw-panel-body">
                <div class="aw-sticky-actions">
                    <div class="aw-actions-row">
                        <x-filament::button type="submit" color="primary">
                            Сохранить настройки
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-filament-panels::page>
