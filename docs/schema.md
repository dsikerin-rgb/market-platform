# Схема данных: задачи и заявки

## tasks

Минимальная таблица задач для сотрудников рынка.

- `id`
- `market_id` → `markets.id`
- `title` (string)
- `description` (text, nullable)
- `status` (`new`, `in_progress`, `on_hold`, `completed`, `cancelled`)
- `priority` (`low`, `normal`, `high`, `urgent`)
- `due_at` (timestamp, nullable)
- `completed_at` (timestamp, nullable)
- `created_by_user_id` → `users.id` (nullable)
- `assignee_id` → `users.id` (nullable)
- `source_type` + `source_id` (polymorphic источник, например `Ticket`)
- `created_at`, `updated_at`

Индексы: `market_id`, `status`, `assignee_id`, `due_at`, `source_type+source_id`.

## task_participants

Участники/наблюдатели задачи (расширяемая модель роли).

- `task_id` → `tasks.id`
- `user_id` → `users.id`
- `role` (`assignee`, `co_assignee`, `observer`)
- уникальность: `task_id` + `user_id`

## task_comments

Комментарии к задаче.

- `task_id` → `tasks.id`
- `author_user_id` → `users.id`
- `body`
- `created_at`, `updated_at`

## task_attachments

Вложения к задаче.

- `task_id` → `tasks.id`
- `file_path`
- `original_name`
- `created_at`, `updated_at`

## tickets → tasks

При создании заявки (`tickets`) в категории из `config/tasks.php`
автоматически создаётся задача:

- `market_id` берётся из заявки
- `title`: `Заявка #<id>: <subject>`
- `description`: из заявки
- `status`: `new`
- `priority`: из заявки (или `normal`)
- `source`: `Ticket`
