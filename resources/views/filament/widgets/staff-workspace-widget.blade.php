<x-filament::section>
    @include('filament.partials.admin-workspace-styles')

    <div class="aw-shell aw-shell--staff">
        <section class="aw-hero aw-hero--staff">
            <div class="aw-hero-stack--staff">
                <div class="aw-hero-copy aw-hero-copy--staff">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-user-group" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Сотрудники</h1>
                            <p class="aw-hero-subheading">
                                Внутренняя команда рынка: роли, доступы и приглашения по выбранному контуру.
                            </p>
                        </div>
                    </div>
                </div>

                @if ($createUrl || $invitationsUrl)
                    <div class="aw-hero-actions aw-hero-actions--staff">
                        @if ($createUrl)
                            <a href="{{ $createUrl }}" class="aw-link-card aw-link-card--staff-action aw-link-card--staff-inline">
                                <div class="aw-link-icon aw-link-icon--staff-action">
                                    <x-filament::icon icon="heroicon-o-user-plus" class="h-5 w-5" />
                                </div>
                                <div>
                                    <p class="aw-link-title">Добавить сотрудника</p>
                                    <p class="aw-link-copy aw-link-copy--staff-action">Новый участник внутренней команды.</p>
                                </div>
                            </a>
                        @endif

                        @if ($invitationsUrl)
                            <a href="{{ $invitationsUrl }}" class="aw-link-card aw-link-card--staff-action aw-link-card--staff-inline">
                                <div class="aw-link-icon aw-link-icon--staff-action">
                                    <x-filament::icon icon="heroicon-o-envelope-open" class="h-5 w-5" />
                                </div>
                                <div>
                                    <div class="aw-link-head aw-link-head--staff">
                                        <p class="aw-link-title">Приглашения</p>
                                        @if ($pendingInvitations > 0)
                                            <span class="aw-chip aw-chip--staff-alert">{{ number_format($pendingInvitations, 0, ',', ' ') }}</span>
                                        @endif
                                    </div>
                                    <p class="aw-link-copy aw-link-copy--staff-action">Активные и ожидающие приглашения.</p>
                                </div>
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-filament::section>
