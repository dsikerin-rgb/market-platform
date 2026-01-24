<?php
# app/Models/Ticket.php

namespace App\Models;

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

            if (! $ticket->category || ! in_array($ticket->category, $categories, true)) {
                return;
            }

            Task::create([
                'market_id' => $ticket->market_id,
                'title' => "Заявка #{$ticket->id}: {$ticket->subject}",
                'description' => $ticket->description,
                'status' => Task::STATUS_NEW,
                'priority' => $ticket->priority ?? Task::PRIORITY_NORMAL,
                'source_type' => $ticket::class,
                'source_id' => $ticket->id,
            ]);
        });
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
