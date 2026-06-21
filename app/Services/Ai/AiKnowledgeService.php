<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiKnowledgeService
{
    /**
     * @param array<string, mixed> $value
     */
    public function rememberResponsibility(
        int $marketId,
        string $topic,
        User $responsible,
        ?User $sourceUser = null,
        int $confidence = 70,
    ): void
    {
        if (! $this->available() || $marketId <= 0) {
            return;
        }

        $key = 'responsibility:'.Str::slug($topic).':user:'.(int) $responsible->id;

        AiKnowledgeEntry::query()->updateOrCreate([
            'market_id' => $marketId,
            'dictionary' => 'responsibilities',
            'key' => $key,
        ], [
            'label' => "{$topic}: ".($responsible->name ?: 'сотрудник'),
            'value' => [
                'topic' => $topic,
                'responsible_user_id' => (int) $responsible->id,
                'responsible_name' => (string) $responsible->name,
            ],
            'confidence' => max(1, min($confidence, 100)),
            'source_user_id' => $sourceUser?->id,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function responsibilitiesForMarket(int $marketId, int $limit = 10): array
    {
        if (! $this->available() || $marketId <= 0) {
            return [];
        }

        return AiKnowledgeEntry::query()
            ->where('market_id', $marketId)
            ->where('dictionary', 'responsibilities')
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(static fn (AiKnowledgeEntry $entry): array => [
                'label' => (string) $entry->label,
                'value' => (array) ($entry->value ?? []),
                'confidence' => (int) $entry->confidence,
            ])
            ->all();
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('ai_knowledge_entries');
        } catch (\Throwable) {
            return false;
        }
    }
}
