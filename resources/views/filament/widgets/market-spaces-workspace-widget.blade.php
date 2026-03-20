<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell aw-shell--spaces">
        <section class="aw-hero aw-hero--spaces">
            <div class="aw-hero-stack--spaces">
                <div class="aw-hero-copy aw-hero-copy--spaces">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-home-modern" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Торговые места</h1>
                            <p class="aw-hero-subheading">
                                Каталог мест рынка: занятость, карточки мест и переходы в связанные разделы.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-hero-actions aw-hero-actions--spaces">
                    @if ($createUrl)
                        <a href="{{ $createUrl }}" class="aw-link-card aw-link-card--space-action aw-link-card--space-primary">
                            <div class="aw-link-icon aw-link-icon--space-action">
                                <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                            </div>

                            <div>
                                <p class="aw-link-title">Добавить место</p>
                                <p class="aw-link-copy aw-link-copy--space-action">Создать новую карточку торгового места.</p>
                            </div>
                        </a>
                    @endif

                    <a href="{{ $contractsUrl }}" class="aw-link-card aw-link-card--space-action">
                        <div class="aw-link-icon aw-link-icon--space-action">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                        </div>

                        <div>
                            <p class="aw-link-title">Договоры</p>
                            <p class="aw-link-copy aw-link-copy--space-action">Проверить привязку договоров к местам.</p>
                        </div>
                    </a>

                    <a href="{{ $tenantsUrl }}" class="aw-link-card aw-link-card--space-action">
                        <div class="aw-link-icon aw-link-icon--space-action">
                            <x-filament::icon icon="heroicon-o-users" class="h-5 w-5" />
                        </div>

                        <div>
                            <p class="aw-link-title">Арендаторы</p>
                            <p class="aw-link-copy aw-link-copy--space-action">Перейти к арендаторам и занятости мест.</p>
                        </div>
                    </a>
                </div>
            </div>
        </section>
    </div>
</x-filament::section>
