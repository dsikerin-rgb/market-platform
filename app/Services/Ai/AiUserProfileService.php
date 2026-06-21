<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUserProfile;
use App\Models\User;
use App\Support\UserNotificationPreferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $this->clearExpiredCommunicationPause($profile);

        if ($conversation instanceof AiConversation) {
            $this->learnFromMessages($profile, $conversation, $user);
        }

        $profile->profile_summary = $this->buildSummary($profile, $user);
        $this->refreshOnboardingState($profile, $user);
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

        if (! $profile instanceof AiUserProfile) {
            return [];
        }

        $this->clearExpiredCommunicationPause($profile);
        if ($profile->isDirty()) {
            $profile->save();
        }

        return $this->compact($profile);
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
            $this->learnCommunicationState($profile, $body);
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

        $preferredName = $this->preferredNameFromText($body);
        if ($preferredName !== null) {
            $profile->preferred_name = $preferredName;
        }

        if (preg_match('/(?:моя должность|я работаю как|я работаю|должность)\s*[:\-—]?\s*(.{3,80})/uiu', $body, $matches)) {
            $profile->job_title = $this->cleanExtract($matches[1]);
        }

        if (preg_match('/(?:мой отдел|отдел)\s*[:\-—]?\s*(.{3,80})/uiu', $body, $matches)) {
            $profile->department = $this->cleanExtract($matches[1]);
        }

        if (preg_match('/(?:дата рождения|день рождения|родился|родилась)\s*[:\-—]?\s*(\d{1,2}[.\-\/]\d{1,2}(?:[.\-\/]\d{2,4})?)/uiu', $body, $matches)) {
            $birthDate = $this->parseBirthDate($matches[1]);
            if ($birthDate !== null) {
                $profile->birth_date = $birthDate;
            }
        }

        if (preg_match('/(?:мой телефон|телефон)\s*[:\-—]?\s*(\+?[\d\s().\-]{7,24})/uiu', $body, $matches)) {
            $phone = $this->normalizePhone($matches[1]);
            if ($phone !== '' && blank($profile->user?->phone ?? null)) {
                $facts['phone_candidate'] = $phone;
            }
        }

        $channels = $this->channelsFromText($body);
        if ($channels !== []) {
            $profile->preferred_contact_channels = $channels;
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
     * @param array<string, mixed> $payload
     * @return array{ok:bool,message:string,changed:list<string>}
     */
    public function updateEditableProfile(User $user, int $marketId, array $payload): array
    {
        if (! $this->profilesAvailable()) {
            return ['ok' => false, 'message' => 'Профиль ИИ-агента пока недоступен.', 'changed' => []];
        }

        $profile = AiUserProfile::query()->firstOrNew(['user_id' => (int) $user->id]);
        $profile->market_id = $marketId > 0 ? $marketId : ((int) ($user->market_id ?? 0) ?: null);
        $this->clearExpiredCommunicationPause($profile);
        $changed = [];

        if (array_key_exists('preferred_name', $payload)) {
            $value = $this->cleanPreferredName((string) $payload['preferred_name']);
            $profile->preferred_name = $value !== '' ? $value : null;
            $changed[] = 'обращение';
        }

        foreach ([
            'job_title' => 'должность',
            'department' => 'отдел',
        ] as $field => $label) {
            if (array_key_exists($field, $payload)) {
                $value = $this->cleanExtract((string) $payload[$field], 100);
                $user->forceFill([$field => $value !== '' ? $value : null])->save();
                $changed[] = $label;
            }
        }

        if (array_key_exists('responsibility_scope', $payload)) {
            $value = $this->cleanExtract((string) $payload['responsibility_scope'], 240);
            $profile->responsibility_scope = $value !== '' ? $value : null;
            $changed[] = 'зона ответственности';
        }

        if (array_key_exists('birth_date', $payload)) {
            $birthDate = $this->parseBirthDate((string) $payload['birth_date']);
            if ($birthDate !== null) {
                $user->forceFill(['birth_date' => $birthDate])->save();
                $changed[] = 'дата рождения';
            }
        }

        if (array_key_exists('regular_tasks', $payload)) {
            $tasks = array_values(array_filter(array_map(
                fn (mixed $task): string => $this->cleanExtract((string) $task, 180),
                (array) $payload['regular_tasks'],
            )));
            $profile->regular_tasks = array_slice(array_unique($tasks), 0, 12);
            $changed[] = 'регулярные задачи';
        }

        if (array_key_exists('preferred_contact_channels', $payload)) {
            $channels = app(UserNotificationPreferences::class)->normalizeChannels($payload['preferred_contact_channels']);
            $profile->preferred_contact_channels = $channels;
            $changed[] = 'предпочитаемые каналы связи';
        }

        if (array_key_exists('communication_status', $payload)) {
            $status = $this->normalizeCommunicationStatus((string) $payload['communication_status']);
            $profile->communication_status = $status;
            $profile->communication_paused_until = $status === 'do_not_disturb'
                ? now()->addHours(max(1, min((int) ($payload['pause_hours'] ?? 4), 24)))
                : null;
            $changed[] = 'готовность к общению';
        }

        if (array_key_exists('phone', $payload)) {
            $phone = $this->normalizePhone((string) $payload['phone']);
            if ($phone !== '') {
                $user->forceFill(['phone' => $phone])->save();
                $changed[] = 'телефон';
            }
        }

        if (array_key_exists('notification_channels', $payload) || array_key_exists('notification_topics', $payload)) {
            $preferences = app(UserNotificationPreferences::class);
            $existing = (array) ($user->notification_preferences ?? []);
            $normalized = $preferences->normalizeForStorage([
                ...$existing,
                'channels' => $payload['notification_channels'] ?? ($existing['channels'] ?? []),
                'topics' => $payload['notification_topics'] ?? ($existing['topics'] ?? null),
            ], fallbackSelfManage: (bool) ($existing['self_manage'] ?? false));
            $user->forceFill(['notification_preferences' => $normalized])->save();
            $changed[] = 'уведомления';
        }

        $profile->profile_summary = $this->buildSummary($profile, $user);
        $this->refreshOnboardingState($profile, $user);
        $profile->save();

        return [
            'ok' => true,
            'message' => $changed === []
                ? 'Не нашла безопасных полей для обновления.'
                : 'Обновила: '.implode(', ', array_values(array_unique($changed))).'.',
            'changed' => array_values(array_unique($changed)),
        ];
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

    private function learnCommunicationState(AiUserProfile $profile, string $body): void
    {
        $text = mb_strtolower($body);

        foreach (['не беспокоить', 'не хочу общаться', 'не готов общаться', 'позже', 'сейчас неудобно'] as $needle) {
            if (str_contains($text, $needle)) {
                $profile->communication_status = 'do_not_disturb';
                $profile->communication_paused_until = str_contains($text, 'не беспокоить')
                    ? now()->addDay()
                    : now()->addHours(4);

                return;
            }
        }

        foreach (['можно писать', 'готов общаться', 'можешь спрашивать', 'на связи'] as $needle) {
            if (str_contains($text, $needle)) {
                $profile->communication_status = 'available';
                $profile->communication_paused_until = null;

                return;
            }
        }
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

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $user = User::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($token).'%']);
                }
            })
            ->orderBy('id')
            ->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = User::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $query) use ($tokens, $operator): void {
                foreach ($tokens as $token) {
                    $query->where('name', $operator, '%'.$token.'%');
                }
            })
            ->orderBy('id')
            ->first();

        if ($user instanceof User) {
            return $user;
        }

        return User::query()
            ->where('market_id', $marketId)
            ->orderBy('id')
            ->get()
            ->first(function (User $candidate) use ($tokens): bool {
                $name = mb_strtolower((string) $candidate->name);

                foreach ($tokens as $token) {
                    if (! str_contains($name, mb_strtolower($token))) {
                        return false;
                    }
                }

                return true;
            });
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
        $authority = app(AiKnowledgeService::class)->sourceAuthority(
            $sourceUser,
            $responsible,
            'responsibilities',
            (string) $responsible->name,
        );

        return (int) ($authority['score'] ?? 55);
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

        if (filled($profile->preferred_name)) {
            $parts[] = 'Предпочитает обращение: '.trim((string) $profile->preferred_name);
        }

        if (filled($user->job_title ?? null)) {
            $parts[] = 'Должность: '.trim((string) $user->job_title);
        } elseif (filled($profile->job_title)) {
            $parts[] = 'Должность из переписки: '.trim((string) $profile->job_title).$this->authorityNote($profile, $user);
        }

        if (filled($user->department ?? null)) {
            $parts[] = 'Отдел: '.trim((string) $user->department);
        } elseif (filled($profile->department)) {
            $parts[] = 'Отдел из переписки: '.trim((string) $profile->department);
        }

        if ($user->birth_date) {
            $parts[] = 'Дата рождения: '.$user->birth_date->format('d.m.Y');
        } elseif ($profile->birth_date) {
            $parts[] = 'Дата рождения: '.$profile->birth_date->format('d.m.Y');
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

        if ((string) ($profile->communication_status ?? 'available') !== 'available') {
            $until = $profile->communication_paused_until?->format('d.m.Y H:i');
            $parts[] = 'Готовность к общению: временно не беспокоить'.($until ? " до {$until}" : '');
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
        $user = $profile->user;

        return [
            'preferred_name' => $profile->preferred_name,
            'job_title' => $user instanceof User ? ($user->job_title ?: $profile->job_title) : $profile->job_title,
            'department' => $user instanceof User ? ($user->department ?: $profile->department) : $profile->department,
            'birth_date' => ($user instanceof User ? $user->birth_date?->toDateString() : null) ?: $profile->birth_date?->toDateString(),
            'responsibility_scope' => $profile->responsibility_scope,
            'regular_tasks' => array_values(array_filter((array) ($profile->regular_tasks ?? []))),
            'rejected_topics' => array_values((array) ($profile->rejected_topics ?? [])),
            'preferred_contact_channels' => array_values(array_filter((array) ($profile->preferred_contact_channels ?? []))),
            'communication_status' => (string) ($profile->communication_status ?? 'available'),
            'communication_paused_until' => $profile->communication_paused_until?->toDateTimeString(),
            'onboarding_status' => (string) ($profile->onboarding_status ?? 'new'),
            'missing_fields' => $this->missingProfileFields($profile, $profile->user),
            'onboarding_questions' => $this->onboardingQuestions($profile, $profile->user),
            'summary' => $profile->profile_summary,
        ];
    }

    /**
     * @return list<string>
     */
    private function missingProfileFields(AiUserProfile $profile, ?User $user): array
    {
        $missing = [];

        if (blank($user?->job_title ?? null) && blank($profile->job_title)) {
            $missing[] = 'job_title';
        }

        if (blank($profile->responsibility_scope)) {
            $missing[] = 'responsibility_scope';
        }

        if (! $user?->birth_date && ! $profile->birth_date) {
            $missing[] = 'birth_date';
        }

        if (! $user instanceof User || blank($user->phone ?? null)) {
            $missing[] = 'phone';
        }

        if (! $user instanceof User || blank($user->telegram_chat_id ?? null)) {
            $missing[] = 'telegram';
        }

        if (! $user instanceof User || empty((array) ($user->notification_preferences ?? []))) {
            $missing[] = 'notification_preferences';
        }

        if (! $user instanceof User || blank($user->staff_avatar_path ?? null)) {
            $missing[] = 'profile_photo';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function onboardingQuestions(AiUserProfile $profile, ?User $user): array
    {
        $questions = [
            'job_title' => 'Какая у вас роль на рынке?',
            'responsibility_scope' => 'За какие вопросы вы отвечаете?',
            'birth_date' => 'Когда у вас день рождения?',
            'phone' => 'Какой телефон можно указать для связи?',
            'notification_preferences' => 'Куда вам удобнее получать уведомления: в сервисе, на почту или в Telegram?',
        ];

        return collect($this->missingProfileFields($profile, $user))
            ->map(static fn (string $field): ?string => $questions[$field] ?? null)
            ->filter()
            ->take(3)
            ->values()
            ->all();
    }

    private function refreshOnboardingState(AiUserProfile $profile, User $user): void
    {
        $this->clearExpiredCommunicationPause($profile);

        $missing = $this->missingProfileFields($profile, $user);
        $coreMissing = array_intersect($missing, ['job_title', 'responsibility_scope', 'phone']);

        $profile->onboarding_status = $coreMissing === [] ? 'complete' : 'incomplete';
        if ($profile->onboarding_status === 'complete' && ! $profile->onboarding_completed_at) {
            $profile->onboarding_completed_at = now();
        }
    }

    private function cleanExtract(string $value, int $limit = 80): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');
        $value = preg_replace('/[.?!].*$/u', '', $value) ?: $value;

        return trim(Str::limit($value, $limit, ''));
    }

    private function preferredNameFromText(string $body): ?string
    {
        foreach ([
            '/(?:зови|называй)\s+меня\s+(.{2,40})/uiu',
            '/(?:обращайся\s+ко\s+мне|можешь\s+обращаться\s+ко\s+мне)\s+(.{2,40})/uiu',
            '/(?:для\s+тебя\s+я|можно\s+просто)\s+(.{2,40})/uiu',
        ] as $pattern) {
            if (! preg_match($pattern, $body, $matches)) {
                continue;
            }

            $name = $this->cleanPreferredName((string) $matches[1]);
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    private function cleanPreferredName(string $value): string
    {
        $value = $this->cleanExtract($value, 40);
        $value = preg_replace('/^(пожалуйста|пж|плиз|просто)\s+/uiu', '', $value) ?: $value;
        $value = preg_replace('/\s+(пожалуйста|пж|плиз)$/uiu', '', $value) ?: $value;
        $value = trim($value, " \t\n\r\0\x0B\"'«».,;:!?");

        if (! preg_match('/^[\pL][\pL\pN\s.\-]{1,39}$/u', $value)) {
            return '';
        }

        return $value;
    }

    private function parseBirthDate(string $value): ?Carbon
    {
        $value = trim($value);

        foreach (['d.m.Y', 'd-m-Y', 'd/m/Y', 'd.m.y', 'd-m-y', 'd/m/y', 'd.m', 'd-m', 'd/m'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date instanceof Carbon) {
                    if (in_array($format, ['d.m', 'd-m', 'd/m'], true)) {
                        $date->year = 1900;
                    }

                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                // Try the next common date format.
            }
        }

        return null;
    }

    private function normalizePhone(string $value): string
    {
        $value = trim($value);

        return preg_replace('/[^\d+]/', '', $value) ?: '';
    }

    /**
     * @return list<string>
     */
    private function channelsFromText(string $body): array
    {
        $text = mb_strtolower($body);
        $channels = [];

        if (str_contains($text, 'телеграм') || str_contains($text, 'telegram')) {
            $channels[] = 'telegram';
        }

        if (str_contains($text, 'почт') || str_contains($text, 'email') || str_contains($text, 'e-mail')) {
            $channels[] = 'mail';
        }

        if (str_contains($text, 'в сервисе') || str_contains($text, 'в кабинете') || str_contains($text, 'на странице')) {
            $channels[] = 'database';
        }

        return array_values(array_unique($channels));
    }

    private function normalizeCommunicationStatus(string $status): string
    {
        $status = mb_strtolower(trim($status));

        return in_array($status, ['available', 'do_not_disturb'], true) ? $status : 'available';
    }

    private function clearExpiredCommunicationPause(AiUserProfile $profile): void
    {
        if (
            (string) ($profile->communication_status ?? 'available') === 'do_not_disturb'
            && $profile->communication_paused_until
            && $profile->communication_paused_until->isPast()
        ) {
            $profile->communication_status = 'available';
            $profile->communication_paused_until = null;
        }
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
