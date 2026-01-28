<?php
# app/Models/TaskAttachment.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

        // При удалении записи стараемся удалить и сам файл (как ожидается во “вложениях”).
        static::deleted(function (self $attachment): void {
            if (blank($attachment->file_path)) {
                return;
            }

            $path = (string) $attachment->file_path;

            $disk = (string) (config('filament.default_filesystem_disk')
                ?: config('filesystems.default')
                ?: 'public');

            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
                // Не ломаем удаление записи из БД, даже если диск/файл недоступен.
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
