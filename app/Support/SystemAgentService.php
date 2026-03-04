<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Str;

class SystemAgentService
{
    public const EMAIL_DOMAIN = 'internal.market-platform.local';

    public function emailForMarket(int $marketId): string
    {
        return sprintf('system+market%d@%s', $marketId, self::EMAIL_DOMAIN);
    }

    public function findForMarket(int $marketId): ?User
    {
        return User::query()
            ->where('email', $this->emailForMarket($marketId))
            ->first();
    }

    /**
     * @return array{status:string,user:?User,message:string}
     */
    public function ensureForMarket(int $marketId, bool $persist = true): array
    {
        $market = Market::query()->select(['id'])->find($marketId);
        if (! $market) {
            return [
                'status' => 'missing_market',
                'user' => null,
                'message' => "Market #{$marketId} not found.",
            ];
        }

        $email = $this->emailForMarket($marketId);

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            if ((int) $existing->market_id !== $marketId) {
                return [
                    'status' => 'conflict',
                    'user' => $existing,
                    'message' => "Email {$email} already exists with another market_id={$existing->market_id}.",
                ];
            }

            return [
                'status' => 'exists',
                'user' => $existing,
                'message' => "System Agent already exists for market #{$marketId}.",
            ];
        }

        if (! $persist) {
            return [
                'status' => 'would_create',
                'user' => null,
                'message' => "System Agent would be created for market #{$marketId}.",
            ];
        }

        $user = new User();
        $user->name = 'System Agent';
        $user->email = $email;
        $user->password = Str::random(64);
        $user->market_id = $marketId;
        $user->notification_preferences = [
            'self_manage' => false,
            'channels' => [],
            'topics' => [],
        ];
        $user->save();

        return [
            'status' => 'created',
            'user' => $user,
            'message' => "System Agent created for market #{$marketId}.",
        ];
    }
}

