<?php
# app/Services/MarketSpaces/SpaceGroupManager.php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

use App\Models\MarketSpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpaceGroupManager
{
    public function __construct(
        private readonly SpaceGroupResolver $resolver,
    ) {
    }

    /**
     * Добавить обычное место в группу.
     *
     * @return array{
     *   child_id: int,
     *   old_parent_id: null,
     *   new_parent_id: int,
     *   slot: string,
     *   renamed_parents: list<array{id:int, old_number:string, new_number:string}>
     * }
     */
    public function addToGroup(MarketSpace $space, MarketSpace $targetParent, string $slot): array
    {
        $slot = $this->normalizeRequiredSlot($slot);
        $this->assertCanAddToGroup($space, $targetParent, $slot);

        return DB::transaction(function () use ($space, $targetParent, $slot): array {
            $targetToken = $this->resolveParentToken($targetParent);

            $space->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
                'space_group_parent_id' => (int) $targetParent->getKey(),
                'space_group_slot' => $slot,
                'space_group_token' => $targetToken,
            ])->save();

            $renamed = $this->syncParentIdentity($targetParent->fresh());
            $renamedParents = $renamed !== null ? [$renamed] : [];

            return [
                'child_id' => (int) $space->getKey(),
                'old_parent_id' => null,
                'new_parent_id' => (int) $targetParent->getKey(),
                'slot' => $slot,
                'renamed_parents' => $renamedParents,
            ];
        });
    }

    /**
     * Убрать child-место из группы.
     *
     * @return array{
     *   child_id: int,
     *   old_parent_id: int,
     *   new_parent_id: null,
     *   renamed_parents: list<array{id:int, old_number:string, new_number:string}>
     * }
     */
    public function removeFromGroup(MarketSpace $child): array
    {
        $this->assertCanRemoveFromGroup($child);

        return DB::transaction(function () use ($child): array {
            $oldParent = $child->spaceGroupParent;
            $oldParentId = $oldParent?->getKey() ? (int) $oldParent->getKey() : null;

            $child->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
                'space_group_parent_id' => null,
                'space_group_slot' => null,
                'space_group_token' => null,
            ])->save();

            $renamedParents = [];

            if ($oldParent instanceof MarketSpace) {
                $renamed = $this->syncParentIdentity($oldParent->fresh());
                if ($renamed !== null) {
                    $renamedParents[] = $renamed;
                }
            }

            return [
                'child_id' => (int) $child->getKey(),
                'old_parent_id' => $oldParentId,
                'new_parent_id' => null,
                'renamed_parents' => $renamedParents,
            ];
        });
    }

    /**
     * @return array{
     *   child_id: int,
     *   old_parent_id: ?int,
     *   new_parent_id: int,
     *   slot: string,
     *   renamed_parents: list<array{id:int, old_number:string, new_number:string}>
     * }
     */
    public function regroupChild(MarketSpace $child, MarketSpace $targetParent, ?string $targetSlot = null): array
    {
        $slot = $this->normalizeRequiredSlot($targetSlot ?? $child->space_group_slot);
        $oldParent = $child->spaceGroupParent;

        $this->assertCanRegroup($child, $targetParent, $slot);

        return DB::transaction(function () use ($child, $targetParent, $slot, $oldParent): array {
            $targetToken = $this->resolveParentToken($targetParent);

            $child->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
                'space_group_parent_id' => (int) $targetParent->getKey(),
                'space_group_slot' => $slot,
                'space_group_token' => $targetToken ?? $child->space_group_token,
            ])->save();

            $renamedParents = [];

            if ($oldParent instanceof MarketSpace) {
                $renamed = $this->syncParentIdentity($oldParent->fresh());
                if ($renamed !== null) {
                    $renamedParents[] = $renamed;
                }
            }

            $targetParentId = (int) $targetParent->getKey();
            if (! $oldParent instanceof MarketSpace || (int) $oldParent->getKey() !== $targetParentId) {
                $renamed = $this->syncParentIdentity($targetParent->fresh());
                if ($renamed !== null) {
                    $renamedParents[] = $renamed;
                }
            }

            return [
                'child_id' => (int) $child->getKey(),
                'old_parent_id' => $oldParent?->getKey() ? (int) $oldParent->getKey() : null,
                'new_parent_id' => $targetParentId,
                'slot' => $slot,
                'renamed_parents' => $renamedParents,
            ];
        });
    }

    /**
     * @return array{id:int, old_number:string, new_number:string}|null
     */
    public function syncParentIdentity(MarketSpace $parent): ?array
    {
        if ($parent->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return null;
        }

        $token = $this->resolveParentToken($parent);
        $slots = $this->collectSortedChildSlots($parent);

        if ($token === null || $slots === []) {
            return null;
        }

        $newNumber = $this->formatParentNumber($token, $slots);
        $oldNumber = trim((string) ($parent->number ?? ''));
        $oldDisplayName = trim((string) ($parent->display_name ?? ''));

        $updates = [];

        if ($oldNumber !== $newNumber) {
            $updates['number'] = $newNumber;
        }

        if ($oldDisplayName === '' || $oldDisplayName === $oldNumber) {
            if ($oldDisplayName !== $newNumber) {
                $updates['display_name'] = $newNumber;
            }
        }

        if (($parent->space_group_token ?? null) !== $token) {
            $updates['space_group_token'] = $token;
        }

        if ($updates === []) {
            return null;
        }

        $parent->forceFill($updates)->save();

        return [
            'id' => (int) $parent->getKey(),
            'old_number' => $oldNumber,
            'new_number' => $newNumber,
        ];
    }

    private function assertCanRegroup(MarketSpace $child, MarketSpace $targetParent, string $slot): void
    {
        if (! (bool) $child->is_active) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Нельзя переносить неактивное место.',
            ]);
        }

        if ($child->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_CHILD) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Переносить в другую группу можно только место с ролью "Место в группе".',
            ]);
        }

        if ((int) ($child->space_group_parent_id ?? 0) === (int) $targetParent->getKey()) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Выберите другую группу. Текущая группа уже указана у этого места.',
            ]);
        }

        if ($targetParent->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Целевая запись должна быть группой мест.',
            ]);
        }

        if ((int) $child->market_id !== (int) $targetParent->market_id) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Нельзя переносить место в группу другого рынка.',
            ]);
        }

        if (! (bool) $targetParent->is_active) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Нельзя переносить место в неактивную группу.',
            ]);
        }

        if ((int) $child->getKey() === (int) $targetParent->getKey()) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Место не может быть собственной группой.',
            ]);
        }

        $duplicateSlotExists = $targetParent->spaceGroupChildren()
            ->whereKeyNot($child->getKey())
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->where('space_group_slot', $slot)
            ->exists();

        if ($duplicateSlotExists) {
            throw ValidationException::withMessages([
                'target_slot' => 'В выбранной группе уже есть место с таким номером внутри группы.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function collectSortedChildSlots(MarketSpace $parent): array
    {
        $slots = $parent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->orderBy('id')
            ->pluck('space_group_slot')
            ->map(fn (mixed $value): ?string => $this->resolver->normalizeGroupSlot($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values()
            ->all();

        usort($slots, static function (string $left, string $right): int {
            if (is_numeric($left) && is_numeric($right)) {
                return (int) $left <=> (int) $right;
            }

            return strnatcasecmp($left, $right);
        });

        return $slots;
    }

    private function resolveParentToken(MarketSpace $parent): ?string
    {
        $token = $this->resolver->normalizeGroupToken($parent->space_group_token);
        if ($token !== null) {
            return $token;
        }

        $fromNumber = $this->resolver->forMarketSpaceNumber($parent->number);
        $token = $this->resolver->normalizeGroupToken($fromNumber['group_token'] ?? null);
        if ($token !== null) {
            return $token;
        }

        if (preg_match('/^(ОС)\s*(\d+)/u', trim((string) $parent->number), $matches) === 1) {
            return $this->resolver->normalizeGroupToken($matches[1] . $matches[2]);
        }

        $childToken = $parent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->whereNotNull('space_group_token')
            ->value('space_group_token');

        return $this->resolver->normalizeGroupToken($childToken);
    }

    /**
     * @param  list<string>  $slots
     */
    private function formatParentNumber(string $token, array $slots): string
    {
        if (preg_match('/^(ОС)(\d+)$/u', $token, $matches) === 1) {
            return $matches[1] . $matches[2] . ' ' . implode(', ', $slots);
        }

        return $token . ' ' . implode(', ', $slots);
    }

    private function normalizeRequiredSlot(mixed $value): string
    {
        $slot = $this->resolver->normalizeGroupSlot($value);

        if ($slot === null) {
            throw ValidationException::withMessages([
                'target_slot' => 'Укажите номер места внутри группы.',
            ]);
        }

        return $slot;
    }

    private function assertCanAddToGroup(MarketSpace $space, MarketSpace $targetParent, string $slot): void
    {
        if (! (bool) $space->is_active) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Нельзя добавить в группу неактивное место.',
            ]);
        }

        if ($space->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Добавить в группу можно только обычное место (без группы).',
            ]);
        }

        if ($targetParent->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Целевая запись должна быть группой мест.',
            ]);
        }

        if ((int) $space->market_id !== (int) $targetParent->market_id) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Нельзя добавить место в группу другого рынка.',
            ]);
        }

        if (! (bool) $targetParent->is_active) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Нельзя добавить место в неактивную группу.',
            ]);
        }

        if ((int) $space->getKey() === (int) $targetParent->getKey()) {
            throw ValidationException::withMessages([
                'target_parent_id' => 'Место не может быть собственной группой.',
            ]);
        }

        $duplicateSlotExists = $targetParent->spaceGroupChildren()
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->where('space_group_slot', $slot)
            ->exists();

        if ($duplicateSlotExists) {
            throw ValidationException::withMessages([
                'target_slot' => 'В выбранной группе уже есть место с таким номером внутри группы.',
            ]);
        }
    }

    private function assertCanRemoveFromGroup(MarketSpace $child): void
    {
        if (! (bool) $child->is_active) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Нельзя убрать из группы неактивное место.',
            ]);
        }

        if ($child->space_group_role !== MarketSpace::SPACE_GROUP_ROLE_CHILD) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Убрать из группы можно только место с ролью "Место в группе".',
            ]);
        }

        if (! filled($child->space_group_parent_id)) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Это место не входит ни в одну группу.',
            ]);
        }
    }
}
