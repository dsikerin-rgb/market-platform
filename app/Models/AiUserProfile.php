<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUserProfile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'market_id',
        'job_title',
        'department',
        'responsibility_scope',
        'regular_tasks',
        'rejected_topics',
        'facts',
        'profile_summary',
        'inferred_from_messages_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'market_id' => 'integer',
        'regular_tasks' => 'array',
        'rejected_topics' => 'array',
        'facts' => 'array',
        'inferred_from_messages_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
