<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;

class Login extends BaseLogin
{
    protected string $view = 'filament-panels::pages.auth.login';

    public function getHeading(): string
    {
        return '';
    }

    public function getSubHeading(): ?string
    {
        return null;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $user = Filament::auth()->user();

            if ($user && $this->isTenantCabinetUser($user)) {
                redirect()->route('cabinet.dashboard');

                return;
            }

            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();

        $authProvider = $authGuard->getProvider(); /** @phpstan-ignore-line */
        $credentials = $this->getCredentialsFromFormData($data);

        $user = $authProvider->retrieveByCredentials($credentials);

        if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
            $this->userUndertakingMultiFactorAuthentication = null;

            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        if ($this->isTenantCabinetUser($user)) {
            $authGuard->login($user, (bool) ($data['remember'] ?? false));
            session()->regenerate();

            $this->redirect(route('cabinet.dashboard'), navigate: true);

            return null;
        }

        if (
            filled($this->userUndertakingMultiFactorAuthentication) &&
            (decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier())
        ) {
            $this->multiFactorChallengeForm->validate();
        } else {
            foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
                if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

                if ($multiFactorAuthenticationProvider instanceof \Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook) {
                    $multiFactorAuthenticationProvider->beforeChallenge($user);
                }

                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return null;
            }
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, $data['remember'] ?? false)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    private function isTenantCabinetUser(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        if (! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        $hasMerchantRole = $user->hasAnyRole(['merchant', 'merchant-user']);
        $hasAdminRole = $user->hasAnyRole(['super-admin', 'market-admin', 'market-manager', 'market-operator']);

        return $hasMerchantRole && ! $hasAdminRole;
    }
}

