<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRequest extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'market_space_id',
        'tenant_contract_id',
        'ticket_id',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'created_by_user_id',
        'resolved_at',
        'closed_at',
        'internal_notes',
        'is_active',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if (in_array($request->status, ['resolved', 'closed', 'cancelled'], true) && ! $request->resolved_at) {
                $request->resolved_at = now();
            }

            if (in_array($request->status, ['closed', 'cancelled'], true) && ! $request->closed_at) {
                $request->closed_at = now();
            }

            if (in_array($request->status, ['resolved', 'closed', 'cancelled'], true)) {
                $request->is_active = false;
            }
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

    public function marketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TenantContract::class, 'tenant_contract_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
