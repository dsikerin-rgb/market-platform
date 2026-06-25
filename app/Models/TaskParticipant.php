<?php
# app/Models/TaskParticipant.php

namespace App\Models;

use App\Notifications\TaskParticipantAssignedNotification;
use App\Support\TaskAssignmentRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskParticipant extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'user_id',
        'role',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $participant): void {
            if (! auth()->check() || blank($participant->user_id)) {
                return;
            }

            if (blank($participant->role)) {
                $participant->role = Task::PARTICIPANT_ROLE_OBSERVER;
            }

            $rules = app(TaskAssignmentRules::class);

            if ((string) $participant->role === Task::PARTICIPANT_ROLE_COEXECUTOR) {
                $rules->assertCanAssignWorkToUserIds(auth()->user(), [(int) $participant->user_id], 'coexecutor_user_ids');

                return;
            }

            if ((string) $participant->role === Task::PARTICIPANT_ROLE_OBSERVER) {
                $rules->assertCanObserveUserIds(auth()->user(), [(int) $participant->user_id], 'observer_user_ids');
            }
        });

        static::created(function (self $participant): void {
            $participant->notifyAssigned();
        });

        static::updated(function (self $participant): void {
            if ($participant->wasChanged('role')) {
                $participant->notifyAssigned();
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private function notifyAssigned(): void
    {
        $actorId = auth()->check() ? (int) auth()->id() : 0;
        $userId = (int) ($this->user_id ?? 0);

        if ($userId <= 0 || ($actorId > 0 && $actorId === $userId)) {
            return;
        }

        $this->loadMissing('task', 'user');

        if (! $this->task instanceof Task || ! $this->user instanceof User) {
            return;
        }

        $this->user->notify(new TaskParticipantAssignedNotification($this->task, (string) $this->role));
    }
}
