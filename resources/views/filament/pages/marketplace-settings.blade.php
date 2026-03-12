<x-filament-panels::page>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <div class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-m-shopping-bag" class="h-6 w-6" />
                        </div>

                        <div>
                            <h2 class="aw-hero-heading">Настройки маркетплейса</h2>
                            <p class="aw-hero-subheading">
                                Управляйте брендом, публичными контактами и промо-слоем. Слайды встроены прямо в этот экран,
                                чтобы не приходилось переходить на отдельную страницу без необходимости.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Всего слайдов</div>
                        <div class="aw-stat-value">{{ $slidesCount }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Активные</div>
                        <div class="aw-stat-value">{{ $activeSlidesCount }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="aw-grid">
            <div class="aw-column aw-column--sidebar">
                <div class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h3 class="aw-panel-title">Слайды маркетплейса</h3>
                            <p class="aw-panel-copy">Последние карточки, быстрые переходы и контроль активности.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            @if (! empty($slidesUrl))
                                <a href="{{ $slidesUrl }}" class="aw-link-card">
                                    <div class="aw-link-icon"><x-filament::icon icon="heroicon-m-photo" class="h-5 w-5" /></div>
                                    <div>
                                        <p class="aw-link-title">Все слайды</p>
                                        <p class="aw-link-copy">Управление порядком, контентом и активностью слайдов.</p>
                                        <div class="aw-link-meta">Открыть раздел слайдов</div>
                                    </div>
                                </a>
                            @endif

                            @if (! empty($publicMarketplaceUrl))
                                <a href="{{ $publicMarketplaceUrl }}" class="aw-link-card" target="_blank" rel="noopener">
                                    <div class="aw-link-icon"><x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-5 w-5" /></div>
                                    <div>
                                        <p class="aw-link-title">Проверить маркетплейс</p>
                                        <p class="aw-link-copy">Откройте публичную витрину и проверьте, как выглядит главный экран.</p>
                                        <div class="aw-link-meta">Открыть в новой вкладке</div>
                                    </div>
                                </a>
                            @endif
                        </div>

                        @if ($slidesPreview !== [])
                            <div class="aw-list" style="margin-top: 1.25rem;">
                                @foreach ($slidesPreview as $slide)
                                    <div class="aw-list-item">
                                        <div>
                                            <p class="aw-list-title">{{ $slide['title'] }}</p>
                                            <p class="aw-list-copy">
                                                Тема: {{ $slide['theme'] }}@if($slide['badge'] !== '') • Метка: {{ $slide['badge'] }}@endif • Порядок: {{ $slide['sort_order'] }}
                                            </p>
                                        </div>

                                        <span class="aw-chip">{{ $slide['is_active'] ? 'Активен' : 'Черновик' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="aw-empty" style="margin-top: 1.25rem;">
                                <x-filament::icon icon="heroicon-o-photo" class="h-8 w-8 text-slate-400" />
                                <div class="aw-empty-title">Слайды ещё не настроены</div>
                                <div class="aw-empty-copy">После первого добавленного слайда здесь появится быстрый preview.</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="aw-column aw-column--content">
                <form wire:submit.prevent="save" class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h3 class="aw-panel-title">Параметры маркетплейса</h3>
                            <p class="aw-panel-copy">Бренд, контакты, hero-блок и режим публикации продавцов.</p>
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
        </div>
    </div>
</x-filament-panels::page>
