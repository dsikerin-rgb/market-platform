<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * DTO для результата определения сценария ревизии места.
 *
 * @psalm-immutable
 */
class SpaceReviewCase
{
    /**
     * @param  'duplicate_identity'|'generic_manual_conflict'|'tenant_change'|'identity_clarification'|'other'  $caseType
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $caseType,
        public readonly int $caseSeverity,
        public readonly string $recommendedAction,
        public readonly array $availableActions,
        public readonly array $requiresInput,
        public readonly array $blockedActions,
        public readonly string $caseExplanation,
        public readonly array $relatedSpaces,
        public readonly bool $canCloseWithoutChanges,
        public readonly string $closePolicy,
        public readonly array $context = [],
    ) {}

    /**
     * Создать результат для дубля места.
     *
     * @param  array<int, array<string, mixed>>  $relatedSpaces
     */
    public static function duplicateIdentity(array $relatedSpaces = [], bool $hasExplicitCandidate = false): self
    {
        return new self(
            caseType: 'duplicate_identity',
            caseSeverity: 100,
            recommendedAction: 'resolve_duplicate',
            availableActions: ['resolve_duplicate', 'manual_review', 'close_without_changes'],
            requiresInput: [
                'resolve_duplicate' => ['canonical_space_id'],
            ],
            blockedActions: [
                'switch_tenant' => 'Сначала разберите дубль места',
            ],
            caseExplanation: 'Ревизор указал, что место является дублем. Нужно выбрать основное место.',
            relatedSpaces: $relatedSpaces,
            canCloseWithoutChanges: false,
            closePolicy: 'after_duplicate_resolution',
            context: [
                'has_explicit_candidate' => $hasExplicitCandidate,
            ],
        );
    }

    /**
     * Создать результат для общего конфликта, требующего ручной проверки.
     */
    public static function genericManualConflict(): self
    {
        return new self(
            caseType: 'generic_manual_conflict',
            caseSeverity: 50,
            recommendedAction: 'manual_review',
            availableActions: ['manual_review', 'close_without_changes'],
            requiresInput: [],
            blockedActions: [],
            caseExplanation: 'Требуется ручная проверка контекста на месте.',
            relatedSpaces: [],
            canCloseWithoutChanges: true,
            closePolicy: 'standard',
        );
    }

    /**
     * Создать результат для смены арендатора.
     */
    public static function tenantChange(): self
    {
        return new self(
            caseType: 'tenant_change',
            caseSeverity: 60,
            recommendedAction: 'switch_tenant',
            availableActions: ['switch_tenant', 'manual_review'],
            requiresInput: [
                'switch_tenant' => ['target_tenant_id'],
            ],
            blockedActions: [],
            caseExplanation: 'На месте другой арендатор. Нужно сменить привязку.',
            relatedSpaces: [],
            canCloseWithoutChanges: false,
            closePolicy: 'standard',
        );
    }

    /**
     * Создать результат для уточнения идентификации места.
     */
    public static function identityClarification(): self
    {
        return new self(
            caseType: 'identity_clarification',
            caseSeverity: 40,
            recommendedAction: 'fix_space_identity',
            availableActions: ['fix_space_identity', 'manual_review'],
            requiresInput: [
                'fix_space_identity' => ['space_number', 'space_name'],
            ],
            blockedActions: [],
            caseExplanation: 'Требуется уточнить номер или название места.',
            relatedSpaces: [],
            canCloseWithoutChanges: false,
            closePolicy: 'standard',
        );
    }

    /**
     * Конвертировать в массив для сериализации.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'case_type' => $this->caseType,
            'case_severity' => $this->caseSeverity,
            'recommended_action' => $this->recommendedAction,
            'available_actions' => $this->availableActions,
            'requires_input' => $this->requiresInput,
            'blocked_actions' => $this->blockedActions,
            'case_explanation' => $this->caseExplanation,
            'related_spaces' => $this->relatedSpaces,
            'can_close_without_changes' => $this->canCloseWithoutChanges,
            'close_policy' => $this->closePolicy,
        ];
    }
}
