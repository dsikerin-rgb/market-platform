<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

final class MarketContext
{
    private const DEFAULT_SESSION_KEYS = [
        'dashboard_market_id',
        'filament.{panel}.selected_market_id',
        'filament_{panel}_market_id',
        'filament.admin.selected_market_id',
        'selected_market_id',
    ];

    /**
     * @var list<int>
     */
    private array $marketStack = [];

    public function scopeEnabled(): bool
    {
        return (bool) config('market_context.scope_enabled', false);
    }

    public function writeGuardsEnabled(): bool
    {
        return (bool) config('market_context.write_guards_enabled', false);
    }

    public function strictMissingContext(): bool
    {
        return (bool) config('market_context.strict_missing_context', false);
    }

    public function shadowMode(): bool
    {
        return (bool) config('market_context.shadow_mode', true);
    }

    public function currentMarketId(?User $user = null): ?int
    {
        $override = $this->overrideMarketId();

        if ($override !== null) {
            return $override;
        }

        $user ??= $this->authenticatedUser();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $this->selectedMarketIdFromSession()
                ?? $this->fallbackMarketIdForSuperAdmin();
        }

        $marketId = (int) ($user->market_id ?? 0);

        return $marketId > 0 ? $marketId : null;
    }

    public function currentMarket(?User $user = null): ?Market
    {
        $marketId = $this->currentMarketId($user);

        return $marketId !== null
            ? Market::query()->find($marketId)
            : null;
    }

    public function requireMarketId(?User $user = null): int
    {
        $marketId = $this->currentMarketId($user);

        if ($marketId === null) {
            throw new RuntimeException('Market context is not available.');
        }

        return $marketId;
    }

    /**
     * Temporarily set market context for non-request flows such as jobs,
     * commands, integrations, or tests.
     *
     * @template TValue
     *
     * @param Closure(): TValue $callback
     * @return TValue
     */
    public function withMarket(Market|int $market, Closure $callback): mixed
    {
        $this->marketStack[] = $market instanceof Market
            ? (int) $market->getKey()
            : (int) $market;

        try {
            return $callback();
        } finally {
            array_pop($this->marketStack);
        }
    }

    public function selectedMarketIdFromSession(?string $panelId = null): ?int
    {
        foreach ($this->sessionKeys($panelId) as $key) {
            $marketId = $this->normalizeMarketId($this->sessionValue($key));

            if ($marketId !== null) {
                return $marketId;
            }
        }

        return null;
    }

    public function panelId(): string
    {
        try {
            $panelId = Filament::getCurrentPanel()?->getId();
        } catch (Throwable) {
            $panelId = null;
        }

        $panelId = trim((string) $panelId);

        return $panelId !== '' ? $panelId : 'admin';
    }

    private function authenticatedUser(): ?User
    {
        try {
            $user = Filament::auth()->user();

            if ($user instanceof User) {
                return $user;
            }
        } catch (Throwable) {
            //
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function overrideMarketId(): ?int
    {
        if ($this->marketStack === []) {
            return null;
        }

        $marketId = (int) end($this->marketStack);

        return $marketId > 0 ? $marketId : null;
    }

    /**
     * @return list<string>
     */
    private function sessionKeys(?string $panelId = null): array
    {
        $panelId = trim((string) ($panelId ?? $this->panelId()));
        $panelId = $panelId !== '' ? $panelId : 'admin';
        $keys = (array) config('market_context.session_keys', self::DEFAULT_SESSION_KEYS);

        if ($keys === []) {
            $keys = self::DEFAULT_SESSION_KEYS;
        }

        return collect($keys)
            ->map(static fn (mixed $key): string => str_replace('{panel}', $panelId, (string) $key))
            ->filter(static fn (string $key): bool => trim($key) !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function sessionValue(string $key): mixed
    {
        try {
            if (app()->bound('session.store')) {
                return app('session.store')->get($key);
            }
        } catch (Throwable) {
            //
        }

        try {
            if (app()->bound('session')) {
                return app('session')->get($key);
            }
        } catch (Throwable) {
            //
        }

        try {
            return session($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeMarketId(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $marketId = (int) $value;

        return $marketId > 0 ? $marketId : null;
    }

    private function fallbackMarketIdForSuperAdmin(): ?int
    {
        $mode = (string) config('market_context.super_admin_fallback', 'none');

        return match ($mode) {
            'first_by_id' => $this->firstMarketId('id'),
            'first_by_name' => $this->firstMarketId('name'),
            default => null,
        };
    }

    private function firstMarketId(string $orderBy): ?int
    {
        $marketId = Market::query()
            ->orderBy($orderBy)
            ->value('id');

        return $marketId ? (int) $marketId : null;
    }
}
