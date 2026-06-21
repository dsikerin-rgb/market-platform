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

        <form wire:submit.prevent="save" class="aw-panel">
            <div class="aw-panel-head">
                <div>
                    <h3 class="aw-panel-title">Параметры консультанта</h3>
                    <p class="aw-panel-copy">
                        Настройки разложены по вкладкам. Изменения применяются к ИИ-чату в модалке "Диалоги".
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
