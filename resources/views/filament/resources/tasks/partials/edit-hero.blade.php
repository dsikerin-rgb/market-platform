<header class="task-edit-hero">
    @php
        $titleAction = $heroActions['title'] ?? null;
        $descriptionAction = $heroActions['description'] ?? null;
        $statusAction = $heroActions['status'] ?? null;
        $priorityAction = $heroActions['priority'] ?? null;
        $dueAtAction = $heroActions['dueAt'] ?? null;
    @endphp

    @if ($breadcrumbs)
        <div class="task-edit-hero__breadcrumbs">
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        </div>
    @endif

    <div class="task-edit-hero__top">
        <div class="task-edit-hero__main">
            @if (filled($heading))
                @if ($titleAction instanceof \Filament\Actions\Action)
                    <button
                        type="button"
                        class="task-edit-hero__heading-button task-edit-hero__editable"
                        wire:click="{{ $titleAction->getLivewireClickHandler() }}"
                        @if ($titleAction->getLivewireTarget()) wire:target="{{ $titleAction->getLivewireTarget() }}" @endif
                    >
                        <span class="task-edit-hero__heading-text">{{ $heading }}</span>
                    </button>
                @else
                    <h1 class="task-edit-hero__heading">{{ $heading }}</h1>
                @endif
            @endif

            @if (filled($subheading))
                <p class="task-edit-hero__subheading">{{ $subheading }}</p>
            @endif

            @if (filled($hero['description'] ?? null) || ($hero['canEditDescription'] ?? false))
                <div class="task-edit-hero__description">
                    <span class="task-edit-hero__description-label">Описание</span>

                    @if ($descriptionAction instanceof \Filament\Actions\Action)
                        <button
                            type="button"
                            class="task-edit-hero__description-button task-edit-hero__editable"
                            wire:click="{{ $descriptionAction->getLivewireClickHandler() }}"
                            @if ($descriptionAction->getLivewireTarget()) wire:target="{{ $descriptionAction->getLivewireTarget() }}" @endif
                        >
                            <span class="task-edit-hero__description-text">{{ $hero['description'] }}</span>
                        </button>
                    @else
                        <span class="task-edit-hero__description-text">{{ $hero['description'] }}</span>
                    @endif
                </div>
            @endif

            <div class="task-edit-hero__chips">
                @if ($statusAction instanceof \Filament\Actions\Action)
                    <button
                        type="button"
                        class="task-edit-hero__chip task-edit-hero__chip--status task-edit-hero__chip--action task-edit-hero__editable"
                        wire:click="{{ $statusAction->getLivewireClickHandler() }}"
                        @if ($statusAction->getLivewireTarget()) wire:target="{{ $statusAction->getLivewireTarget() }}" @endif
                    >
                        {{ $hero['status'] }}
                    </button>
                @else
                    <span class="task-edit-hero__chip task-edit-hero__chip--status">{{ $hero['status'] }}</span>
                @endif

                @if ($priorityAction instanceof \Filament\Actions\Action)
                    <button
                        type="button"
                        class="task-edit-hero__chip task-edit-hero__chip--action task-edit-hero__editable"
                        wire:click="{{ $priorityAction->getLivewireClickHandler() }}"
                        @if ($priorityAction->getLivewireTarget()) wire:target="{{ $priorityAction->getLivewireTarget() }}" @endif
                    >
                        {{ $hero['priority'] }}
                    </button>
                @else
                    <span class="task-edit-hero__chip">{{ $hero['priority'] }}</span>
                @endif
            </div>
        </div>

        @if ($actions)
            <div class="task-edit-hero__actions">
                <x-filament::actions
                    :actions="$actions"
                    :alignment="$actionsAlignment"
                />
            </div>
        @endif
    </div>

    <dl class="task-edit-hero__grid">
        <div class="task-edit-hero__item">
            <dt>Постановщик</dt>
            <dd>{{ $hero['creator'] }}</dd>
        </div>

        <div class="task-edit-hero__item">
            <dt>Создано</dt>
            <dd>{{ $hero['createdAt'] }}</dd>
        </div>

        <div class="task-edit-hero__item @if ($hero['canEditDueAt'] ?? false) task-edit-hero__item--actionable @endif">
            <dt>Дедлайн</dt>
            <dd>
                @if ($dueAtAction instanceof \Filament\Actions\Action)
                    <button
                        type="button"
                        class="task-edit-hero__value-button task-edit-hero__editable"
                        wire:click="{{ $dueAtAction->getLivewireClickHandler() }}"
                        @if ($dueAtAction->getLivewireTarget()) wire:target="{{ $dueAtAction->getLivewireTarget() }}" @endif
                    >
                        {{ $hero['dueAt'] }}
                    </button>
                @else
                    <span>{{ $hero['dueAt'] }}</span>
                @endif
            </dd>
        </div>
    </dl>
</header>
