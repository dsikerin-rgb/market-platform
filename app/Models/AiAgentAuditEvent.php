<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentAuditEvent extends Model
{
    protected $fillable = [
        'market_id',
        'user_id',
        'ai_conversation_id',
        'ai_message_id',
        'event_type',
        'tool',
        'status',
        'title',
        'summary',
        'request_payload',
        'result_payload',
        'result_message',
        'chips',
        'duration_ms',
        'error_type',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'user_id' => 'integer',
        'ai_conversation_id' => 'integer',
        'ai_message_id' => 'integer',
        'summary' => 'array',
        'request_payload' => 'array',
        'result_payload' => 'array',
        'chips' => 'array',
        'duration_ms' => 'integer',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }
}
