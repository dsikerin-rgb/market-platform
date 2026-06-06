<?php

// app/Domain/Operations/SpaceReviewStateMachine.php

declare(strict_types=1);

namespace App\Domain\Operations;

final class SpaceReviewStateMachine
{
    /**
     * @return list<string>
     */
    public static function attentionReviewStatuses(): array
    {
        return [
            'changed_tenant',
            'conflict',
            'not_found',
        ];
    }

    public static function isAttentionReviewStatus(?string $status): bool
    {
        return in_array(trim((string) $status), self::attentionReviewStatuses(), true);
    }

    public static function defaultOperationStatus(string $decision): string
    {
        return in_array($decision, SpaceReviewDecision::observedValues(), true) ? 'observed' : 'applied';
    }

    public static function reviewStatusForDecision(string $decision): string
    {
        return match ($decision) {
            'matched' => 'matched',
            SpaceReviewDecision::MARK_SPACE_FREE => 'matched',
            SpaceReviewDecision::MARK_SPACE_SERVICE => 'matched',
            SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION => 'conflict',
            SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION => 'changed',
            SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL => 'changed',
            SpaceReviewDecision::HISTORICAL_COMPOSED_SPACE_REVIEWED => 'matched',
            SpaceReviewDecision::OCCUPANCY_CONFLICT => 'conflict',
            SpaceReviewDecision::TENANT_CHANGED_ON_SITE => 'changed_tenant',
            SpaceReviewDecision::SHAPE_NOT_FOUND => 'not_found',
            SpaceReviewDecision::CONFIRM_UNCONFIRMED_FINANCIAL_LINK => 'matched',
            SpaceReviewDecision::REJECT_UNCONFIRMED_FINANCIAL_LINK => 'unconfirmed_link_rejected',
            SpaceReviewDecision::REOPEN_UNCONFIRMED_FINANCIAL_LINK => 'unconfirmed_link',
            default => 'changed',
        };
    }

    public static function isActiveIdentityClarification(
        ?string $latestReviewDecision,
        ?string $currentReviewStatus
    ): bool {
        return trim((string) $latestReviewDecision) === SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION
            && trim((string) $currentReviewStatus) === 'conflict';
    }

    public static function shouldSkipRepeatedIdentityClarification(
        ?string $latestReviewDecision,
        ?string $currentReviewStatus
    ): bool {
        return self::isActiveIdentityClarification($latestReviewDecision, $currentReviewStatus);
    }

    public static function blocksTenantSwitch(
        ?string $latestReviewDecision,
        ?string $currentReviewStatus
    ): bool {
        return self::isActiveIdentityClarification($latestReviewDecision, $currentReviewStatus);
    }

    public static function isFinancialOnlyConflict(
        bool $hasOperationDecision,
        bool $hasFinancialSignal,
        ?string $currentReviewStatus
    ): bool {
        return ! $hasOperationDecision
            && $hasFinancialSignal
            && ! self::isAttentionReviewStatus($currentReviewStatus);
    }
}
