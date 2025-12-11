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
            if ($request->status === 'resolved' && ! $request->resolved_at) {
                $request->resolved_at = now();
            }

            if ($request->status === 'closed' && ! $request->closed_at) {
                if (! $request->resolved_at) {
                    $request->resolved_at = now();
                }

                $request->closed_at = now();
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
