<?php
# app/Models/Ticket.php

declare(strict_types=1);

namespace App\Models;

use App\Support\TicketChatNotificationRouter;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Ticket extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'assigned_to',
    ];

    protected static function booted(): void
    {
        static::created(function (self $ticket): void {
            $categories = (array) config('tasks.auto_create_from_ticket_categories', []);

            if ($ticket->category && in_array($ticket->category, $categories, true)) {
                Task::create([
                    'market_id' => $ticket->market_id,
                    'title' => "Заявка #{$ticket->id}: {$ticket->subject}",
                    'description' => $ticket->description,
                    'status' => Task::STATUS_NEW,
                    'priority' => $ticket->priority ?? Task::PRIORITY_NORMAL,
                    'source_type' => $ticket::class,
                    'source_id' => $ticket->id,
                ]);
            }

            if (! empty($ticket->assigned_to)) {
                $assignee = User::query()->find($ticket->assigned_to);

                if ($assignee instanceof User) {
                    self::sendInAppNotification(
                        recipient: $assignee,
                        title: 'Вам назначена заявка',
                        body: "Заявка #{$ticket->id}: {$ticket->subject}",
                        url: self::getFilamentTicketUrl($ticket),
                    );
                }
            }

            $actor = auth()->user();
            if ($actor instanceof User) {
                try {
                    app(TicketChatNotificationRouter::class)->notifyOnTicketCreated($ticket, $actor);
                } catch (\Throwable) {
                    // Notification failures must not break business flow.
                }
            }
        });

        static::updated(function (self $ticket): void {
            if ($ticket->wasChanged('assigned_to')) {
                $newAssigneeId = $ticket->assigned_to;

                if (! empty($newAssigneeId)) {
                    $assignee = User::query()->find($newAssigneeId);

                    if ($assignee instanceof User) {
                        self::sendInAppNotification(
                            recipient: $assignee,
                            title: 'Вам назначена заявка',
                            body: "Заявка #{$ticket->id}: {$ticket->subject}",
                            url: self::getFilamentTicketUrl($ticket),
                        );
                    }
                }
            }

            if ($ticket->wasChanged('status')) {
                $assignee = $ticket->user()->first();

                if ($assignee instanceof User) {
                    $oldStatus = (string) $ticket->getOriginal('status');
                    $newStatus = (string) $ticket->status;

                    self::sendInAppNotification(
                        recipient: $assignee,
                        title: 'Изменён статус заявки',
                        body: "Заявка #{$ticket->id}: {$oldStatus} → {$newStatus}",
                        url: self::getFilamentTicketUrl($ticket),
                    );
                }
            }
        });
    }

    protected static function sendInAppNotification(User $recipient, string $title, string $body, ?string $url = null): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body);

        if ($url) {
            $notification->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($recipient);
    }

    protected static function getFilamentTicketUrl(self $ticket): ?string
    {
        $resourceClass = \App\Filament\Resources\TicketResource::class;

        if (class_exists($resourceClass) && method_exists($resourceClass, 'getUrl')) {
            try {
                return $resourceClass::getUrl('view', ['record' => $ticket]);
            } catch (\Throwable) {
                // no-op
            }
        }

        $pageClass = \App\Filament\Pages\Requests::class;

        if (class_exists($pageClass) && method_exists($pageClass, 'getUrl')) {
            try {
                $baseUrl = (string) $pageClass::getUrl();
                $separator = str_contains($baseUrl, '?') ? '&' : '?';

                return $baseUrl . $separator . 'ticket_id=' . $ticket->id;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'source');
    }
}
