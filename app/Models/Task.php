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
use Illuminate\Support\Facades\Schema;

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

    public const OPEN_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
    ];

    public const CLOSED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Новая',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_ON_HOLD => 'На паузе',
        self::STATUS_COMPLETED => 'Завершена',
        self::STATUS_CANCELLED => 'Отменена',
    ];

    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Низкий',
        self::PRIORITY_NORMAL => 'Обычный',
        self::PRIORITY_HIGH => 'Высокий',
        self::PRIORITY_URGENT => 'Критичный',
    ];

    // Роли участников (pivot task_participants.role)
    public const PARTICIPANT_ROLE_OBSERVER = 'observer';
    public const PARTICIPANT_ROLE_COEXECUTOR = 'coexecutor';

    public const PARTICIPANT_ROLE_LABELS = [
        self::PARTICIPANT_ROLE_OBSERVER => 'Наблюдатель',
        self::PARTICIPANT_ROLE_COEXECUTOR => 'Соисполнитель',
    ];

    /**
     * Кэш поддержки watchers, чтобы не дергать Schema::hasTable() многократно.
     */
    protected static ?bool $watchersSupportedCache = null;

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

    public static function statusOptions(): array
    {
        return self::STATUS_LABELS;
    }

    public static function priorityOptions(): array
    {
        return self::PRIORITY_LABELS;
    }

    public static function participantRoleOptions(): array
    {
        return self::PARTICIPANT_ROLE_LABELS;
    }

    public static function supportsWatchers(): bool
    {
        if (static::$watchersSupportedCache !== null) {
            return static::$watchersSupportedCache;
        }

        try {
            static::$watchersSupportedCache = Schema::hasTable('task_watchers');
        } catch (\Throwable) {
            static::$watchersSupportedCache = false;
        }

        return static::$watchersSupportedCache;
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
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeInWork(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query
            ->whereNull('assignee_id')
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query
            ->whereIn('priority', [self::PRIORITY_URGENT, self::PRIORITY_HIGH])
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query
            ->where('assignee_id', $userId)
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /**
     * “Наблюдаю” = observer (task_participants.role=observer) и/или watcher,
     * но не исполнитель.
     */
    public function scopeWatching(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->where(function (Builder $inner) use ($userId) {
                $inner->whereHas('observers', fn (Builder $q) => $q->whereKey($userId));

                if (self::supportsWatchers()) {
                    $inner->orWhereHas('watchers', fn (Builder $q) => $q->whereKey($userId));
                }
            })
            ->where(function (Builder $inner) use ($userId) {
                $inner->whereNull('assignee_id')
                    ->orWhere('assignee_id', '!=', $userId);
            });
    }

    /**
     * “Соисполняю” = coexecutor (task_participants.role=coexecutor), но не исполнитель.
     */
    public function scopeCoexecuting(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $query
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->whereHas('coexecutors', fn (Builder $q) => $q->whereKey($userId))
            ->where(function (Builder $inner) use ($userId) {
                $inner->whereNull('assignee_id')
                    ->orWhere('assignee_id', '!=', $userId);
            });
    }

    /**
     * “Рабочая” сортировка списка:
     * 1) просроченные
     * 2) ближайшие дедлайны
     * 3) без дедлайна
     * 4) закрытые в конце
     */
    public function scopeWorkOrder(Builder $query): Builder
    {
        $now = now()->toDateTimeString();

        return $query
            ->orderByRaw("
                CASE
                    WHEN status IN ('completed','cancelled') THEN 4
                    WHEN due_at IS NOT NULL AND due_at < ? THEN 0
                    WHEN due_at IS NOT NULL THEN 1
                    ELSE 2
                END ASC
            ", [$now])
            ->orderByRaw("CASE WHEN due_at IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy('due_at')
            ->orderByDesc('created_at');
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

    /**
     * Поддерживается только если реально есть таблица task_watchers.
     * Вызывай в запросах только под guards (supportsWatchers()).
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers', 'task_id', 'user_id');
    }

    /**
     * Общая связь участников (для совместимости).
     * В pivot есть role: observer|coexecutor.
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants', 'task_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function observers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants', 'task_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps()
            ->wherePivot('role', self::PARTICIPANT_ROLE_OBSERVER);
    }

    public function coexecutors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_participants', 'task_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps()
            ->wherePivot('role', self::PARTICIPANT_ROLE_COEXECUTOR);
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
