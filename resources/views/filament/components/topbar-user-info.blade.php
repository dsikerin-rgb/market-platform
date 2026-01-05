@php
    use Illuminate\Support\Facades\Lang;
    use Illuminate\Support\Str;

    /** @var \App\Models\User|null $user */
    $user = filament()->auth()->user();

    if (! $user) {
        return;
    }

    $displayName = (string) ($user->display_name ?? $user->name ?? '—');

    // 1) Определяем "сырую" роль (slug/имя роли)
    $rawRole = null;

    // Если у пользователя уже задан label роли (возможно, уже человекочитаемый) — берём его,
    // но всё равно попробуем перевести, если это окажется slug типа "market-admin".
    if (isset($user->primary_role_label) && filled($user->primary_role_label)) {
        $rawRole = (string) $user->primary_role_label;
    } elseif (method_exists($user, 'getRoleNames')) {
        $roleNames = collect($user->getRoleNames()->all());

        // Приоритет: если ролей несколько — показываем "главную" предсказуемо
        $rolePriority = [
            'super-admin',
            'market-admin',
            'market-finance',
            'market-maintenance',
            'market-security',
            'staff',
            'tenant',
            'merchant',
            'user',
        ];

        $rawRole = $roleNames
            ->sortBy(function (string $r) use ($rolePriority) {
                $idx = array_search($r, $rolePriority, true);
                return $idx === false ? 999 : $idx;
            })
            ->first();
    } elseif (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        $rawRole = 'super-admin';
    }

    $rawRole = is_string($rawRole) ? trim($rawRole) : null;

    // 2) Нормализуем в slug: "Super Admin" -> "super-admin", "market_admin" -> "market-admin"
    $roleSlug = filled($rawRole)
        ? Str::of($rawRole)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->replace('--', '-')
            ->toString()
        : null;

    // 3) Пытаемся найти перевод ТОЛЬКО в roles.php: roles.{slug}
    $roleLabel = null;

    if (filled($roleSlug)) {
        $key = "roles.{$roleSlug}";

        $localesToTry = array_values(array_unique(array_filter([
            app()->getLocale(),
            'ru',
        ])));

        foreach ($localesToTry as $locale) {
            if (Lang::has($key, $locale, false)) {
                $roleLabel = Lang::get($key, [], $locale);
                break;
            }
        }
    }

    // 4) Fallback: специальные синонимы + иначе показываем как есть
    if (! filled($roleLabel)) {
        $roleLabel = match ($roleSlug) {
            'super-admin', 'superadmin', 'super-administrator', 'superadministrator' => 'Суперадминистратор',
            default => $rawRole,
        };
    }
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
