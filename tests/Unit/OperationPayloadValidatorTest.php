<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Services\Operations\OperationPayloadValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationPayloadValidatorTest extends TestCase
{
    public function test_validates_tenant_switch_payload(): void
    {
        $payload = OperationPayloadValidator::normalize(OperationType::TENANT_SWITCH, [
            'market_space_id' => 10,
            'from_tenant_id' => 1,
            'to_tenant_id' => 2,
            'reason' => 'Перевод',
        ]);

        $this->assertSame(10, $payload['market_space_id']);
        $this->assertSame(1, $payload['from_tenant_id']);
        $this->assertSame(2, $payload['to_tenant_id']);
        $this->assertSame('Перевод', $payload['reason']);
    }

    public function test_validates_accrual_adjustment_payload(): void
    {
        $payload = OperationPayloadValidator::normalize(OperationType::ACCRUAL_ADJUSTMENT, [
            'market_space_id' => 5,
            'amount_delta' => -1200.5,
            'reason' => 'Корректировка',
        ]);

        $this->assertSame(5, $payload['market_space_id']);
        $this->assertSame(-1200.5, $payload['amount_delta']);
        $this->assertSame('Корректировка', $payload['reason']);
    }
    public function test_validates_space_review_identity_fix_payload(): void
    {
        $payload = OperationPayloadValidator::normalize(OperationType::SPACE_REVIEW, [
            'market_space_id' => 15,
            'decision' => SpaceReviewDecision::FIX_SPACE_IDENTITY,
            'number' => 'A-15',
            'display_name' => 'Shop A-15',
            'code' => 'must-be-ignored',
        ]);

        $this->assertSame(15, $payload['market_space_id']);
        $this->assertSame(SpaceReviewDecision::FIX_SPACE_IDENTITY, $payload['decision']);
        $this->assertSame('A-15', $payload['number']);
        $this->assertSame('Shop A-15', $payload['display_name']);
        $this->assertArrayNotHasKey('code', $payload);
    }

    public function test_space_review_requires_reason_for_conflict_decisions(): void
    {
        $this->expectException(ValidationException::class);

        OperationPayloadValidator::normalize(OperationType::SPACE_REVIEW, [
            'market_space_id' => 15,
            'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
        ]);
    }

    public function test_space_review_requires_observed_tenant_name_for_tenant_change(): void
    {
        $this->expectException(ValidationException::class);

        OperationPayloadValidator::normalize(OperationType::SPACE_REVIEW, [
            'market_space_id' => 15,
            'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
            'reason' => 'Observed on site',
        ]);
    }
}
