<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\MarketplaceProduct;
use App\Models\TenantContract;
use App\Models\User;
use App\Services\Cabinet\TenantCabinetUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SpacesController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $allowedSpaceIds = $request->user()->allowedTenantSpaceIds();

        $spaces = MarketSpace::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->whereIn('id', $allowedSpaceIds))
            ->orderBy('number')
            ->get();

        $contract = TenantContract::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->orderByDesc('starts_at')
            ->first();

        $visibleSpaceIds = $spaces->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $tenantUsers = User::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where(function ($query): void {
                $query
                    ->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['merchant', 'merchant-user']))
                    ->orWhereDoesntHave('roles');
            })
            ->with(['tenantSpaces' => fn ($query) => $query->select('market_spaces.id')])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'telegram_chat_id', 'telegram_profile', 'telegram_linked_at']);

        $spaceStaffMap = [];
        foreach ($tenantUsers as $cabinetUser) {
            $userSpaceIds = $cabinetUser->tenantSpaces
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => in_array($id, $visibleSpaceIds, true))
                ->values()
                ->all();

            $effectiveSpaceIds = $userSpaceIds !== [] ? $userSpaceIds : $visibleSpaceIds;
            foreach ($effectiveSpaceIds as $spaceId) {
                $spaceStaffMap[$spaceId][] = [
                    'id' => (int) $cabinetUser->id,
                    'name' => trim((string) ($cabinetUser->name ?? 'Сотрудник')),
                    'email' => trim((string) ($cabinetUser->email ?? '')),
                    'telegram_linked' => filled($cabinetUser->telegram_chat_id),
                    'telegram_username' => trim((string) data_get($cabinetUser->telegram_profile, 'username', '')),
                    'telegram_linked_at' => optional($cabinetUser->telegram_linked_at)?->format('d.m.Y H:i'),
                    'all_spaces' => $userSpaceIds === [],
                ];
            }
        }

        $spaceProductCounts = MarketplaceProduct::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_id', (int) ($tenant->market_id ?? 0))
            ->whereNotNull('market_space_id')
            ->selectRaw('market_space_id, COUNT(*) as aggregate')
            ->groupBy('market_space_id')
            ->pluck('aggregate', 'market_space_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $globalProductsCount = (int) MarketplaceProduct::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_id', (int) ($tenant->market_id ?? 0))
            ->whereNull('market_space_id')
            ->count();

        return view('cabinet.spaces', [
            'tenant' => $tenant,
            'spaces' => $spaces,
            'contract' => $contract,
            'spaceStaffMap' => $spaceStaffMap,
            'spaceProductCounts' => $spaceProductCounts,
            'globalProductsCount' => $globalProductsCount,
            'canManageStaff' => $this->canManageStaff($request->user()),
        ]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $authUser = $request->user();
        $tenant = $authUser?->tenant;

        if (! $authUser || ! $tenant) {
            abort(403);
        }

        if (! $this->canManageStaff($authUser)) {
            return redirect()
                ->route('cabinet.spaces')
                ->with('error', 'Добавлять сотрудников может только основной аккаунт арендатора.');
        }

        $assignableSpaceIds = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_id', (int) $tenant->market_id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($assignableSpaceIds === []) {
            return redirect()
                ->route('cabinet.spaces')
                ->with('error', 'Нельзя добавить сотрудника: у арендатора нет торговых мест.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'space_ids' => ['required', 'array', 'min:1'],
            'space_ids.*' => ['integer'],
        ]);

        $email = Str::lower(trim((string) ($validated['email'] ?? '')));
        $name = trim((string) ($validated['name'] ?? ''));
        $password = (string) ($validated['password'] ?? '');

        if ($email === '' || $name === '') {
            throw ValidationException::withMessages([
                'email' => 'Укажите email.',
                'name' => 'Укажите имя сотрудника.',
            ]);
        }

        $selectedSpaceIds = collect($validated['space_ids'] ?? [])
            ->filter(static fn ($id): bool => is_numeric($id))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => in_array($id, $assignableSpaceIds, true))
            ->unique()
            ->values()
            ->all();

        if ($selectedSpaceIds === []) {
            throw ValidationException::withMessages([
                'space_ids' => 'Выберите минимум одно торговое место.',
            ]);
        }

        $emailExists = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
        if ($emailExists) {
            throw ValidationException::withMessages([
                'email' => 'Этот email уже используется.',
            ]);
        }

        $newUser = new User();
        $newUser->name = $name;
        $newUser->email = $email;
        $newUser->password = Hash::make($password);
        $newUser->tenant_id = (int) $tenant->id;
        $newUser->market_id = (int) $tenant->market_id;
        $newUser->save();

        app(TenantCabinetUserService::class)->ensureCabinetRole($newUser, 'merchant-user');

        if (Schema::hasTable('tenant_user_market_spaces')) {
            $newUser->tenantSpaces()->sync($selectedSpaceIds);
        }

        return redirect()
            ->route('cabinet.spaces')
            ->with('success', 'Сотрудник добавлен и привязан к выбранным торговым местам.');
    }

    private function canManageStaff(User $user): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole('merchant');
    }
}
