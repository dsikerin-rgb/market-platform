<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\Pages\Concerns;

use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantShowcase;
use App\Models\User;
use App\Services\Cabinet\TenantCabinetUserService;
use App\Services\Marketplace\MarketplaceDemoContentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait InteractsWithTenantCabinet
{
    /**
     * @var array<string, mixed>
     */
    protected array $cabinetPayload = [];

    /**
     * @return list<string>
     */
    protected function cabinetFieldKeys(): array
    {
        return [
            'cabinet_user_name',
            'cabinet_user_email',
            'cabinet_user_password',
            'cabinet_additional_users',
            'showcase_title',
            'showcase_description',
            'showcase_phone',
            'showcase_telegram',
            'showcase_website',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullCabinetPayloadFromForm(array &$data): array
    {
        $payload = [];

        foreach ($this->cabinetFieldKeys() as $key) {
            $payload[$key] = $data[$key] ?? null;
            unset($data[$key]);
        }

        $payload['cabinet_user_name'] = trim((string) ($payload['cabinet_user_name'] ?? ''));
        $payload['cabinet_user_email'] = Str::lower(trim((string) ($payload['cabinet_user_email'] ?? '')));
        $payload['cabinet_user_password'] = trim((string) ($payload['cabinet_user_password'] ?? ''));
        $payload['cabinet_additional_users'] = $this->normalizeAdditionalCabinetUsers(
            is_array($payload['cabinet_additional_users'] ?? null)
                ? $payload['cabinet_additional_users']
                : []
        );

        $payload['showcase_title'] = trim((string) ($payload['showcase_title'] ?? ''));
        $payload['showcase_description'] = trim((string) ($payload['showcase_description'] ?? ''));
        $payload['showcase_phone'] = trim((string) ($payload['showcase_phone'] ?? ''));
        $payload['showcase_telegram'] = trim((string) ($payload['showcase_telegram'] ?? ''));
        $payload['showcase_website'] = trim((string) ($payload['showcase_website'] ?? ''));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCabinetFormData(?Tenant $tenant): array
    {
        $state = [
            'cabinet_user_name' => null,
            'cabinet_user_email' => null,
            'cabinet_user_password' => null,
            'cabinet_additional_users' => [],
            'showcase_title' => null,
            'showcase_description' => null,
            'showcase_phone' => null,
            'showcase_telegram' => null,
            'showcase_website' => null,
        ];

        if (! $tenant) {
            return $state;
        }

        $user = $this->resolvePrimaryCabinetUser($tenant);
        if ($user) {
            $state['cabinet_user_name'] = (string) ($user->name ?? '');
            $state['cabinet_user_email'] = (string) ($user->email ?? '');
        }

        $state['cabinet_additional_users'] = $this->resolveAdditionalCabinetUsers($tenant, $user?->id)
            ->get()
            ->map(static fn (User $item): array => [
                'id' => (int) $item->id,
                'name' => (string) ($item->name ?? ''),
                'email' => (string) ($item->email ?? ''),
                'password' => '',
                'space_ids' => $item->relationLoaded('tenantSpaces')
                    ? $item->tenantSpaces->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all()
                    : [],
            ])
            ->values()
            ->all();

        $showcase = TenantShowcase::query()->where('tenant_id', (int) $tenant->id)->first();
        if ($showcase && ! app(MarketplaceDemoContentService::class)->isEnabled($tenant->market) && (bool) $showcase->is_demo) {
            $showcase = null;
        }
        if ($showcase) {
            $state['showcase_title'] = (string) ($showcase->title ?? '');
            $state['showcase_description'] = (string) ($showcase->description ?? '');
            $state['showcase_phone'] = (string) ($showcase->phone ?? '');
            $state['showcase_telegram'] = (string) ($showcase->telegram ?? '');
            $state['showcase_website'] = (string) ($showcase->website ?? '');
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateCabinetPayload(array $payload, ?Tenant $tenant): void
    {
        $email = (string) ($payload['cabinet_user_email'] ?? '');
        $password = (string) ($payload['cabinet_user_password'] ?? '');
        $existing = $tenant ? $this->resolvePrimaryCabinetUser($tenant) : null;

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'cabinet_user_email' => 'Введите корректный email.',
            ]);
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            throw ValidationException::withMessages([
                'cabinet_user_password' => 'Пароль должен быть не короче 8 символов.',
            ]);
        }

        if ($email !== '') {
            $emailTaken = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->when($existing, fn ($query) => $query->whereKeyNot((int) $existing->id))
                ->exists();

            if ($emailTaken) {
                throw ValidationException::withMessages([
                    'cabinet_user_email' => 'Этот email уже используется другим пользователем.',
                ]);
            }
        }

        $this->validateAdditionalCabinetUsers(
            is_array($payload['cabinet_additional_users'] ?? null)
                ? $payload['cabinet_additional_users']
                : [],
            $tenant,
            $existing
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function syncCabinetPayload(Tenant $tenant, array $payload): void
    {
        $email = (string) ($payload['cabinet_user_email'] ?? '');
        $name = (string) ($payload['cabinet_user_name'] ?? '');
        $password = (string) ($payload['cabinet_user_password'] ?? '');

        /** @var TenantCabinetUserService $cabinetUsers */
        $cabinetUsers = app(TenantCabinetUserService::class);
        $cabinetUser = $cabinetUsers->ensurePrimaryUser(
            $tenant,
            $name !== '' ? $name : null,
            $email !== '' ? $email : null,
            $password !== '' ? $password : null,
        );

        $this->syncAdditionalCabinetUsers(
            $tenant,
            is_array($payload['cabinet_additional_users'] ?? null)
                ? $payload['cabinet_additional_users']
                : [],
            $cabinetUser?->id ? (int) $cabinetUser->id : null
        );

        $showcaseData = [
            'title' => $this->emptyToNull((string) ($payload['showcase_title'] ?? '')),
            'description' => $this->emptyToNull((string) ($payload['showcase_description'] ?? '')),
            'phone' => $this->emptyToNull((string) ($payload['showcase_phone'] ?? '')),
            'telegram' => $this->emptyToNull((string) ($payload['showcase_telegram'] ?? '')),
            'website' => $this->emptyToNull((string) ($payload['showcase_website'] ?? '')),
        ];

        $showcase = TenantShowcase::query()->firstOrNew(['tenant_id' => (int) $tenant->id]);
        $hasShowcaseInput = collect($showcaseData)->contains(static fn ($value): bool => filled($value));

        if ($hasShowcaseInput || $showcase->exists) {
            $showcase->fill($showcaseData);
            $showcase->tenant_id = (int) $tenant->id;
            $showcase->is_demo = false;
            $showcase->save();
        }
    }

    protected function resolvePrimaryCabinetUser(Tenant $tenant): ?User
    {
        /** @var TenantCabinetUserService $cabinetUsers */
        $cabinetUsers = app(TenantCabinetUserService::class);

        return $cabinetUsers->resolvePrimaryUser($tenant);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id:?int,name:string,email:string,password:string,space_ids:list<int>}>
     */
    protected function normalizeAdditionalCabinetUsers(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $name = trim((string) ($row['name'] ?? ''));
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));
            $spaceIds = collect(is_array($row['space_ids'] ?? null) ? $row['space_ids'] : [])
                ->filter(static fn ($id): bool => is_numeric($id))
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($id === null && $name === '' && $email === '' && $password === '' && $spaceIds === []) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'space_ids' => $spaceIds,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{id:?int,name:string,email:string,password:string,space_ids:list<int>}>  $rows
     */
    protected function validateAdditionalCabinetUsers(array $rows, ?Tenant $tenant, ?User $primaryUser): void
    {
        $seenEmails = [];
        $primaryEmail = Str::lower(trim((string) ($primaryUser?->email ?? '')));
        $allowedSpaceIds = $tenant
            ? MarketSpace::query()
                ->where('tenant_id', (int) $tenant->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all()
            : [];

        if ($primaryEmail !== '') {
            $seenEmails[$primaryEmail] = true;
        }

        foreach ($rows as $index => $row) {
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $path = 'cabinet_additional_users.' . $index . '.email';
            $passwordPath = 'cabinet_additional_users.' . $index . '.password';

            if ($email === '') {
                throw ValidationException::withMessages([
                    $path => 'Укажите email сотрудника для входа в кабинет.',
                ]);
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    $path => 'Введите корректный email сотрудника.',
                ]);
            }

            if (isset($seenEmails[$email])) {
                throw ValidationException::withMessages([
                    $path => 'Email дублируется с другим кабинетным аккаунтом.',
                ]);
            }
            $seenEmails[$email] = true;

            if (($id === null || $id <= 0) && $password === '') {
                throw ValidationException::withMessages([
                    $passwordPath => 'Для нового сотрудника задайте пароль (не менее 8 символов).',
                ]);
            }

            if ($password !== '' && mb_strlen($password) < 8) {
                throw ValidationException::withMessages([
                    $passwordPath => 'Пароль сотрудника должен быть не короче 8 символов.',
                ]);
            }

            $query = User::query()->whereRaw('LOWER(email) = ?', [$email]);
            if ($primaryUser) {
                $query->whereKeyNot((int) $primaryUser->id);
            }
            if ($id) {
                $query->whereKeyNot($id);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    $path => 'Этот email уже используется другим пользователем.',
                ]);
            }

            if ($tenant && $id) {
                if ($primaryUser && (int) $primaryUser->id === $id) {
                    throw ValidationException::withMessages([
                        $path => 'Основной кабинетный аккаунт редактируется в верхнем блоке.',
                    ]);
                }

                $owned = $this->cabinetUsersQuery($tenant)->whereKey($id)->exists();
                if (! $owned) {
                    throw ValidationException::withMessages([
                        $path => 'Нельзя редактировать этот кабинетный аккаунт.',
                    ]);
                }
            }

            $spaceIds = collect(is_array($row['space_ids'] ?? null) ? $row['space_ids'] : [])
                ->filter(static fn ($value): bool => is_numeric($value))
                ->map(static fn ($value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value > 0)
                ->unique()
                ->values()
                ->all();

            if ($tenant && $spaceIds !== []) {
                $invalid = collect($spaceIds)
                    ->reject(static fn (int $spaceId): bool => in_array($spaceId, $allowedSpaceIds, true))
                    ->values()
                    ->all();

                if ($invalid !== []) {
                    throw ValidationException::withMessages([
                        'cabinet_additional_users.' . $index . '.space_ids' => 'Выбраны недопустимые торговые места для этого арендатора.',
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<int, array{id:?int,name:string,email:string,password:string,space_ids:list<int>}>  $rows
     */
    protected function syncAdditionalCabinetUsers(Tenant $tenant, array $rows, ?int $primaryId): void
    {
        $existing = $this->resolveAdditionalCabinetUsers($tenant, $primaryId)->get()->keyBy('id');
        $keepIds = [];
        $allowedSpaceIds = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $email = Str::lower(trim((string) ($row['email'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            $password = trim((string) ($row['password'] ?? ''));
            $spaceIds = collect(is_array($row['space_ids'] ?? null) ? $row['space_ids'] : [])
                ->filter(static fn ($value): bool => is_numeric($value))
                ->map(static fn ($value): int => (int) $value)
                ->filter(static fn (int $value): bool => $value > 0 && in_array($value, $allowedSpaceIds, true))
                ->unique()
                ->values()
                ->all();

            if ($email === '') {
                continue;
            }

            $cabinetUser = null;
            if ($id && $existing->has($id)) {
                $cabinetUser = $existing->get($id);
            }

            if (! $cabinetUser) {
                $cabinetUser = new User();
                $cabinetUser->tenant_id = (int) $tenant->id;
            }

            $cabinetUser->market_id = (int) $tenant->market_id;
            $cabinetUser->tenant_id = (int) $tenant->id;
            $cabinetUser->email = $email;
            $cabinetUser->name = $name !== '' ? $name : (trim((string) ($tenant->name ?? '')) ?: 'Сотрудник арендатора');

            if ($password !== '') {
                $cabinetUser->password = Hash::make($password);
            } elseif (! $cabinetUser->exists) {
                $cabinetUser->password = Hash::make(Str::random(32));
            }

            $cabinetUser->save();
            $this->ensureCabinetUserRole($cabinetUser, 'merchant-user');
            $cabinetUser->tenantSpaces()->sync($spaceIds);

            $keepIds[] = (int) $cabinetUser->id;
        }

        $toDeleteQuery = $this->resolveAdditionalCabinetUsers($tenant, $primaryId);
        if ($keepIds !== []) {
            $toDeleteQuery->whereNotIn('id', $keepIds);
        }
        $toDeleteQuery->each(static fn (User $user) => $user->delete());
    }

    protected function resolveAdditionalCabinetUsers(Tenant $tenant, ?int $primaryId): Builder
    {
        $query = $this->cabinetUsersQuery($tenant)
            ->with(['tenantSpaces:id'])
            ->orderBy('id');

        if ($primaryId && $primaryId > 0) {
            $query->whereKeyNot($primaryId);
        }

        return $query;
    }

    protected function cabinetUsersQuery(Tenant $tenant): Builder
    {
        return User::query()
            ->where('tenant_id', (int) $tenant->id)
            ->whereHas('roles', function ($query): void {
                $query->whereIn('name', ['merchant', 'merchant-user']);
            });
    }

    protected function ensureCabinetUserRole(User $user, string $preferredRole): void
    {
        /** @var TenantCabinetUserService $cabinetUsers */
        $cabinetUsers = app(TenantCabinetUserService::class);
        $cabinetUsers->ensureCabinetRole($user, $preferredRole);
    }

    protected function emptyToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
