<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

class AiConsultantService
{
    /**
     * @param  list<array{role:string,content:string}>  $history
     * @param  array<string,mixed>  $pageContext
     * @return array{answer:string,error_type:'provider'|'auth'|'connectivity'|null,chips:list<array{label:string,url:string}>,pending_action?:array<string,mixed>|null}
     */
    public function answer(User $user, int $marketId, string $question, array $history = [], array $pageContext = []): array
    {
        $question = trim($question);
        $settingsService = app(AiAgentSettings::class);
        $settings = $settingsService->get();
        $settings['_market_id'] = $marketId;
        $settings['_can_read_data'] = $settingsService->canReadData($user, $settings);
        $settings['_allowed_action_labels'] = $settingsService->allowedActionLabelsForUser($user, $settings);

        if (! (bool) $settings['enabled']) {
            return [
                'answer' => 'ИИ-консультант отключён в настройках.',
                'error_type' => null,
                'chips' => [],
                'pending_action' => null,
            ];
        }

        if (! $settingsService->canUseAgent($user, $settings)) {
            return [
                'answer' => 'ИИ-консультант недоступен для вашей роли. Обратитесь к администратору рынка, если нужен доступ.',
                'error_type' => null,
                'chips' => [],
                'pending_action' => null,
            ];
        }

        if ($question === '') {
            return [
                'answer' => 'Напишите вопрос по рынку, арендатору, месту, договору, задолженности или 1С-сверке.',
                'error_type' => null,
                'chips' => [],
                'pending_action' => null,
            ];
        }

        if ($this->isOnboardingStartRequest($question)) {
            $userProfile = app(AiUserProfileService::class)->compactForUser($user);

            return [
                'answer' => $this->onboardingIntroAnswer($userProfile),
                'error_type' => null,
                'chips' => [],
                'pending_action' => null,
            ];
        }

        $preferredName = $this->preferredNameFromText($question);
        if ($preferredName !== null) {
            app(AiUserProfileService::class)->updateEditableProfile($user, $marketId, [
                'preferred_name' => $preferredName,
            ]);

            return [
                'answer' => "Хорошо, буду обращаться: {$preferredName}.",
                'error_type' => null,
                'chips' => [],
                'pending_action' => null,
            ];
        }

        if (! filled(config('gigachat.auth_key'))) {
            return [
                'answer' => 'ИИ-консультант отключён: в окружении не задан GIGACHAT_AUTH_KEY.',
                'error_type' => 'auth',
                'chips' => [],
                'pending_action' => null,
            ];
        }

        $context = (bool) $settings['context_pack_enabled'] && (bool) $settings['_can_read_data']
            ? app(AiConsultantContextBuilder::class)->build($user, $marketId, $question)
            : [
                'scope' => [
                    'market_id' => $marketId > 0 ? $marketId : null,
                    'user_id' => (int) $user->id,
                ],
            ];

        if ((bool) $settings['page_context_enabled']) {
            $context['current_page'] = $this->pageContext($pageContext);
        }

        $userProfile = app(AiUserProfileService::class)->compactForUser($user);
        if ($userProfile !== []) {
            $context['user_profile'] = $userProfile;
        }

        $knowledgeService = app(AiKnowledgeService::class);
        $responsibilityKnowledge = $knowledgeService->responsibilitiesForMarket($marketId);
        $generalKnowledge = $knowledgeService->entriesForMarket($marketId, 12, excludeDictionaries: ['responsibilities']);
        if ($responsibilityKnowledge !== [] || $generalKnowledge !== []) {
            $context['agent_knowledge'] = array_filter([
                'responsibilities' => $responsibilityKnowledge,
                'general' => $generalKnowledge,
            ]);
        }

        $budgeter = app(AiContextBudgeter::class);
        $context = $budgeter->compact($context, $settings);

        $client = new GigaChatClient(
            http: app(Http::class),
            authKey: config('gigachat.auth_key'),
            scope: config('gigachat.scope'),
            model: config('gigachat.model'),
            verifySsl: (bool) config('gigachat.verify_ssl', true),
        );

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($settings, $marketId, $user, $userProfile)],
            ...$budgeter->compactHistory($history, $settings),
            ['role' => 'user', 'content' => $this->userPrompt($question, $context)],
        ];

        $response = $this->chatWithTools($client, $messages, $settings, $user);

        if (! $response['ok']) {
            logger()->warning('AI consultant request failed', [
                'user_id' => (int) $user->id,
                'market_id' => $marketId,
                'status' => $response['status'] ?? null,
                'failure_kind' => $response['failure_kind'] ?? null,
            ]);

            return [
                'answer' => $this->providerFallbackMessage($response['failure_kind'] ?? null),
                'error_type' => ($response['failure_kind'] ?? null) === 'auth' ? 'auth' : 'provider',
                'chips' => $response['chips'] ?? [],
                'pending_action' => null,
            ];
        }

        $answer = trim((string) $response['content']);
        if ($answer === '') {
            return [
                'answer' => 'ИИ-консультант вернул пустой ответ. Попробуйте уточнить вопрос.',
                'error_type' => 'provider',
                'chips' => $response['chips'] ?? [],
                'pending_action' => null,
            ];
        }

        $presented = app(AiAgentAnswerPresenter::class)->present($answer, $response['chips'] ?? []);

        return [
            'answer' => Str::limit($presented['answer'], 6000, '...'),
            'error_type' => null,
            'chips' => $presented['chips'],
            'pending_action' => $response['pending_action'] ?? null,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $settings
     * @return array{
     *   ok: bool,
     *   content: string|null,
     *   error: string|null,
     *   model_used: string|null,
     *   status: int|null,
     *   failure_kind: 'billing'|'rate_limit'|'auth'|'provider_http'|'network'|'empty_content'|null,
     *   chips?: list<array{label:string,url:string}>,
     *   pending_action?: array<string,mixed>|null
     * }
     */
    private function chatWithTools(GigaChatClient $client, array $messages, array $settings, User $user): array
    {
        $canUseBusinessTools = (bool) $settings['business_tools_enabled'] && (bool) $settings['_can_read_data'];
        $canUseReadSql = (bool) $settings['read_only_sql_enabled'] && (bool) $settings['_can_read_data'];
        $canUseActionTools = (bool) $settings['action_tools_enabled']
            && ((array) ($settings['_allowed_action_labels'] ?? [])) !== [];
        $toolsEnabled = $canUseReadSql || $canUseBusinessTools || $canUseActionTools;
        $maxRounds = $toolsEnabled
            ? (int) $settings['max_tool_rounds']
            : 0;
        $chips = [];

        for ($round = 0; $round <= $maxRounds; $round++) {
            $response = $client->chat(
                $messages,
                temperature: (float) $settings['temperature'],
                maxTokens: (int) $settings['max_tokens'],
            );

            if (! $response['ok']) {
                $response['chips'] = $chips;

                return $response;
            }

            $content = trim((string) $response['content']);
            $toolRequest = $this->extractToolRequest($content);

            if ($toolRequest === null) {
                $response['chips'] = $chips;

                return $response;
            }

            if ($this->toolRequiresConfirmation($toolRequest)) {
                if (! (bool) $settings['action_tools_enabled']) {
                    $toolResult = [
                        'ok' => false,
                        'message' => 'Рабочие действия отключены в настройках.',
                        'chips' => [],
                    ];
                } elseif (! app(AiAgentSettings::class)->canPrepareAction($user, $this->toolName($toolRequest), $settings)) {
                    $toolResult = [
                        'ok' => false,
                        'message' => 'Для вашей роли это действие недоступно.',
                        'chips' => [],
                    ];
                } else {
                    return [
                        ...$response,
                        'content' => $this->pendingActionAnswer($toolRequest),
                        'chips' => $chips,
                        'pending_action' => $this->pendingActionDraft($toolRequest),
                    ];
                }
            } elseif ($round >= $maxRounds) {
                break;
            } else {
                $toolResult = $this->runTool($user, $toolRequest, $settings);
            }

            $chips = [
                ...$chips,
                ...$this->normalizeChips((array) ($toolResult['chips'] ?? [])),
            ];

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = [
                'role' => 'user',
                'content' => "Результат действия приложения:\n"
                    .json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    ."\n\nЕсли действие уже выполнено успешно, не вызывай его повторно. Сформулируй ответ для сотрудника простым русским языком. Не показывай JSON, названия инструментов и технические детали.",
            ];
        }

        return [
            'ok' => false,
            'content' => null,
            'error' => 'AI consultant reached tool round limit',
            'model_used' => null,
            'status' => null,
            'failure_kind' => 'provider_http',
            'chips' => $chips,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function systemPrompt(array $settings, int $marketId, User $user, array $userProfile = []): string
    {
        $settings['_market_id'] = $marketId;
        $prompt = trim((string) $settings['system_prompt']);
        $friendlyName = $this->friendlyUserName($user, $userProfile);

        if ((bool) $settings['read_only_sql_enabled'] && (bool) ($settings['_can_read_data'] ?? false)) {
            $prompt .= "\n\n".app(AiReadOnlySqlTool::class)->schemaHint($marketId, $settings);
        }

        if ((bool) ($settings['business_tools_enabled'] ?? true) && (bool) ($settings['_can_read_data'] ?? false)) {
            $prompt .= "\n\n".app(AiAgentActionTool::class)->schemaHint(true, false);
        }

        if ((bool) $settings['action_tools_enabled']) {
            $allowedActions = (array) ($settings['_allowed_action_labels'] ?? []);
            if ($allowedActions !== []) {
                $prompt .= "\n\n".app(AiAgentActionTool::class)->schemaHint(false, true);
                $prompt .= "\n\nПо роли сотруднику можно: ".implode(', ', $allowedActions).". Не предлагай другие действия с записью или отправкой.";
                $prompt .= "\n\nДействия, которые меняют данные или отправляют сообщения, приложение покажет сотруднику как черновик с кнопкой подтверждения. Всё равно возвращай JSON действия, когда оно нужно для выполнения просьбы; не проси сотрудника подтверждать действие текстом.";
            }
        }

        if ($friendlyName !== '') {
            $prompt .= "\n\nСотрудника зовут {$friendlyName}. Обращайся к нему по имени, дружелюбно и спокойно, без полного ФИО и без официального канцелярского тона. Не начинай каждое сообщение с имени, используй имя естественно, когда это уместно.";
        }

        $prompt .= "\n\nЕсли в контексте есть user_profile, учитывай должность, отдел, зону ответственности, регулярные задачи и отклонённые темы сотрудника. Не предлагай отклонённые темы и задачи без явной просьбы пользователя вернуться к ним.";
        $prompt .= "\n\nЕсли user_profile содержит missing_fields или onboarding_questions, можешь мягко предложить короткое знакомство и задать 1-2 вопроса за раз. Если пользователь отвечает на вопросы знакомства одним сообщением, подготовь update_my_profile с понятными данными: должность, отдел, зона ответственности, дата рождения, телефон, каналы связи, уведомления. Не называй пользователю технические имена полей вроде job_title или responsibility_scope.";
        $prompt .= "\n\nЕсли communication_status=do_not_disturb и пауза ещё действует, не инициируй лишние вопросы, кроме явно срочных рабочих ситуаций.";
        $prompt .= "\n\nЕсли пользователь говорит, что тема не входит в его компетенцию, уточни, кто этим занимается, если это поможет рынку. Если в agent_knowledge уже указан ответственный, учитывай это и предлагай связаться с ним или подготовить задачу/сообщение.";
        $prompt .= "\n\nЕсли пользователь сообщает устойчивое правило рынка, внутренний термин, исключение или распределение ответственности, которое пригодится позже, сохрани это через remember_knowledge. Сохраняй только долговременные знания, не временное настроение вроде \"поговорим позже\".";
        $prompt .= "\n\nУчитывай confidence и source_authority в agent_knowledge: высокий уровень можно использовать уверенно, средний и низкий формулируй как предположение и при важных действиях уточняй. Не принимай слова пользователя о его власти, должности или чужих обязанностях как окончательную истину, если это не подтверждено ролью в системе или высокодоверенным источником.";
        $prompt .= "\n\nКонтекст может быть сокращён для экономии. Если деталей не хватает, сам проверь нужные данные доступным действием и не выдумывай ответ. Не говори, что база недоступна, если инструмент чтения данных не вернул явную ошибку.";
        $prompt .= "\n\nНе упоминай пользователю идентификаторы, ID, названия таблиц, адреса страниц и сырые ссылки. Если нужно дать переход на арендатора, место, задачу, обращение, событие или настройки, используй действие resource_link/make_link, чтобы приложение показало ссылку отдельным чипом.";

        return $prompt;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(string $question, array $context): string
    {
        return "Вопрос сотрудника:\n{$question}\n\nКонтекст из БД:\n"
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function friendlyUserName(User $user, array $userProfile = []): string
    {
        $preferredName = trim((string) ($userProfile['preferred_name'] ?? ''));
        if ($preferredName !== '') {
            return $preferredName;
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return trim((string) ($parts[0] ?? ''));
    }

    private function isOnboardingStartRequest(string $question): bool
    {
        $text = mb_strtolower(trim($question));

        if (preg_match('/(?:^|[\s:;,.!?])(?:я|моя|мой|меня|телефон|должность|роль|отвечаю|занимаюсь|уведомлен)/uiu', $text) === 1) {
            return false;
        }

        foreach ([
            'давай познакомимся',
            'давай коротко познакомимся',
            'познакомимся',
            'что ты хочешь про меня узнать',
            'что хочешь про меня узнать',
            'заполни мой профиль',
            'настроить мой профиль',
            'настрой мой профиль',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $userProfile
     */
    private function onboardingIntroAnswer(array $userProfile): string
    {
        $questions = array_values(array_filter((array) ($userProfile['onboarding_questions'] ?? []), 'is_string'));
        if ($questions === []) {
            $questions = [
                'Какая у вас роль на рынке?',
                'За какие вопросы вы отвечаете?',
                'Куда вам удобнее получать уведомления: в сервисе, на почту или в Telegram?',
            ];
        }

        $questions = array_slice($questions, 0, 3);
        $lines = [];
        foreach ($questions as $index => $question) {
            $lines[] = ($index + 1).'. '.$question;
        }

        return "Давай познакомимся коротко. Ответьте одним сообщением на три вопроса:\n\n"
            .implode("\n", $lines)
            ."\n\nЯ подготовлю обновление профиля и покажу его перед сохранением.";
    }

    private function preferredNameFromText(string $question): ?string
    {
        foreach ([
            '/(?:зови|называй)\s+меня\s+(.{2,40})/uiu',
            '/(?:обращайся\s+ко\s+мне|можешь\s+обращаться\s+ко\s+мне)\s+(.{2,40})/uiu',
            '/(?:для\s+тебя\s+я|можно\s+просто)\s+(.{2,40})/uiu',
        ] as $pattern) {
            if (! preg_match($pattern, $question, $matches)) {
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
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');
        $value = preg_replace('/[.?!].*$/u', '', $value) ?: $value;
        $value = preg_replace('/^(пожалуйста|пж|плиз|просто)\s+/uiu', '', $value) ?: $value;
        $value = preg_replace('/\s+(пожалуйста|пж|плиз)$/uiu', '', $value) ?: $value;
        $value = trim($value, " \t\n\r\0\x0B\"'«».,;:!?");

        if (! preg_match('/^[\pL][\pL\pN\s.\-]{1,39}$/u', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     */
    private function toolRequiresConfirmation(array $toolRequest): bool
    {
        $tool = $this->toolName($toolRequest);

        return in_array($tool, [
            'create_task',
            'create_reminder',
            'create_event',
            'send_staff_message',
            'send_tenant_message',
            'update_my_profile',
            'profile_update',
            'remember_knowledge',
            'remember_fact',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     */
    private function toolName(array $toolRequest): string
    {
        return strtolower(trim((string) ($toolRequest['tool'] ?? $toolRequest['name'] ?? '')));
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     */
    private function pendingActionAnswer(array $toolRequest): string
    {
        return match (strtolower(trim((string) ($toolRequest['tool'] ?? $toolRequest['name'] ?? '')))) {
            'create_reminder' => 'Подготовил напоминание. Проверьте, когда и о чём напомнить, затем подтвердите создание.',
            'create_event' => 'Подготовил событие. Проверьте дату и описание, затем подтвердите создание.',
            'send_staff_message' => 'Подготовил сообщение сотруднику. Проверьте текст и подтвердите отправку.',
            'send_tenant_message' => 'Подготовил сообщение арендатору. Проверьте текст и подтвердите отправку.',
            'update_my_profile', 'profile_update' => 'Подготовил обновление профиля. Проверьте, что всё верно, и подтвердите сохранение.',
            'remember_knowledge', 'remember_fact' => 'Подготовил запись в справочник агента. Проверьте факт и подтвердите сохранение.',
            default => 'Подготовил задачу. Проверьте детали и подтвердите, чтобы я её создал.',
        };
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     * @return array<string,mixed>
     */
    private function pendingActionDraft(array $toolRequest): array
    {
        $tool = $this->toolName($toolRequest);
        $payload = $this->sanitizePendingActionPayload($toolRequest);

        return [
            'status' => 'pending',
            'tool' => $tool,
            'payload' => $payload,
            'title' => $this->pendingActionTitle($tool),
            'summary' => $this->pendingActionSummary($tool, $payload),
            'confirm_label' => $this->pendingActionConfirmLabel($tool),
            'cancel_label' => 'Отменить',
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function pendingActionTitle(string $tool): string
    {
        return match ($tool) {
            'create_reminder' => 'Новое напоминание',
            'create_event' => 'Новое событие',
            'send_staff_message' => 'Сообщение сотруднику',
            'send_tenant_message' => 'Сообщение арендатору',
            'update_my_profile', 'profile_update' => 'Обновление профиля',
            'remember_knowledge', 'remember_fact' => 'Запись в справочник',
            default => 'Новая задача',
        };
    }

    private function pendingActionConfirmLabel(string $tool): string
    {
        return match ($tool) {
            'create_reminder' => 'Создать напоминание',
            'create_event' => 'Создать событие',
            'send_staff_message' => 'Отправить сотруднику',
            'send_tenant_message' => 'Отправить арендатору',
            'update_my_profile', 'profile_update' => 'Сохранить в профиль',
            'remember_knowledge', 'remember_fact' => 'Сохранить в справочник',
            default => 'Создать задачу',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array{label:string,value:string}>
     */
    private function pendingActionSummary(string $tool, array $payload): array
    {
        $rows = [];
        $add = static function (string $label, mixed $value) use (&$rows): void {
            $value = Str::limit(trim((string) $value), 260, '');
            if ($value !== '') {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        };

        if ($tool === 'create_reminder') {
            $add('Напомнить', $payload['title'] ?? '');
            $add('Детали', $payload['description'] ?? $payload['message'] ?? '');
            $add('Когда', $payload['due_at'] ?? $payload['remind_at'] ?? '');
            $add('Кому', $payload['assignee_query'] ?? (($payload['assignee_user_id'] ?? null) ? 'выбранный сотрудник' : 'мне'));
            $add('Важность', $payload['priority'] ?? '');

            return $rows;
        }

        if ($tool === 'create_task') {
            $add('Название', $payload['title'] ?? '');
            $add('Описание', $payload['description'] ?? $payload['message'] ?? '');
            $add('Срок', $payload['due_at'] ?? $payload['remind_at'] ?? '');
            $add('Исполнитель', $payload['assignee_query'] ?? (($payload['assignee_user_id'] ?? null) ? 'выбранный сотрудник' : ''));
            $add('Важность', $payload['priority'] ?? '');

            return $rows;
        }

        if ($tool === 'create_event') {
            $add('Событие', $payload['title'] ?? '');
            $add('Описание', $payload['description'] ?? '');
            $add('Дата начала', $payload['starts_at'] ?? $payload['date'] ?? '');
            $add('Дата окончания', $payload['ends_at'] ?? '');

            if (array_key_exists('all_day', $payload)) {
                $add('Формат', ((bool) $payload['all_day']) ? 'весь день' : 'по времени');
            }

            return $rows;
        }

        if ($tool === 'send_staff_message') {
            $add('Кому', $payload['recipient_query'] ?? (($payload['recipient_user_id'] ?? null) ? 'выбранный сотрудник' : ''));
            $add('Тема', $payload['subject'] ?? '');
            $add('Сообщение', $payload['message'] ?? $payload['body'] ?? '');

            return $rows;
        }

        if ($tool === 'send_tenant_message') {
            $add('Кому', $payload['tenant_query'] ?? (($payload['tenant_id'] ?? null) ? 'выбранный арендатор' : ''));
            $add('Тема', $payload['subject'] ?? '');
            $add('Сообщение', $payload['message'] ?? $payload['body'] ?? '');
            $add('Важность', $payload['priority'] ?? '');
        }

        if (in_array($tool, ['update_my_profile', 'profile_update'], true)) {
            $add('Как обращаться', $payload['preferred_name'] ?? '');
            $add('Должность', $payload['job_title'] ?? '');
            $add('Отдел', $payload['department'] ?? '');
            $add('Зона ответственности', $payload['responsibility_scope'] ?? '');
            $add('Дата рождения', $payload['birth_date'] ?? '');
            $add('Телефон', $payload['phone'] ?? '');
            $add('Регулярные задачи', $this->humanList($payload['regular_tasks'] ?? []));
            $add('Удобные каналы связи', $this->humanList($payload['preferred_contact_channels'] ?? []));
            $add('Каналы уведомлений', $this->humanList($payload['notification_channels'] ?? []));
            $add('Темы уведомлений', $this->humanList($payload['notification_topics'] ?? []));
            $add('Готовность к общению', $this->communicationStatusLabel($payload['communication_status'] ?? ''));
        }

        if (in_array($tool, ['remember_knowledge', 'remember_fact'], true)) {
            $add('Раздел', $payload['dictionary'] ?? $payload['book'] ?? '');
            $add('Название', $payload['label'] ?? $payload['title'] ?? '');
            $add('Тема', $payload['subject'] ?? $payload['topic'] ?? '');
            $add('Факт', $payload['fact'] ?? $payload['value'] ?? $payload['description'] ?? '');
            $add('Доверие', $payload['confidence'] ?? '');
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePendingActionPayload(array $payload): array
    {
        $tool = strtolower(trim((string) ($payload['tool'] ?? $payload['name'] ?? '')));
        $allowedKeys = [
            'tool',
            'title',
            'description',
            'message',
            'body',
            'due_at',
            'remind_at',
            'starts_at',
            'ends_at',
            'date',
            'all_day',
            'assignee_user_id',
            'assignee_query',
            'recipient_user_id',
            'recipient_query',
            'tenant_id',
            'tenant_query',
            'market_space_id',
            'subject',
            'priority',
            'preferred_name',
            'job_title',
            'department',
            'responsibility_scope',
            'birth_date',
            'phone',
            'regular_tasks',
            'preferred_contact_channels',
            'notification_channels',
            'notification_topics',
            'communication_status',
            'pause_hours',
            'dictionary',
            'book',
            'label',
            'subject',
            'topic',
            'fact',
            'value',
            'key',
            'confidence',
        ];

        $result = ['tool' => $tool];

        foreach ($allowedKeys as $key) {
            if ($key === 'tool' || ! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $result[$key] = $value;

                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $result[$key] = Str::limit(trim((string) $value), 5000, '');
                continue;
            }

            if (is_array($value)) {
                $result[$key] = collect($value)
                    ->map(static fn (mixed $item): string => Str::limit(trim((string) $item), 240, ''))
                    ->filter()
                    ->values()
                    ->take(20)
                    ->all();
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     */
    private function humanList(mixed $value): string
    {
        if (! is_array($value)) {
            return trim((string) $value);
        }

        return collect($value)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->implode(', ');
    }

    /**
     * @param mixed $value
     */
    private function communicationStatusLabel(mixed $value): string
    {
        return match (trim((string) $value)) {
            'available' => 'можно писать',
            'do_not_disturb' => 'не беспокоить временно',
            default => '',
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractToolRequest(string $content): ?array
    {
        $payload = $this->decodeToolPayload($content);
        if (! is_array($payload)) {
            return null;
        }

        $tool = strtolower(trim((string) ($payload['tool'] ?? $payload['name'] ?? '')));

        return $tool !== '' ? $payload : null;
    }

    /**
     * @param  array<string,mixed>  $toolRequest
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function runTool(User $user, array $toolRequest, array $settings): array
    {
        $tool = $this->toolName($toolRequest);

        if ($tool === 'read_sql') {
            if (! (bool) $settings['read_only_sql_enabled'] || ! (bool) ($settings['_can_read_data'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'Проверка данных недоступна для вашей роли или отключена в настройках.',
                    'chips' => [],
                ];
            }

            $sql = trim((string) ($toolRequest['sql'] ?? ''));

            return app(AiReadOnlySqlTool::class)->run(
                marketId: (int) data_get($settings, '_market_id', 0),
                sql: $sql,
                settings: $settings,
            );
        }

        if ($this->toolRequiresConfirmation($toolRequest)) {
            if (! (bool) $settings['action_tools_enabled'] || ! app(AiAgentSettings::class)->canPrepareAction($user, $tool, $settings)) {
                return [
                    'ok' => false,
                    'message' => 'Для вашей роли это действие недоступно.',
                    'chips' => [],
                ];
            }
        } elseif (! (bool) ($settings['business_tools_enabled'] ?? true) || ! (bool) ($settings['_can_read_data'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Типовые проверки недоступны для вашей роли или отключены в настройках.',
                'chips' => [],
            ];
        }

        return app(AiAgentActionTool::class)->run(
            actor: $user,
            marketId: (int) data_get($settings, '_market_id', 0),
            payload: $toolRequest,
        );
    }

    /**
     * @param  array<int, mixed>  $chips
     * @return list<array{label:string,url:string}>
     */
    private function normalizeChips(array $chips): array
    {
        return collect($chips)
            ->filter(static fn (mixed $chip): bool => is_array($chip))
            ->map(static fn (array $chip): array => [
                'label' => Str::limit(trim((string) ($chip['label'] ?? '')), 120, ''),
                'url' => trim((string) ($chip['url'] ?? '')),
            ])
            ->filter(static fn (array $chip): bool => $chip['label'] !== '' && $chip['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $pageContext
     * @return array<string,string>
     */
    private function pageContext(array $pageContext): array
    {
        return [
            'url' => Str::limit(trim((string) ($pageContext['url'] ?? '')), 500, ''),
            'path' => Str::limit(trim((string) ($pageContext['path'] ?? '')), 300, ''),
            'title' => Str::limit(trim((string) ($pageContext['title'] ?? '')), 160, ''),
            'heading' => Str::limit(trim((string) ($pageContext['heading'] ?? '')), 160, ''),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeToolPayload(string $content): ?array
    {
        $candidates = [trim($content)];

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $content, $match) === 1) {
            $candidates[] = trim($match[1]);
        }

        if (preg_match('/(\{.*\})/s', $content, $match) === 1) {
            $candidates[] = trim($match[1]);
        }

        foreach ($candidates as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function providerFallbackMessage(?string $failureKind): string
    {
        return match ($failureKind) {
            'billing' => 'ИИ-консультант недоступен: провайдер сообщил о проблеме оплаты или лимита.',
            'rate_limit' => 'ИИ-консультант временно недоступен: превышен лимит запросов провайдера.',
            'auth' => 'ИИ-консультант недоступен: провайдер отклонил авторизацию.',
            default => 'ИИ-консультант временно недоступен. Попробуйте повторить вопрос позже.',
        };
    }
}
