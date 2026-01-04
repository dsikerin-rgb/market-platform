@php
    /** @var \App\Models\User|null $user */
    $user = filament()->auth()->user();

    if (! $user) {
        return;
    }

    $displayName = (string) ($user->display_name ?? $user->name ?? '—');

    $roleName = null;

    if (isset($user->primary_role_label) && filled($user->primary_role_label)) {
        $roleName = (string) $user->primary_role_label;
    } elseif (method_exists($user, 'getRoleNames')) {
        $roleName = $user->getRoleNames()->first();
    } elseif (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        $roleName = 'super-admin';
    }

    $roleName = is_string($roleName) ? trim($roleName) : null;

    $roleKey = $roleName
        ? strtolower(str_replace(['_', ' '], ['-', '-'], $roleName))
        : null;

    $roleLabel = match ($roleKey) {
        'super-admin', 'superadmin', 'super-administrator', 'superadministrator' => 'Суперадминистратор',
        default => $roleName,
    };
@endphp

<div class="hidden sm:flex items-center gap-x-3 mr-2">
    <div class="text-right" style="line-height: 1.1;">
        <div
            class="text-gray-900 dark:text-gray-50"
            style="font-weight: 700 !important; font-size: 14px !important;"
        >
            {{ $displayName }}
        </div>

        @if (filled($roleLabel))
            <div
                class="text-gray-500 dark:text-gray-400"
                style="font-weight: 400 !important; font-size: 11px !important; margin-top: 2px;"
            >
                {{ $roleLabel }}
            </div>
        @endif
    </div>
</div>
