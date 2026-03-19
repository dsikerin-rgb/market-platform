<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Начисления</h1>
                            <p class="aw-hero-subheading">
                                Диагностический слой по 1С-начислениям и историческому импорту. Основной сценарий —
                                контролировать, сколько строк пришло из 1С, сколько связалось с договорами и почему
                                оставшиеся строки не удалось привязать автоматически.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-stat-grid">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Рынок</div>
                        <div class="aw-stat-value" style="font-size: 1.15rem;">
                            {{ $marketName ?: 'Выберите рынок' }}
                        </div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">1С-строк</div>
                        <div class="aw-stat-value">{{ number_format($oneC, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Связаны</div>
                        <div class="aw-stat-value">{{ number_format($linked, 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Последний период</div>
                        <div class="aw-stat-value" style="font-size: 1.15rem;">{{ $latestPeriodLabel }}</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="aw-grid">
            <div class="aw-column aw-column--sidebar">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Рабочие сценарии</h2>
                            <p class="aw-panel-copy">Переходите сразу в нужный слой без ручной переборки вкладок.</p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-action-grid">
                            <a href="{{ $oneCUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-building-office-2" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">1С-начисления</p>
                                    <p class="aw-link-copy">Все строки, которые реально пришли из 1С.</p>
                                </div>
                            </a>

                            <a href="{{ $linkedUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-link" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Связаны с договором</p>
                                    <p class="aw-link-copy">Строки с точной или безопасно разрешённой связкой с договором.</p>
                                </div>
                            </a>

                            <a href="{{ $withoutContractUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-link-slash" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Без договора</p>
                                    <p class="aw-link-copy">Строки, где договор пока не найден или не определён.</p>
                                </div>
                            </a>

                            <a href="{{ $ambiguousUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Неоднозначные</p>
                                    <p class="aw-link-copy">Строки, где найдено несколько кандидатов и нужна ручная проверка.</p>
                                </div>
                            </a>

                            <a href="{{ $historyUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Исторический импорт</p>
                                    <p class="aw-link-copy">Старый CSV-слой, который больше не считается финансовой истиной.</p>
                                </div>
                            </a>

                            <a href="{{ $allUrl }}" class="aw-link-card">
                                <div class="aw-link-icon">
                                    <x-filament::icon icon="heroicon-o-rectangle-stack" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Все начисления</p>
                                    <p class="aw-link-copy">Полный реестр строк для ручной проверки и сверки.</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </section>
            </div>

            <div class="aw-column aw-column--content">
                <section class="aw-panel">
                    <div class="aw-panel-head">
                        <div>
                            <h2 class="aw-panel-title">Состояние контура</h2>
                            <p class="aw-panel-copy">
                                Ключевой контроль здесь — насколько хорошо 1С-начисления связываются с договорами и какой
                                объём строк остаётся без понятной связи.
                            </p>
                        </div>
                    </div>

                    <div class="aw-panel-body">
                        <div class="aw-stat-grid">
                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Неоднозначные</div>
                                <div class="aw-stat-value">{{ number_format($ambiguous, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Без договора</div>
                                <div class="aw-stat-value">{{ number_format($unmatched, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Ещё не проверены</div>
                                <div class="aw-stat-value">{{ number_format($unchecked, 0, ',', ' ') }}</div>
                            </div>

                            <div class="aw-stat-card">
                                <div class="aw-stat-label">Исторический слой</div>
                                <div class="aw-stat-value">{{ number_format($history, 0, ',', ' ') }}</div>
                            </div>
                        </div>

                        @if ($issues !== [])
                            <div class="aw-card-list" style="margin-top: 1.25rem;">
                                @foreach ($issues as $issue)
                                    <article class="aw-list-card aw-list-card--compact">
                                        <div class="aw-list-card-meta">
                                            <span class="aw-badge aw-badge--warning">Проблема</span>
                                            <span class="aw-list-card-date">{{ number_format($issue['count'], 0, ',', ' ') }} строк</span>
                                        </div>

                                        <div class="aw-list-card-title">{{ $issue['note'] }}</div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-filament::section>
