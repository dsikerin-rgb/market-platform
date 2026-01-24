<?php
# app/Models/TaskAttachment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAttachment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'file_path',
        'original_name',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attachment): void {
            if (blank($attachment->original_name) && filled($attachment->file_path)) {
                $attachment->original_name = basename((string) $attachment->file_path);
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
