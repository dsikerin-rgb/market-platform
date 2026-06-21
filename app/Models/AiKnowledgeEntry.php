<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'dictionary',
        'key',
        'label',
        'value',
        'confidence',
        'status',
        'source_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'last_seen_at',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'value' => 'array',
        'confidence' => 'integer',
        'source_user_id' => 'integer',
        'reviewed_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
