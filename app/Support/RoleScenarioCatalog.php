<?php

declare(strict_types=1);

namespace App\Support;

class RoleScenarioCatalog
{
    /**
     * @return array<string, array{label: string, description: string, topics: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'super-admin' => [
                'label' => 'Супер-администратор',
                'description' => 'Полный доступ ко всем рынкам и системным настройкам.',
                'topics' => ['calendar', 'requests', 'messages', 'tasks', 'reminders'],
            ],
            'market-owner' => [
                'label' => 'Собственник рынка',
                'description' => 'Контроль ключевых показателей и критических событий рынка.',
                'topics' => ['calendar', 'tasks', 'reminders'],
            ],
            'market-admin' => [
                'label' => 'Администратор рынка',
                'description' => 'Операционное управление рынком, сотрудниками и настройками.',
                'topics' => ['calendar', 'requests', 'messages', 'tasks', 'reminders'],
            ],
            'market-manager' => [
                'label' => 'Управляющий рынком',
                'description' => 'Координация работы подразделений и контроль исполнения задач.',
                'topics' => ['calendar', 'requests', 'tasks', 'reminders'],
            ],
            'market-operator' => [
                'label' => 'Оператор рынка',
                'description' => 'Ежедневная операционная работа с обращениями и заявками.',
                'topics' => ['requests', 'messages', 'tasks'],
            ],
            'market-maintenance' => [
                'label' => 'Техническая служба',
                'description' => 'Техническая эксплуатация, ремонт и обслуживание.',
                'topics' => ['requests', 'tasks', 'reminders'],
            ],
            'market-engineer' => [
                'label' => 'Инженер',
                'description' => 'Исполнение инженерных задач и контроль техсостояния.',
                'topics' => ['requests', 'tasks', 'reminders'],
            ],
            'market-it' => [
                'label' => 'ИТ-специалист',
                'description' => 'Поддержка ИТ-инфраструктуры и интеграций.',
                'topics' => ['tasks', 'reminders'],
            ],
            'market-accountant' => [
                'label' => 'Бухгалтер',
                'description' => 'Финансовые операции, начисления, закрытие периодов.',
                'topics' => ['tasks', 'reminders'],
            ],
            'market-finance' => [
                'label' => 'Финансовый отдел',
                'description' => 'Контроль финансовых процессов и платежной дисциплины.',
                'topics' => ['tasks', 'reminders'],
            ],
            'market-marketing' => [
                'label' => 'Маркетинг',
                'description' => 'Продвижение событий рынка и коммуникации с аудиторией.',
                'topics' => ['calendar', 'tasks', 'reminders'],
            ],
            'market-advertising' => [
                'label' => 'Реклама и медиа',
                'description' => 'Планирование и запуск рекламных кампаний по событиям рынка.',
                'topics' => ['calendar', 'tasks'],
            ],
            'market-support' => [
                'label' => 'Служба поддержки',
                'description' => 'Коммуникация с арендаторами и сопровождение обращений.',
                'topics' => ['requests', 'messages'],
            ],
            'market-security' => [
                'label' => 'Служба безопасности',
                'description' => 'Контроль безопасности и оперативные регламенты.',
                'topics' => ['calendar', 'tasks', 'reminders'],
            ],
            'market-guard' => [
                'label' => 'Охранник',
                'description' => 'Исполнение задач безопасности на местах.',
                'topics' => ['tasks', 'reminders'],
            ],
            'market-hr' => [
                'label' => 'Кадровая служба',
                'description' => 'Подбор и сопровождение сотрудников.',
                'topics' => ['tasks', 'reminders'],
            ],
            'merchant' => [
                'label' => 'Продавец',
                'description' => 'Роль арендатора в контуре личного кабинета.',
                'topics' => ['requests', 'messages'],
            ],
            'merchant-user' => [
                'label' => 'Пользователь арендатора',
                'description' => 'Участник аккаунта арендатора в личном кабинете.',
                'topics' => ['requests', 'messages'],
            ],
            'staff' => [
                'label' => 'Сотрудник',
                'description' => 'Базовая служебная роль без специализированного профиля.',
                'topics' => ['tasks'],
            ],
            'tenant' => [
                'label' => 'Арендатор',
                'description' => 'Служебная роль для привязки пользователей арендаторов.',
                'topics' => ['requests', 'messages'],
            ],
            'user' => [
                'label' => 'Пользователь',
                'description' => 'Базовая техническая роль.',
                'topics' => [],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::definitions() as $slug => $definition) {
            $options[$slug] = $definition['label'];
        }

        return $options;
    }

    public static function labelForSlug(string $slug, ?string $fallback = null): string
    {
        $definition = self::definitions()[$slug] ?? null;

        if ($definition !== null) {
            return $definition['label'];
        }

        $translationKey = "roles.{$slug}";
        $translated = __($translationKey);
        if ($translated !== $translationKey) {
            return $translated;
        }

        return $fallback ?: $slug;
    }

    public static function descriptionForSlug(string $slug): ?string
    {
        return self::definitions()[$slug]['description'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function topicLabelsForSlug(string $slug): array
    {
        $topicLabels = [
            'calendar' => 'Календарь',
            'requests' => 'Обращения',
            'messages' => 'Сообщения',
            'tasks' => 'Задачи',
            'reminders' => 'Напоминания',
        ];

        $topics = self::definitions()[$slug]['topics'] ?? [];

        $labels = [];
        foreach ($topics as $topic) {
            $labels[] = $topicLabels[$topic] ?? $topic;
        }

        return array_values(array_unique($labels));
    }

    public static function topicSummaryForSlug(string $slug): string
    {
        $labels = self::topicLabelsForSlug($slug);

        return $labels === [] ? '—' : implode(', ', $labels);
    }
}

