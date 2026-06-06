<?php

// tests/Unit/SpaceReviewStateMachineTest.php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\SpaceReviewDecision;
use App\Domain\Operations\SpaceReviewStateMachine;
use Tests\TestCase;

class SpaceReviewStateMachineTest extends TestCase
{
    public function test_treats_identity_clarification_as_active_only_while_space_is_still_in_conflict(): void
    {
        $this->assertTrue(SpaceReviewStateMachine::isActiveIdentityClarification(
            SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            'conflict',
        ));

        $this->assertFalse(SpaceReviewStateMachine::isActiveIdentityClarification(
            SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            'matched',
        ));

        $this->assertFalse(SpaceReviewStateMachine::isActiveIdentityClarification(
            SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
            'conflict',
        ));
    }

    public function test_recognizes_attention_statuses(): void
    {
        $this->assertTrue(SpaceReviewStateMachine::isAttentionReviewStatus('conflict'));
        $this->assertTrue(SpaceReviewStateMachine::isAttentionReviewStatus('changed_tenant'));
        $this->assertFalse(SpaceReviewStateMachine::isAttentionReviewStatus('matched'));
        $this->assertFalse(SpaceReviewStateMachine::isAttentionReviewStatus(null));
    }

    public function test_detects_financial_only_conflict_when_status_is_not_attention(): void
    {
        $this->assertTrue(SpaceReviewStateMachine::isFinancialOnlyConflict(false, true, 'matched'));
        $this->assertFalse(SpaceReviewStateMachine::isFinancialOnlyConflict(true, true, 'matched'));
        $this->assertFalse(SpaceReviewStateMachine::isFinancialOnlyConflict(false, true, 'conflict'));
    }

    public function test_maps_review_status_for_decision(): void
    {
        $this->assertSame('matched', SpaceReviewStateMachine::reviewStatusForDecision('matched'));
        $this->assertSame('matched', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::MARK_SPACE_FREE));
        $this->assertSame('matched', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::MARK_SPACE_SERVICE));
        $this->assertSame('conflict', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION));
        $this->assertSame('changed', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION));
        $this->assertSame('changed', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL));
        $this->assertSame('conflict', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::OCCUPANCY_CONFLICT));
        $this->assertSame('changed_tenant', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::TENANT_CHANGED_ON_SITE));
        $this->assertSame('not_found', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::SHAPE_NOT_FOUND));
        $this->assertSame('matched', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::CONFIRM_UNCONFIRMED_FINANCIAL_LINK));
        $this->assertSame('unconfirmed_link_rejected', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::REJECT_UNCONFIRMED_FINANCIAL_LINK));
        $this->assertSame('unconfirmed_link', SpaceReviewStateMachine::reviewStatusForDecision(SpaceReviewDecision::REOPEN_UNCONFIRMED_FINANCIAL_LINK));
        $this->assertSame('changed', SpaceReviewStateMachine::reviewStatusForDecision('unknown_decision'));
    }

    public function test_computes_default_operation_status(): void
    {
        foreach (SpaceReviewDecision::observedValues() as $decision) {
            $this->assertSame('observed', SpaceReviewStateMachine::defaultOperationStatus($decision));
        }

        foreach (array_diff(SpaceReviewDecision::values(), SpaceReviewDecision::observedValues()) as $decision) {
            $this->assertSame('applied', SpaceReviewStateMachine::defaultOperationStatus($decision));
        }
    }
}
