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
        'source_user_id',
        'last_seen_at',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'value' => 'array',
        'confidence' => 'integer',
        'source_user_id' => 'integer',
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
}
