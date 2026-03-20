<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell aw-shell--tenants">
        <section class="aw-hero aw-hero--tenants">
            <div class="aw-hero-stack--tenants">
                <div class="aw-hero-copy aw-hero-copy--tenants">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-users" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Арендаторы</h1>
                            <p class="aw-hero-subheading">
                                Карточки арендаторов рынка и быстрые переходы в договоры, начисления и обращения.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="aw-hero-actions aw-hero-actions--tenants">
                    @if ($createUrl)
                        <a href="{{ $createUrl }}" class="aw-link-card aw-link-card--tenant-action aw-link-card--tenant-primary">
                            <div class="aw-link-icon aw-link-icon--tenant-action">
                                <x-filament::icon icon="heroicon-o-plus" class="h-5 w-5" />
                            </div>
                            <div>
                                <p class="aw-link-title">Создать арендатора</p>
                                <p class="aw-link-copy aw-link-copy--tenant-action">Новая карточка арендатора рынка.</p>
                            </div>
                        </a>
                    @endif

                    <a href="{{ $contractsUrl }}" class="aw-link-card aw-link-card--tenant-action">
                        <div class="aw-link-icon aw-link-icon--tenant-action">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="aw-link-title">Договоры</p>
                            <p class="aw-link-copy aw-link-copy--tenant-action">Привязки к местам и договорный контур.</p>
                        </div>
                    </a>

                    <a href="{{ $accrualsUrl }}" class="aw-link-card aw-link-card--tenant-action">
                        <div class="aw-link-icon aw-link-icon--tenant-action">
                            <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="aw-link-title">Начисления</p>
                            <p class="aw-link-copy aw-link-copy--tenant-action">1С-начисления и строки без договора.</p>
                        </div>
                    </a>

                    <a href="{{ $requestsUrl }}" class="aw-link-card aw-link-card--tenant-action">
                        <div class="aw-link-icon aw-link-icon--tenant-action">
                            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="aw-link-title">Обращения</p>
                            <p class="aw-link-copy aw-link-copy--tenant-action">Диалоги и текущие запросы арендаторов.</p>
                        </div>
                    </a>
                </div>
            </div>
        </section>
    </div>
</x-filament::section>
