<?php
# app/Models/TaskComment.php

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
        'user_id',
        'author_user_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $comment): void {
            if (blank($comment->author_user_id) && filled($comment->user_id)) {
                $comment->author_user_id = (int) $comment->user_id;
            }

            if (blank($comment->user_id) && filled($comment->author_user_id)) {
                $comment->user_id = (int) $comment->author_user_id;
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
