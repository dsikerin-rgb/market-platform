<?php
# app/Models/Task.php

namespace App\Models;

use App\Notifications\TaskAssignedNotification;
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
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

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
        'completed_at',
        'created_by',
        'created_by_user_id',
        'assignee_id',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'created_by' => 'integer',
        'created_by_user_id' => 'integer',
        'assignee_id' => 'integer',
        'source_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            if (blank($task->created_by_user_id) && auth()->check()) {
                $task->created_by_user_id = (int) auth()->id();
            }

            if (blank($task->created_by) && filled($task->created_by_user_id)) {
                $task->created_by = (int) $task->created_by_user_id;
            }

            $task->status ??= self::STATUS_NEW;
            $task->priority ??= self::PRIORITY_NORMAL;
        });

        static::saving(function (self $task): void {
            if ($task->status === self::STATUS_COMPLETED && ! $task->completed_at) {
                $task->completed_at = now();
            }

            if ($task->status !== self::STATUS_COMPLETED) {
                $task->completed_at = null;
            }
        });

        static::created(function (self $task): void {
            $task->notifyAssignee();
        });

        static::updated(function (self $task): void {
            if ($task->wasChanged('assignee_id')) {
                $task->notifyAssignee();
            }
        });
    }

    public function scopeForMarket(Builder $query, User|int|null $marketOrUser): Builder
    {
        $marketId = $marketOrUser instanceof User
            ? $marketOrUser->market_id
            : $marketOrUser;

        if (! $marketId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('market_id', $marketId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants', 'task_id', 'user_id')
            ->withPivot('role');
    }

    public function participantEntries(): HasMany
    {
        return $this->hasMany(TaskParticipant::class, 'task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'task_id');
    }

    public function getSourceLabelAttribute(): string
    {
        if (! $this->source_type || ! $this->source_id) {
            return '—';
        }

        if ($this->source_type === Ticket::class) {
            return "Заявка #{$this->source_id}";
        }

        $base = class_basename($this->source_type);

        return "{$base} #{$this->source_id}";
    }

    private function notifyAssignee(): void
    {
        if (! $this->assignee_id) {
            return;
        }

        $assignee = User::query()->find($this->assignee_id);

        if (! $assignee) {
            return;
        }

        $assignee->notify(new TaskAssignedNotification($this));
    }
}
