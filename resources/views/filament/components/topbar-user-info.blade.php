@php
    /** @var \App\Models\User|null $user */
    $user = filament()->auth()->user();

    if (! $user) {
        return;
    }

    $roleName = null;

    if (method_exists($user, 'getRoleNames')) {
        $roleName = $user->getRoleNames()->first();
    } elseif (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        $roleName = 'super-admin';
    }

    $roleLabel = match ($roleName) {
        'super-admin' => 'Суперадминистратор',
        default => $roleName,
    };
@endphp

<div class="flex flex-col items-end mr-2 leading-tight">
    <div class="text-xs font-bold text-gray-900">
        {{ $user->name }}
    </div>

    @if ($roleLabel)
        <div class="text-[11px] text-gray-500 font-normal">
            {{ $roleLabel }}
        </div>
    @endif
</div>
