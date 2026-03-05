<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceChatMessage extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_id',
        'sender_user_id',
        'sender_type',
        'body',
        'attachments',
        'read_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'read_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(MarketplaceChat::class, 'chat_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}

