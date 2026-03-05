<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\OperationType;
use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationPayloadValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Market;

class Operation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'entity_type',
        'entity_id',
        'type',
        'effective_at',
        'effective_tz',
        'effective_month',
        'status',
        'payload',
        'comment',
        'created_by',
        'cancels_operation_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'effective_month' => 'date',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $operation->payload = OperationPayloadValidator::normalize($operation->type, $payload);

            if (! $operation->entity_type && isset($operation->payload['market_space_id'])) {
                $operation->entity_type = 'market_space';
            }

            if (! $operation->entity_id && isset($operation->payload['market_space_id'])) {
                $operation->entity_id = (int) $operation->payload['market_space_id'];
            }

            if (! $operation->created_by) {
                $operation->created_by = Auth::id();
            }

            $market = Market::query()->find($operation->market_id);
            $resolver = app(MarketPeriodResolver::class);
            $tz = $market?->timezone ?: (string) config('app.timezone', 'UTC');
            $operation->effective_tz = $operation->effective_tz ?: $tz;

            $effectiveAt = $operation->effective_at
                ? CarbonImmutable::parse($operation->effective_at)
                : ($market ? $resolver->marketNow($market) : CarbonImmutable::now($tz));

            $operation->effective_at = $effectiveAt->utc();

            if ($market) {
                $operation->effective_month = $resolver->resolveMarketPeriod($market, $effectiveAt->timezone($tz)->toDateString());
            } else {
                $operation->effective_month = CarbonImmutable::parse($effectiveAt->timezone($tz)->toDateString(), $tz)->startOfMonth();
            }
        });

        static::saved(function (self $operation): void {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $spaceId = (int) ($payload['market_space_id'] ?? $operation->entity_id ?? 0);

            if ($spaceId <= 0) {
                return;
            }

            if ($operation->entity_type !== 'market_space') {
                return;
            }

            if (! static::isSpaceSnapshotAffectingType((string) $operation->type)) {
                return;
            }

            // Всегда пересчитываем срез места от applied-операций.
            // Это корректно обрабатывает create/update/status change (draft/canceled/applied).
            static::syncMarketSpaceSnapshotFromOperations((int) $operation->market_id, $spaceId);
        });
    }

    private static function syncMarketSpaceSnapshotFromOperations(int $marketId, int $spaceId): void
    {
        $space = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereKey($spaceId)
            ->first();

        if (! $space) {
            return;
        }

        $nowUtc = CarbonImmutable::now('UTC');

        $latestTenantOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::TENANT_SWITCH)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestTenantOp) {
            $payload = is_array($latestTenantOp->payload) ? $latestTenantOp->payload : [];
            $tenantId = (int) ($payload['to_tenant_id'] ?? 0);
            $space->tenant_id = $tenantId > 0 ? $tenantId : null;
        }

        $latestRentRateOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::RENT_RATE_CHANGE)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestRentRateOp) {
            $payload = is_array($latestRentRateOp->payload) ? $latestRentRateOp->payload : [];

            if (array_key_exists('rent_rate', $payload)) {
                $rentRate = $payload['rent_rate'];
                $space->rent_rate_value = is_numeric($rentRate) ? (float) $rentRate : null;
            }

            if (array_key_exists('unit', $payload) && is_string($payload['unit']) && trim($payload['unit']) !== '') {
                $space->rent_rate_unit = trim($payload['unit']);
            }
        }

        $latestAttrsOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::SPACE_ATTRS_CHANGE)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestAttrsOp) {
            $payload = is_array($latestAttrsOp->payload) ? $latestAttrsOp->payload : [];

            if (array_key_exists('area_sqm', $payload)) {
                $space->area_sqm = is_numeric($payload['area_sqm']) ? (float) $payload['area_sqm'] : null;
            }

            if (array_key_exists('activity_type', $payload)) {
                $space->activity_type = is_string($payload['activity_type']) && trim($payload['activity_type']) !== ''
                    ? trim($payload['activity_type'])
                    : null;
            }

            if (array_key_exists('location_id', $payload)) {
                $space->location_id = is_numeric($payload['location_id']) ? (int) $payload['location_id'] : null;
            }

            if (array_key_exists('type', $payload)) {
                $space->type = is_string($payload['type']) && trim($payload['type']) !== ''
                    ? trim($payload['type'])
                    : null;
            }

            if (array_key_exists('is_active', $payload)) {
                if ($payload['is_active'] !== null) {
                    $space->is_active = (bool) $payload['is_active'];
                }
            }
        }

        if ($space->isDirty()) {
            $space->save();
        }
    }

    public static function rebuildMarketSpaceSnapshot(int $marketId, int $spaceId): void
    {
        static::syncMarketSpaceSnapshotFromOperations($marketId, $spaceId);
    }

    private static function isSpaceSnapshotAffectingType(string $type): bool
    {
        return in_array($type, [
            OperationType::TENANT_SWITCH,
            OperationType::RENT_RATE_CHANGE,
            OperationType::SPACE_ATTRS_CHANGE,
        ], true);
    }
}
