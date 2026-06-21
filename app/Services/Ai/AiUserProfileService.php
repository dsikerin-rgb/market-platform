<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUserProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiUserProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function syncFromConversation(User $user, ?AiConversation $conversation, int $marketId): array
    {
        if (! $this->profilesAvailable()) {
            return [];
        }

        $profile = AiUserProfile::query()->firstOrNew(['user_id' => (int) $user->id]);
        $profile->market_id = $marketId > 0 ? $marketId : ((int) ($user->market_id ?? 0) ?: null);

        if ($conversation instanceof AiConversation) {
            $this->learnFromMessages($profile, $conversation, $user);
        }

        $profile->profile_summary = $this->buildSummary($profile, $user);
        $profile->save();

        return $this->compact($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function compactForUser(User $user): array
    {
        if (! $this->profilesAvailable()) {
            return [];
        }

        $profile = AiUserProfile::query()
            ->where('user_id', (int) $user->id)
            ->first();

        return $profile instanceof AiUserProfile ? $this->compact($profile) : [];
    }

    /**
     * @return list<string>
     */
    public function rejectedTopicKeys(User $user): array
    {
        $profile = $this->compactForUser($user);

        return collect((array) ($profile['rejected_topics'] ?? []))
            ->pluck('key')
            ->filter()
            ->map(static fn (mixed $key): string => (string) $key)
            ->unique()
            ->values()
            ->all();
    }

    private function learnFromMessages(AiUserProfile $profile, AiConversation $conversation, User $user): void
    {
        $messages = $conversation->messages()
            ->latest('created_at')
            ->limit(80)
            ->get()
            ->reverse()
            ->values();

        $facts = (array) ($profile->facts ?? []);
        $regularTasks = (array) ($profile->regular_tasks ?? []);
        $rejectedTopics = $this->normalizeRejectedTopics((array) ($profile->rejected_topics ?? []));
        $previousAssistant = null;

        foreach ($messages as $message) {
            if (! $message instanceof AiMessage) {
                continue;
            }

            if ($message->role === AiMessage::ROLE_ASSISTANT) {
                $previousAssistant = $message;

                continue;
            }

            if ($message->role !== AiMessage::ROLE_USER) {
                continue;
            }

            $body = trim((string) $message->body);
            if ($body === '') {
                continue;
            }

            $facts = $this->learnFactsFromText($profile, $body, $facts);
            $regularTasks = $this->learnRegularTasksFromText($body, $regularTasks);
            $this->learnCrossUserResponsibilities($profile, $body, $user);

            if ($this->isTopicRejected($body)) {
                foreach ($this->topicsForRejection($body, $previousAssistant) as $topic) {
                    $rejectedTopics[$topic['key']] = $topic;
                }
            }

            $profile->inferred_from_messages_at = $message->created_at;
        }

        $profile->regular_tasks = array_values(array_slice(array_unique($regularTasks), 0, 12));
        $profile->rejected_topics = array_values($rejectedTopics);
        $profile->facts = $facts;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private function learnFactsFromText(AiUserProfile $profile, string $body, array $facts): array
    {
        $normalized = mb_strtolower($body);

        if (preg_match('/(?:моя должность|я работаю как|я работаю|должность)\s*[:\-—]?\s*(.{3,80})/uiu', $body, $matches)) {
            $profile->job_title = $this->cleanExtract($matches[1]);
        }

        if (preg_match('/(?:мой отдел|отдел)\s*[:\-—]?\s*(.{3,80})/uiu', $body, $matches)) {
            $profile->department = $this->cleanExtract($matches[1]);
        }

        if (preg_match('/(?:я отвечаю за|моя зона ответственности|мой периметр|в моей зоне)\s*[:\-—]?\s*(.{3,180})/uiu', $body, $matches)) {
            $profile->responsibility_scope = $this->cleanExtract($matches[1], 180);
        }

        if (str_contains($normalized, 'не моя компетенция') || str_contains($normalized, 'не относится к моей компетенции')) {
            $facts['has_competency_rejections'] = true;
        }

        return $facts;
    }

    /**
     * @param list<string> $regularTasks
     * @return list<string>
     */
    private function learnRegularTasksFromText(string $body, array $regularTasks): array
    {
        if (! preg_match('/(?:регулярно|каждый день|каждую неделю|мои регулярные задачи)\s*[:\-—]?\s*(.{3,180})/uiu', $body, $matches)) {
            return $regularTasks;
        }

        $task = $this->cleanExtract($matches[1], 180);
        if ($task !== '') {
            $regularTasks[] = $task;
        }

        return $regularTasks;
    }

    private function learnCrossUserResponsibilities(AiUserProfile $sourceProfile, string $body, User $sourceUser): void
    {
        $marketId = (int) ($sourceProfile->market_id ?? 0);
        if ($marketId <= 0) {
            return;
        }

        foreach ($this->responsibilityMentions($body) as $mention) {
            $responsible = $this->findMentionedUser($marketId, $mention['person']);
            if (! $responsible instanceof User) {
                continue;
            }

            $profile = AiUserProfile::query()->firstOrNew(['user_id' => (int) $responsible->id]);
            $profile->market_id = $marketId;
            $profile->responsibility_scope = $this->mergeResponsibility(
                (string) ($profile->responsibility_scope ?? ''),
                $mention['scope'],
            );
            $profile->profile_summary = $this->buildSummary($profile, $responsible);
            $profile->save();

            app(AiKnowledgeService::class)->rememberResponsibility(
                $marketId,
                $mention['scope'],
                $responsible,
                $sourceUser,
                $this->sourceConfidence($sourceUser, $responsible),
            );
        }
    }

    /**
     * @return list<array{scope:string,person:string}>
     */
    private function responsibilityMentions(string $body): array
    {
        $mentions = [];
        $scopePattern = '(долгами|задолженностями|оплатами|обращениями|заявками|задачами|договорами|местами|картой|арендаторами)';
        $personPattern = '([А-ЯЁ][\p{L}\-]+(?:\s+[А-ЯЁ][\p{L}\-]+){0,2})';

        if (preg_match_all("/{$scopePattern}\s+(?:занимается|занимаются|вед[её]т|отвечает)\s+{$personPattern}/uiu", $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $mentions[] = [
                    'scope' => $this->humanScope($match[1]),
                    'person' => trim($match[2]),
                ];
            }
        }

        if (preg_match_all("/{$personPattern}\s+(?:занимается|вед[её]т|отвечает за)\s+{$scopePattern}/uiu", $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $mentions[] = [
                    'scope' => $this->humanScope($match[2]),
                    'person' => trim($match[1]),
                ];
            }
        }

        return $mentions;
    }

    private function findMentionedUser(int $marketId, string $person): ?User
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($person)) ?: []));
        if ($tokens === []) {
            return null;
        }

        return User::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($token).'%']);
                }
            })
            ->orderBy('id')
            ->first();
    }

    private function mergeResponsibility(string $current, string $scope): string
    {
        $current = trim($current);
        if ($current === '') {
            return $scope;
        }

        if (str_contains(mb_strtolower($current), mb_strtolower($scope))) {
            return $current;
        }

        return Str::limit($current.'; '.$scope, 240, '');
    }

    private function humanScope(string $scope): string
    {
        return match (mb_strtolower($scope)) {
            'долгами', 'задолженностями', 'оплатами' => 'долги и оплаты арендаторов',
            'обращениями', 'заявками' => 'обращения арендаторов',
            'задачами' => 'задачи',
            'договорами' => 'договоры арендаторов',
            'местами', 'картой' => 'места и карта рынка',
            'арендаторами' => 'арендаторы',
            default => $scope,
        };
    }

    private function sourceConfidence(User $sourceUser, User $responsible): int
    {
        if ((int) $sourceUser->id === (int) $responsible->id) {
            return 60;
        }

        if (method_exists($sourceUser, 'isSuperAdmin') && $sourceUser->isSuperAdmin()) {
            return 90;
        }

        if (method_exists($sourceUser, 'isMarketAdmin') && $sourceUser->isMarketAdmin()) {
            return 80;
        }

        return 55;
    }

    private function isTopicRejected(string $body): bool
    {
        $text = mb_strtolower($body);

        foreach ([
            'не моя компетенция',
            'не относится к моей компетенции',
            'не относится ко мне',
            'это не ко мне',
            'не моя задача',
            'не предлагай',
            'больше не предлагай',
            'мне это не нужно',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key:string,label:string,rejected_at:string}>
     */
    private function topicsForRejection(string $body, ?AiMessage $previousAssistant): array
    {
        $topics = $this->topicsFromText($body);

        if ($topics === [] && $previousAssistant instanceof AiMessage) {
            $metadata = (array) ($previousAssistant->metadata ?? []);
            $priorityContext = (array) ($metadata['priority_context'] ?? []);
            $topic = (string) ($priorityContext['topic'] ?? '');

            if ($topic !== '') {
                $topics[] = $topic;
            }

            foreach ((array) ($metadata['suggestions'] ?? []) as $suggestion) {
                $topics = [...$topics, ...$this->topicsFromText((string) $suggestion)];
            }
        }

        $topics = array_values(array_unique(array_filter($topics)));

        return array_map(fn (string $topic): array => [
            'key' => $topic,
            'label' => $this->topicLabel($topic),
            'rejected_at' => now()->toDateTimeString(),
        ], $topics);
    }

    /**
     * @return list<string>
     */
    private function topicsFromText(string $text): array
    {
        $text = mb_strtolower($text);
        $topics = [];

        $map = [
            'debts' => ['долг', 'должн', 'задолж', 'оплат'],
            'tickets' => ['обращен', 'заявк', 'диалог', 'сообщен'],
            'tasks' => ['задач', 'напоминан', 'поручен'],
            'contracts' => ['договор', 'контракт'],
            'spaces' => ['мест', 'площад', 'карта'],
        ];

        foreach ($map as $topic => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * @param array<int|string, mixed> $topics
     * @return array<string, array{key:string,label:string,rejected_at:string}>
     */
    private function normalizeRejectedTopics(array $topics): array
    {
        $result = [];

        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $key = (string) ($topic['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $result[$key] = [
                'key' => $key,
                'label' => (string) ($topic['label'] ?? $this->topicLabel($key)),
                'rejected_at' => (string) ($topic['rejected_at'] ?? now()->toDateTimeString()),
            ];
        }

        return $result;
    }

    private function buildSummary(AiUserProfile $profile, User $user): string
    {
        $parts = [];

        $systemRoles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->implode(', ') : '';
        if ($systemRoles !== '') {
            $parts[] = 'Системные роли: '.$systemRoles;
        }

        if (filled($profile->job_title)) {
            $parts[] = 'Должность из переписки: '.trim((string) $profile->job_title).$this->authorityNote($profile, $user);
        }

        if (filled($profile->department)) {
            $parts[] = 'Отдел из переписки: '.trim((string) $profile->department);
        }

        if (filled($profile->responsibility_scope)) {
            $parts[] = 'Зона ответственности из переписки: '.trim((string) $profile->responsibility_scope);
        }

        $regularTasks = array_values(array_filter((array) ($profile->regular_tasks ?? [])));
        if ($regularTasks !== []) {
            $parts[] = 'Регулярные задачи: '.implode('; ', array_slice($regularTasks, 0, 5));
        }

        $rejected = collect((array) ($profile->rejected_topics ?? []))
            ->pluck('label')
            ->filter()
            ->take(5)
            ->implode(', ');

        if ($rejected !== '') {
            $parts[] = 'Не предлагать без явной просьбы: '.$rejected;
        }

        if ($parts === []) {
            $parts[] = 'Профиль пока строится.';
        }

        return Str::limit(implode("\n", $parts), 1200, '...');
    }

    private function authorityNote(AiUserProfile $profile, User $user): string
    {
        $jobTitle = mb_strtolower((string) ($profile->job_title ?? ''));
        $claimsAuthority = str_contains($jobTitle, 'директор')
            || str_contains($jobTitle, 'руковод')
            || str_contains($jobTitle, 'главн')
            || str_contains($jobTitle, 'админ');

        if (! $claimsAuthority) {
            return '';
        }

        $hasAuthorityRole = (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'isMarketAdmin') && $user->isMarketAdmin());

        return $hasAuthorityRole ? ' (совпадает с управляющей ролью)' : ' (не подтверждено системной ролью)';
    }

    /**
     * @return array<string, mixed>
     */
    private function compact(AiUserProfile $profile): array
    {
        return [
            'job_title' => $profile->job_title,
            'department' => $profile->department,
            'responsibility_scope' => $profile->responsibility_scope,
            'regular_tasks' => array_values(array_filter((array) ($profile->regular_tasks ?? []))),
            'rejected_topics' => array_values((array) ($profile->rejected_topics ?? [])),
            'summary' => $profile->profile_summary,
        ];
    }

    private function cleanExtract(string $value, int $limit = 80): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');
        $value = preg_replace('/[.?!].*$/u', '', $value) ?: $value;

        return trim(Str::limit($value, $limit, ''));
    }

    private function topicLabel(string $topic): string
    {
        return match ($topic) {
            'debts' => 'долги и задолженности',
            'tickets' => 'обращения и диалоги',
            'tasks' => 'задачи и напоминания',
            'contracts' => 'договоры',
            'spaces' => 'места и карта',
            default => $topic,
        };
    }

    private function profilesAvailable(): bool
    {
        try {
            return Schema::hasTable('ai_user_profiles');
        } catch (\Throwable) {
            return false;
        }
    }
}
