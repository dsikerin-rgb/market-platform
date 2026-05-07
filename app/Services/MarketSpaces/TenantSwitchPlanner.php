<?php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

use App\Domain\Operations\OperationType;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class TenantSwitchPlanner
{
    public function plan(
        MarketSpace $space,
        Tenant $targetTenant,
        CarbonInterface|string $effectiveAt,
        ?string $reason = null,
        ?int $createdBy = null,
        bool $closeReviewOnEffectiveAt = false,
    ): Operation {
        $space = $space->fresh(['tenant', 'spaceGroupParent.tenant']);

        if (! $space instanceof MarketSpace || ! $space->exists) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Торговое место не найдено.',
            ]);
        }

        $this->assertTargetTenant($space, $targetTenant);

        $effectiveTenantId = $space->effectiveTenantId();
        if ($effectiveTenantId !== null && $effectiveTenantId === (int) $targetTenant->getKey()) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'У этого места уже этот арендатор в фактическом состоянии.',
            ]);
        }

        $this->assertNoFutureTenantSwitch($space);

        $effectiveAtCarbon = $effectiveAt instanceof CarbonInterface
            ? Carbon::instance($effectiveAt)
            : Carbon::parse((string) $effectiveAt);

        $isChild = (string) ($space->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD
            && filled($space->space_group_parent_id);

        $payload = [
            'market_space_id' => (int) $space->getKey(),
            'from_tenant_id' => $effectiveTenantId,
            'to_tenant_id' => (int) $targetTenant->getKey(),
            'reason' => $this->normalizeReason($reason),
            'detach_from_group' => $isChild,
            'from_group_parent_id' => $isChild ? (int) $space->space_group_parent_id : null,
            'from_group_slot' => $isChild ? $this->stringOrNull($space->space_group_slot) : null,
            'from_group_role' => $isChild ? MarketSpace::SPACE_GROUP_ROLE_CHILD : $this->stringOrNull($space->space_group_role),
            'review_close_on_effective_at' => $closeReviewOnEffectiveAt,
        ];

        $comment = $this->buildComment($space, $targetTenant, $isChild, $reason);

        return Operation::query()->create([
            'market_id' => (int) $space->market_id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->getKey(),
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => $effectiveAtCarbon,
            'status' => 'applied',
            'payload' => $payload,
            'comment' => $comment,
            'created_by' => $createdBy,
        ]);
    }

    private function assertTargetTenant(MarketSpace $space, Tenant $targetTenant): void
    {
        if (! $targetTenant->exists) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Выберите арендатора.',
            ]);
        }

        if ((int) $space->market_id !== (int) $targetTenant->market_id) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Нельзя назначить арендатора из другого рынка.',
            ]);
        }

        if (! (bool) $targetTenant->is_active) {
            throw ValidationException::withMessages([
                'target_tenant_id' => 'Нельзя запланировать смену на неактивного арендатора.',
            ]);
        }
    }

    private function assertNoFutureTenantSwitch(MarketSpace $space): void
    {
        $hasFutureOperation = Operation::query()
            ->where('market_id', (int) $space->market_id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', (int) $space->getKey())
            ->where('type', OperationType::TENANT_SWITCH)
            ->whereIn('status', ['draft', 'applied'])
            ->where('effective_at', '>=', now('UTC'))
            ->exists();

        if ($hasFutureOperation) {
            throw ValidationException::withMessages([
                'effective_at' => 'Для этого места уже есть запланированная смена арендатора. Сначала разберите существующую операцию.',
            ]);
        }
    }

    private function buildComment(MarketSpace $space, Tenant $targetTenant, bool $detachFromGroup, ?string $reason): string
    {
        $parts = [
            'Смена арендатора места ' . ($space->number ?: ('#' . $space->getKey())),
            'новый арендатор: ' . $targetTenant->name,
        ];

        if ($detachFromGroup && $space->spaceGroupParent instanceof MarketSpace) {
            $parts[] = 'место будет выведено из группы ' . ($space->spaceGroupParent->number ?: ('#' . $space->spaceGroupParent->getKey()));
        }

        $normalizedReason = $this->normalizeReason($reason);
        if ($normalizedReason !== null) {
            $parts[] = 'причина: ' . $normalizedReason;
        }

        return implode('; ', $parts);
    }

    private function normalizeReason(?string $reason): ?string
    {
        return $this->stringOrNull($reason);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
