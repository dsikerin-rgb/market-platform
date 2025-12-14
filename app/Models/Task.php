<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELED = 'canceled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'created_by',
        'assignee_id',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'created_by' => 'integer',
        'assignee_id' => 'integer',
        'source_id' => 'integer',
        'due_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            // Автозаполняем автора задачи, если не задано явно
            if (blank($task->created_by) && auth()->check()) {
                $task->created_by = (int) auth()->id();
            }

            // Дефолты (на случай пустых значений)
            $task->status ??= self::STATUS_NEW;
            $task->priority ??= self::PRIORITY_NORMAL;
        });
    }

    public function scopeForMarket(Builder $query, int $marketId): Builder
    {
        return $query->where('market_id', $marketId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_DONE, self::STATUS_CANCELED]);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers', 'task_id', 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }
}
