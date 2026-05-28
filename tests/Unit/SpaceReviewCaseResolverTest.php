<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\SpaceReviewCase;
use App\Domain\Operations\SpaceReviewCaseResolver;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SpaceReviewCaseResolverTest extends TestCase
{
    private SpaceReviewCaseResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SpaceReviewCaseResolver();
    }

    #[Test]
    public function duplicate_reason_without_candidates(): void
    {
        $context = [
            'review_status' => 'conflict',
            'decision' => 'occupancy_conflict',
            'reason' => 'Дубль с существующим',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('duplicate_identity', $case->caseType);
        $this->assertEquals('resolve_duplicate', $case->recommendedAction);
        $this->assertArrayHasKey('resolve_duplicate', $case->requiresInput);
        $this->assertContains('canonical_space_id', $case->requiresInput['resolve_duplicate']);
        $this->assertFalse($case->canCloseWithoutChanges);
        $this->assertEmpty($case->relatedSpaces);
    }

    #[Test]
    public function duplicate_reason_with_typo(): void
    {
        $context = [
            'review_status' => 'conflict',
            'reason' => 'Дубль с сущесвующим',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('duplicate_identity', $case->caseType);
        $this->assertEquals('resolve_duplicate', $case->recommendedAction);
    }

    #[Test]
    public function duplicate_reason_with_candidates(): void
    {
        $candidate = [
            'space_id' => 42,
            'label' => 'ОС8 6',
            'is_explicit_duplicate_scenario' => true,
            'relation_score' => 100,
            'has_map' => true,
            'space_group_role' => 'parent',
        ];

        $context = [
            'review_status' => 'conflict',
            'reason' => 'Дубль с существующим',
            'candidate_spaces' => [$candidate],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('duplicate_identity', $case->caseType);
        $this->assertEquals('resolve_duplicate', $case->recommendedAction);
        $this->assertNotEmpty($case->relatedSpaces);
        $this->assertEquals(42, $case->relatedSpaces[0]['space_id']);
    }

    #[Test]
    public function duplicate_priority_over_tenant_switch(): void
    {
        $context = [
            'review_status' => 'conflict',
            'decision' => 'occupancy_conflict',
            'reason' => 'Дубль с существующим, арендатор другой',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('duplicate_identity', $case->caseType);
        // blockedActions это массив, проверяем ключ
        $this->assertArrayHasKey('switch_tenant', $case->blockedActions, 'blockedActions should have switch_tenant key');
        $this->assertEquals('Сначала разберите дубль места', $case->blockedActions['switch_tenant']);
    }

    #[Test]
    public function generic_conflict_fallback(): void
    {
        $context = [
            'review_status' => 'conflict',
            'reason' => 'Проверить на месте',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('generic_manual_conflict', $case->caseType);
        $this->assertEquals('manual_review', $case->recommendedAction);
        $this->assertTrue($case->canCloseWithoutChanges);
    }

    #[Test]
    public function empty_reason_returns_generic_conflict(): void
    {
        $context = [
            'review_status' => 'conflict',
            'reason' => '',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);

        $this->assertEquals('generic_manual_conflict', $case->caseType);
    }

    #[Test]
    public function toArray_serialization(): void
    {
        $context = [
            'review_status' => 'conflict',
            'reason' => 'Дубль с существующим',
            'candidate_spaces' => [],
        ];

        $case = $this->resolver->resolve($context);
        $array = $case->toArray();

        $this->assertArrayHasKey('case_type', $array);
        $this->assertArrayHasKey('case_severity', $array);
        $this->assertArrayHasKey('recommended_action', $array);
        $this->assertArrayHasKey('available_actions', $array);
        $this->assertArrayHasKey('requires_input', $array);
        $this->assertArrayHasKey('blocked_actions', $array);
        $this->assertArrayHasKey('case_explanation', $array);
        $this->assertArrayHasKey('related_spaces', $array);
        $this->assertArrayHasKey('can_close_without_changes', $array);
        $this->assertArrayHasKey('close_policy', $array);
    }

    #[Test]
    public function duplicate_with_variations_of_duplicate_keyword(): void
    {
        $keywords = [
            'Дублируется',
            'Это дублирующее место',
            'Дубли',
            'Дублирующим',
        ];

        foreach ($keywords as $keyword) {
            $context = [
                'review_status' => 'conflict',
                'reason' => $keyword,
                'candidate_spaces' => [],
            ];

            $case = $this->resolver->resolve($context);
            $this->assertEquals('duplicate_identity', $case->caseType, "Failed for keyword: {$keyword}");
        }
    }
}
