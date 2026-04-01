<header class="staff-edit-hero">
    @if ($breadcrumbs)
        <div class="staff-edit-hero__breadcrumbs">
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        </div>
    @endif

    <div class="staff-edit-hero__top">
        <div class="staff-edit-hero__main">
            <p class="staff-edit-hero__kicker">Сотрудник</p>

            @if (filled($heading))
                <h1 class="staff-edit-hero__heading">{{ $heading }}</h1>
            @endif

            @if (filled($subheading))
                <div class="staff-edit-hero__subheading">
                    {{ $subheading }}
                </div>
            @endif
        </div>

        @if ($actions)
            <div class="staff-edit-hero__actions">
                <x-filament::actions
                    :actions="$actions"
                    :alignment="$actionsAlignment"
                />
            </div>
        @endif
    </div>

    <dl class="staff-edit-hero__grid">
        <div class="staff-edit-hero__item">
            <dt>Рынок</dt>
            <dd>{{ $hero['market'] ?? '—' }}</dd>
        </div>

        <div class="staff-edit-hero__item">
            <dt>Сотрудник</dt>
            <dd>{{ $hero['name'] ?? '—' }}</dd>
        </div>

        <div class="staff-edit-hero__item staff-edit-hero__item--muted">
            <dt>Email</dt>
            <dd>{{ $hero['email'] ?? '—' }}</dd>
        </div>

        <div class="staff-edit-hero__item staff-edit-hero__item--telegram">
            <dt>Telegram</dt>
            <dd>{{ $hero['telegram'] ?? '—' }}</dd>
        </div>

        <div class="staff-edit-hero__item staff-edit-hero__item--muted">
            <dt>Роли</dt>
            <dd>{{ $hero['roles'] ?? '—' }}</dd>
        </div>
    </dl>
</header>
