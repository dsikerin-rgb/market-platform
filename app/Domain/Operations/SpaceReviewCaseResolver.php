<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Resolver для определения сценария ревизии пространства.
 *
 * Анализирует reason, decision, candidate_spaces и другие данные
 * из карточки ревизии и возвращает рекомендуемый сценарий и действия.
 */
class SpaceReviewCaseResolver
{
    /**
     * Определить сценарий для карточки ревизии.
     *
     * @param  array{
     *   review_status?: string,
     *   decision?: string,
     *   reason?: string,
     *   candidate_spaces?: array<int, array<string, mixed>>,
     *   diagnostics?: array<string, mixed>,
     * }  $context
     */
    public function resolve(array $context): SpaceReviewCase
    {
        $reason = (string) ($context['reason'] ?? '');
        $decision = (string) ($context['decision'] ?? '');
        $reviewStatus = (string) ($context['review_status'] ?? '');
        $candidateSpaces = $context['candidate_spaces'] ?? [];
        $diagnostics = $context['diagnostics'] ?? [];

        // 1. Проверка на duplicate_identity
        if ($this->isDuplicateIdentity($reason, $candidateSpaces, $diagnostics)) {
            $relatedSpaces = $this->extractRelatedSpaces($candidateSpaces);
            $hasExplicitCandidate = $this->hasExplicitCandidate($candidateSpaces);

            return SpaceReviewCase::duplicateIdentity($relatedSpaces, $hasExplicitCandidate);
        }

        // 2. Проверка на tenant_change
        if ($this->isTenantChange($reason, $decision, $reviewStatus)) {
            return SpaceReviewCase::tenantChange();
        }

        // 3. Проверка на identity_clarification
        if ($this->isIdentityClarification($decision, $reviewStatus)) {
            return SpaceReviewCase::identityClarification();
        }

        // 4. Fallback: generic_manual_conflict
        return SpaceReviewCase::genericManualConflict();
    }

    /**
     * Проверить, является ли кейс дублем места.
     */
    private function isDuplicateIdentity(
        string $reason,
        array $candidateSpaces,
        array $diagnostics
    ): bool {
        // Проверка по reason/comment
        if ($this->reasonIndicatesDuplicate($reason)) {
            return true;
        }

        // Проверка по явным duplicate candidates
        if ($this->hasExplicitDuplicateCandidate($candidateSpaces)) {
            return true;
        }

        // Проверка по diagnostics.has_stronger_candidate + контекст дубля
        if (($diagnostics['has_stronger_candidate'] ?? false) && $this->contextLooksLikeDuplicate($reason, $candidateSpaces)) {
            return true;
        }

        return false;
    }

    /**
     * Проверить, указывает ли reason на дубль.
     */
    private function reasonIndicatesDuplicate(string $reason): bool
    {
        $normalized = mb_strtolower(trim($reason), 'UTF-8');

        // Паттерны для "дубль" с возможными опечатками
        $duplicatePatterns = [
            '/дубль/iu',
            '/дублируется/iu',
            '/дублирующее/iu',
            '/дублирующим/iu',
            '/дубли/iu',
        ];

        foreach ($duplicatePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить наличие явного duplicate candidate.
     */
    private function hasExplicitDuplicateCandidate(array $candidateSpaces): bool
    {
        foreach ($candidateSpaces as $candidate) {
            // Проверяем явные признаки дубля
            if (($candidate['is_explicit_duplicate_scenario'] ?? false)) {
                return true;
            }

            // Проверяем match_source
            $matchSources = (array) ($candidate['match_sources'] ?? [$candidate['match_source'] ?? '']);
            if (in_array('duplicate', $matchSources, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить, похож ли контекст на дубль.
     */
    private function contextLooksLikeDuplicate(string $reason, array $candidateSpaces): bool
    {
        // Если reason пустой, но есть stronger candidate с признаками дубля
        if ($reason === '' && $candidateSpaces !== []) {
            foreach ($candidateSpaces as $candidate) {
                $candidateIsGroup = in_array((string) ($candidate['space_group_role'] ?? ''), ['parent'], true);
                $candidateHasShape = ($candidate['has_map'] ?? false);

                if ($candidateIsGroup && $candidateHasShape) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверить наличие явного кандидата.
     */
    private function hasExplicitCandidate(array $candidateSpaces): bool
    {
        foreach ($candidateSpaces as $candidate) {
            if (($candidate['is_explicit_duplicate_scenario'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Извлечь связанные места для дубля.
     *
     * @param  array<int, array<string, mixed>>  $candidateSpaces
     * @return array<int, array<string, mixed>>
     */
    private function extractRelatedSpaces(array $candidateSpaces): array
    {
        return array_values(array_filter($candidateSpaces, function (array $candidate): bool {
            return ($candidate['is_explicit_duplicate_scenario'] ?? false)
                || ($candidate['match_source'] ?? '') === 'duplicate'
                || ($candidate['relation_score'] ?? 0) > 50;
        }));
    }

    /**
     * Проверить, является ли кейс сменой арендатора.
     */
    private function isTenantChange(string $reason, string $decision, string $reviewStatus): bool
    {
        $normalized = mb_strtolower(trim($reason), 'UTF-8');

        // Проверка по decision
        if ($decision === 'tenant_changed_on_site' || $reviewStatus === 'changed_tenant') {
            return true;
        }

        // Если reason содержит "дубль", приоритет у duplicate, не tenant_change
        if (preg_match('/дубль|дублируется|дублирующее/iu', $normalized) === 1) {
            return false;
        }

        // Проверка по reason
        $tenantChangePatterns = [
            '/другой арендатор/iu',
            '/сменил(ся|ась|ось)/iu',
            '/новый арендатор/iu',
            '/замен(а|ил|ить)/iu',
        ];

        foreach ($tenantChangePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить, требует ли кейс уточнения идентификации.
     */
    private function isIdentityClarification(string $decision, string $reviewStatus): bool
    {
        return $decision === 'space_identity_needs_clarification'
            || $reviewStatus === 'identity_needs_clarification';
    }
}
