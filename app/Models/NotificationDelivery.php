<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use HasFactory;

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_id',
        'notification_type',
        'channel',
        'status',
        'notifiable_type',
        'notifiable_id',
        'market_id',
        'queued',
        'payload',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notifiable_id' => 'integer',
            'market_id' => 'integer',
            'queued' => 'boolean',
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function scopeForPeriod(Builder $query, \DateTimeInterface $from, ?\DateTimeInterface $to = null): Builder
    {
        $query->where('created_at', '>=', $from);

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }
}

