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
        'preferred_name',
        'job_title',
        'department',
        'birth_date',
        'responsibility_scope',
        'regular_tasks',
        'rejected_topics',
        'preferred_contact_channels',
        'communication_status',
        'communication_paused_until',
        'onboarding_status',
        'onboarding_completed_at',
        'facts',
        'profile_summary',
        'inferred_from_messages_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'market_id' => 'integer',
        'birth_date' => 'date',
        'regular_tasks' => 'array',
        'rejected_topics' => 'array',
        'preferred_contact_channels' => 'array',
        'facts' => 'array',
        'communication_paused_until' => 'datetime',
        'onboarding_completed_at' => 'datetime',
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
