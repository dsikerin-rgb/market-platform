<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CabinetImpersonationAudit extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_DENIED = 'denied';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'impersonator_user_id',
        'tenant_id',
        'cabinet_user_id',
        'market_id',
        'started_at',
        'ended_at',
        'ip',
        'user_agent',
        'status',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cabinetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cabinet_user_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}

