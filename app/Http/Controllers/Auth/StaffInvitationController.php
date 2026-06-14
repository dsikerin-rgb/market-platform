<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class StaffInvitationController extends Controller
{
    public function show(Request $request, StaffInvitation $invitation, string $token): View|RedirectResponse
    {
        $error = $this->invitationError($invitation, $token);
        if ($error !== null) {
            return view('auth.staff-invitation-invalid', ['message' => $error]);
        }

        $existingUser = $this->existingUser($invitation);

        return view('auth.staff-invitation-accept', [
            'invitation' => $invitation->loadMissing('market'),
            'existingUser' => $existingUser,
        ]);
    }

    public function accept(Request $request, StaffInvitation $invitation, string $token): RedirectResponse|View
    {
        $error = $this->invitationError($invitation, $token);
        if ($error !== null) {
            return view('auth.staff-invitation-invalid', ['message' => $error]);
        }

        $existingUser = $this->existingUser($invitation);
        if ($existingUser instanceof User) {
            if ($existingUser->market_id !== null && (int) $existingUser->market_id !== (int) $invitation->market_id) {
                return view('auth.staff-invitation-invalid', [
                    'message' => 'Этот email уже привязан к другому рынку. Обратитесь к администратору.',
                ]);
            }

            $user = $existingUser;
            $user->forceFill([
                'market_id' => (int) $invitation->market_id,
                'tenant_id' => null,
            ])->save();
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);

            $user = User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => (string) $invitation->email,
                'password' => (string) $validated['password'],
                'market_id' => (int) $invitation->market_id,
                'tenant_id' => null,
            ]);
        }

        $this->syncInvitationRoles($user, (array) $invitation->roles);

        $invitation->forceFill([
            'accepted_at' => now(),
        ])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to('/admin');
    }

    private function invitationError(StaffInvitation $invitation, string $token): ?string
    {
        if ($invitation->accepted_at !== null) {
            return 'Это приглашение уже принято.';
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            return 'Срок действия приглашения истёк.';
        }

        $expectedHash = trim((string) $invitation->token_hash);
        $actualHash = hash('sha256', $token);

        if ($expectedHash === '' || ! hash_equals($expectedHash, $actualHash)) {
            return 'Ссылка приглашения недействительна.';
        }

        return null;
    }

    private function existingUser(StaffInvitation $invitation): ?User
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $invitation->email)])
            ->first();
    }

    /**
     * @param  list<string>  $roles
     */
    private function syncInvitationRoles(User $user, array $roles): void
    {
        $roleNames = array_values(array_filter(array_map(
            static fn (mixed $role): string => trim((string) $role),
            $roles,
        )));

        if ($roleNames === []) {
            $roleNames = ['staff'];
        }

        $existingRoleNames = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('name')
            ->all();

        if ($existingRoleNames === [] && Role::query()->where('name', 'staff')->exists()) {
            $existingRoleNames = ['staff'];
        }

        if ($existingRoleNames !== []) {
            $user->syncRoles($existingRoleNames);
        }
    }
}
