<?php
# app/Models/TaskComment.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        // legacy/совместимость: иногда “автор” был в user_id
        'user_id',
        // актуальное поле автора
        'author_user_id',
        'body',
    ];

    /**
     * Нормализуем автора: в проекте считаем author_user_id источником истины,
     * а user_id держим синхронизированным для обратной совместимости.
     */
    protected static function booted(): void
    {
        static::saving(function (self $comment): void {
            // Приводим к int там, где возможно
            if (filled($comment->author_user_id)) {
                $comment->author_user_id = (int) $comment->author_user_id;
            }
            if (filled($comment->user_id)) {
                $comment->user_id = (int) $comment->user_id;
            }

            // Если есть только user_id — переносим в author_user_id
            if (blank($comment->author_user_id) && filled($comment->user_id)) {
                $comment->author_user_id = (int) $comment->user_id;
            }

            // Если есть author_user_id — поддерживаем user_id в том же значении
            if (filled($comment->author_user_id) && (blank($comment->user_id) || (int) $comment->user_id !== (int) $comment->author_user_id)) {
                $comment->user_id = (int) $comment->author_user_id;
            }

            // Пустое сообщение не сохраняем “молча” — лучше пусть валидирует форма,
            // но на уровне модели тоже подстрахуемся.
            if (is_string($comment->body)) {
                $comment->body = trim($comment->body);
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Автор сообщения (используем в “чате”).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * legacy-связь, чтобы старый код не ломался.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
