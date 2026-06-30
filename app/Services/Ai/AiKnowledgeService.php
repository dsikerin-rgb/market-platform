<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiKnowledgeService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_STALE = 'stale';

    /**
     * @return list<string>
     */
    public static function visibleStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_APPROVED];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{ok:bool,message:string,entry?:AiKnowledgeEntry}
     */
    public function remember(
        int $marketId,
        string $dictionary,
        string $label,
        string $fact,
        ?User $sourceUser = null,
        ?string $subject = null,
        ?string $key = null,
        int $confidence = 70,
        array $value = [],
    ): array {
        if (! $this->available() || $marketId <= 0) {
            return ['ok' => false, 'message' => 'Справочник агента пока недоступен.'];
        }

        $dictionary = $this->normalizeDictionary($dictionary);
        $label = $this->clean($label, 180);
        $fact = $this->clean($fact, 1200);
        $subject = $this->clean((string) $subject, 180);

        if ($label === '' || $fact === '') {
            return ['ok' => false, 'message' => 'Для справочника нужны понятное название и факт.'];
        }

        $authority = $sourceUser instanceof User
            ? $this->sourceAuthority($sourceUser, null, $dictionary, $fact)
            : ['score' => 50, 'label' => 'неизвестный источник', 'reason' => 'Источник не указан.'];
        $confidence = max(1, min($confidence, 100));
        $confidence = min($confidence, (int) $authority['score']);
        $key = $this->normalizeKey($key ?: $dictionary.':'.$label.':'.$subject);

        $nextValue = [
            ...$value,
            'subject' => $subject !== '' ? $subject : null,
            'fact' => $fact,
            'source_authority' => $authority,
        ];

        $entry = AiKnowledgeEntry::query()->firstOrNew([
            'market_id' => $marketId,
            'dictionary' => $dictionary,
            'key' => $key,
        ]);

        $status = $this->nextStatusForWrite($entry, [
            'label' => $label,
            'value' => $nextValue,
            'confidence' => $confidence,
        ]);

        $entry->fill([
            'label' => $label,
            'value' => $nextValue,
            'confidence' => $confidence,
            'source_user_id' => $sourceUser?->id,
            'last_seen_at' => now(),
        ]);
        $this->applyWriteStatus($entry, $status);
        $entry->save();

        return [
            'ok' => true,
            'message' => 'Факт сохранён в справочник агента.',
            'entry' => $entry,
        ];
    }

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

        $authority = $sourceUser instanceof User
            ? $this->sourceAuthority($sourceUser, $responsible, 'responsibilities', $topic)
            : ['score' => max(1, min($confidence, 100)), 'label' => 'неизвестный источник', 'reason' => 'Источник не указан.'];
        $confidence = min(max(1, min($confidence, 100)), (int) $authority['score']);
        $key = 'responsibility:'.$this->normalizeKey($topic).':user:'.(int) $responsible->id;

        $nextValue = [
            'topic' => $topic,
            'responsible_user_id' => (int) $responsible->id,
            'responsible_name' => (string) $responsible->name,
            'source_authority' => $authority,
        ];

        $entry = AiKnowledgeEntry::query()->firstOrNew([
            'market_id' => $marketId,
            'dictionary' => 'responsibilities',
            'key' => $key,
        ]);

        $label = "{$topic}: ".($responsible->name ?: 'сотрудник');
        $status = $this->nextStatusForWrite($entry, [
            'label' => $label,
            'value' => $nextValue,
            'confidence' => $confidence,
        ]);

        $entry->fill([
            'label' => $label,
            'value' => $nextValue,
            'confidence' => $confidence,
            'source_user_id' => $sourceUser?->id,
            'last_seen_at' => now(),
        ]);
        $this->applyWriteStatus($entry, $status);
        $entry->save();
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
            ->when($this->hasStatusColumn(), fn ($query) => $query->whereIn('status', self::visibleStatuses()))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(static fn (AiKnowledgeEntry $entry): array => [
                'label' => (string) $entry->label,
                'value' => (array) ($entry->value ?? []),
                'confidence' => (int) $entry->confidence,
                'confidence_label' => self::confidenceLabel((int) $entry->confidence),
                'status' => (string) ($entry->status ?? self::STATUS_DRAFT),
                'status_label' => self::statusLabel((string) ($entry->status ?? self::STATUS_DRAFT)),
            ])
            ->all();
    }

    /**
     * @param list<string> $excludeDictionaries
     * @return list<array<string, mixed>>
     */
    public function entriesForMarket(
        int $marketId,
        int $limit = 20,
        ?string $dictionary = null,
        array $excludeDictionaries = [],
    ): array
    {
        if (! $this->available() || $marketId <= 0) {
            return [];
        }

        $excludeDictionaries = array_values(array_filter(array_map(
            fn (string $value): string => $this->normalizeDictionary($value),
            $excludeDictionaries,
        )));

        return AiKnowledgeEntry::query()
            ->with('sourceUser:id,name,email')
            ->where('market_id', $marketId)
            ->when($dictionary !== null, fn ($query) => $query->where('dictionary', $this->normalizeDictionary($dictionary)))
            ->when($excludeDictionaries !== [], fn ($query) => $query->whereNotIn('dictionary', $excludeDictionaries))
            ->when($this->hasStatusColumn(), fn ($query) => $query->whereIn('status', self::visibleStatuses()))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(function (AiKnowledgeEntry $entry): array {
                $value = (array) ($entry->value ?? []);

                return [
                    'dictionary' => (string) $entry->dictionary,
                    'key' => (string) $entry->key,
                    'label' => (string) $entry->label,
                    'subject' => (string) ($value['subject'] ?? ''),
                    'fact' => (string) ($value['fact'] ?? ''),
                    'value' => $value,
                    'confidence' => (int) $entry->confidence,
                    'confidence_label' => self::confidenceLabel((int) $entry->confidence),
                    'status' => (string) ($entry->status ?? self::STATUS_DRAFT),
                    'status_label' => self::statusLabel((string) ($entry->status ?? self::STATUS_DRAFT)),
                    'source' => $entry->sourceUser?->name ?: 'Источник не указан',
                    'source_authority' => (array) ($value['source_authority'] ?? []),
                    'last_seen_at' => $entry->last_seen_at?->toDateTimeString(),
                ];
            })
            ->all();
    }

    /**
     * @return array{score:int,label:string,reason:string,roles:list<string>}
     */
    public function sourceAuthority(User $sourceUser, ?User $subjectUser = null, string $dictionary = 'general', string $claim = ''): array
    {
        $roles = method_exists($sourceUser, 'getRoleNames')
            ? $sourceUser->getRoleNames()->map(static fn ($role): string => (string) $role)->values()->all()
            : [];
        $dictionary = $this->normalizeDictionary($dictionary);
        $claim = mb_strtolower($claim);

        $score = 55;
        $reason = 'Обычный сотрудник: факт можно использовать как подсказку, но не как окончательное правило.';

        if (method_exists($sourceUser, 'isSuperAdmin') && $sourceUser->isSuperAdmin()) {
            $score = 95;
            $reason = 'Super-admin считается высокодоверенным источником.';
        } elseif (method_exists($sourceUser, 'isMarketAdmin') && $sourceUser->isMarketAdmin()) {
            $score = 85;
            $reason = 'Администратор рынка считается управленческим источником.';
        } elseif ($this->hasAnyRole($roles, ['market-owner', 'market-owner-director'])) {
            $score = 82;
            $reason = 'Владелец или директор рынка считается управленческим источником.';
        } elseif ($this->hasAnyRole($roles, ['market-manager'])) {
            $score = 76;
            $reason = 'Управляющий рынок источник выше среднего.';
        }

        if ($dictionary === 'responsibilities' && $this->hasAnyRole($roles, [
            'market-debt-manager',
            'market-contract-manager',
            'market-space-manager',
            'market-service-admin',
            'market-finance',
            'market-accountant',
        ])) {
            $score = max($score, 78);
            $reason = 'Профильная роль повышает доверие к сведениям об ответственности.';
        }

        if ($subjectUser instanceof User && (int) $sourceUser->id === (int) $subjectUser->id) {
            $score = max($score, 70);
            $score = min($score, 78);
            $reason = 'Человек говорит о собственной зоне работы: это полезно, но требует роли или подтверждения для высокой уверенности.';
        }

        if ($this->looksLikeAuthorityClaim($claim) && ! $this->hasAnyRole($roles, [
            'super-admin',
            'market-admin',
            'demo-market-admin',
            'market-owner',
            'market-owner-director',
        ])) {
            $score = min($score, 45);
            $reason = 'Заявление о власти или руководящей роли не подтверждено системной ролью.';
        }

        return [
            'score' => max(1, min($score, 100)),
            'label' => self::confidenceLabel($score),
            'reason' => $reason,
            'roles' => $roles,
        ];
    }

    public static function confidenceLabel(int $confidence): string
    {
        if ($confidence >= 80) {
            return 'высокое доверие';
        }

        if ($confidence >= 60) {
            return 'среднее доверие';
        }

        return 'нужно подтверждение';
    }

    public static function normalizeStatus(string $status): string
    {
        $status = Str::of($status)->lower()->trim()->toString();

        return in_array($status, [
            self::STATUS_DRAFT,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_STALE,
        ], true) ? $status : self::STATUS_DRAFT;
    }

    public static function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_APPROVED => 'подтверждено',
            self::STATUS_REJECTED => 'отклонено',
            self::STATUS_STALE => 'устарело',
            default => 'черновик',
        };
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('ai_knowledge_entries');
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasStatusColumn(): bool
    {
        try {
            return Schema::hasColumn('ai_knowledge_entries', 'status');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{label:string,value:array<string,mixed>,confidence:int} $next
     */
    private function nextStatusForWrite(AiKnowledgeEntry $entry, array $next): string
    {
        if (! $this->hasStatusColumn()) {
            return self::STATUS_DRAFT;
        }

        if (! $entry->exists) {
            return self::STATUS_DRAFT;
        }

        $currentStatus = self::normalizeStatus((string) ($entry->status ?? self::STATUS_DRAFT));
        if ($currentStatus !== self::STATUS_APPROVED) {
            return $currentStatus;
        }

        $currentValue = [
            'label' => (string) $entry->label,
            'value' => (array) ($entry->value ?? []),
            'confidence' => (int) $entry->confidence,
        ];

        return $currentValue === $next ? self::STATUS_APPROVED : self::STATUS_DRAFT;
    }

    private function applyWriteStatus(AiKnowledgeEntry $entry, string $status): void
    {
        if (! $this->hasStatusColumn()) {
            return;
        }

        $status = self::normalizeStatus($status);
        $entry->status = $status;

        if ($status !== self::STATUS_APPROVED) {
            $entry->reviewed_by_user_id = null;
            $entry->reviewed_at = null;
        }
    }

    private function normalizeDictionary(string $dictionary): string
    {
        $dictionary = Str::of($dictionary)
            ->lower()
            ->replaceMatches('/[^a-z0-9_\-]+/u', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_-')
            ->toString();

        return Str::limit($dictionary !== '' ? $dictionary : 'general', 80, '');
    }

    private function normalizeKey(string $key): string
    {
        $key = Str::slug(Str::limit($key, 220, ''), '-', null);

        return $key !== '' ? $key : 'knowledge-'.hash('xxh3', (string) now()->timestamp);
    }

    private function clean(string $value, int $limit): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $value) ?: ''), $limit, '');
    }

    /**
     * @param list<string> $roles
     * @param list<string> $expected
     */
    private function hasAnyRole(array $roles, array $expected): bool
    {
        return array_values(array_intersect($roles, $expected)) !== [];
    }

    private function looksLikeAuthorityClaim(string $claim): bool
    {
        foreach (['директор', 'руковод', 'главн', 'самый главный', 'владелец', 'учредитель', 'администратор'] as $needle) {
            if (str_contains($claim, $needle)) {
                return true;
            }
        }

        return false;
    }
}
