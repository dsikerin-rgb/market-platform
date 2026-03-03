<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TicketChatNotificationRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketComment extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::created(function (self $comment): void {
            $sender = User::query()->find((int) $comment->user_id);
            if (! $sender instanceof User) {
                return;
            }

            $ticket = Ticket::query()->find((int) $comment->ticket_id);
            if (! $ticket instanceof Ticket) {
                return;
            }

            try {
                app(TicketChatNotificationRouter::class)->notifyOnCommentCreated($ticket, $sender);
            } catch (\Throwable) {
                // Notification failures must not break business flow.
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
