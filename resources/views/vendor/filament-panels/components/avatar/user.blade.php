@props([
    'user' => filament()->auth()->user(),
])

@php
    $src = filament()->getUserAvatarUrl($user);
    $name = trim((string) filament()->getUserName($user));
    $alt = __('filament-panels::layout.avatar.alt', ['name' => $name]);
    $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = collect($words)
        ->take(2)
        ->map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8'))
        ->implode('');
    $initials = $initials !== '' ? $initials : '?';
    $color = method_exists($user, 'staffAvatarColor') ? $user->staffAvatarColor() : '#2563eb';
    $avatarAttributes = \Filament\Support\prepare_inherited_attributes($attributes)
        ->class(['fi-user-avatar']);
@endphp

@if (filled($src))
    <x-filament::avatar
        :src="$src"
        :alt="$alt"
        :attributes="$avatarAttributes"
    />
@else
    <span
        {{
            $avatarAttributes
                ->except(['loading'])
                ->merge([
                    'aria-label' => $alt,
                    'role' => 'img',
                    'style' => '--staff-avatar-color: ' . $color . '; display: inline-flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--staff-avatar-color) 16%, #ffffff); color: var(--staff-avatar-color); font-size: 0.75rem; font-weight: 700; line-height: 1;',
                ])
                ->class(['fi-avatar fi-circular fi-size-md staff-avatar-fallback'])
        }}
    >
        {{ $initials }}
    </span>
@endif
