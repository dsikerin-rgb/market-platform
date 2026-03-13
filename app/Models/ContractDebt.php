<?php
# app/Models/ContractDebt.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractDebt extends Model
{
    protected $table = 'contract_debts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'tenant_external_id',
        'contract_external_id',
        'period',
        'accrued_amount',
        'paid_amount',
        'debt_amount',
        'calculated_at',
        'source',
        'currency',
        'hash',
        'raw_payload',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'tenant_id' => 'integer',
        'accrued_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'calculated_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForMarket($query, int $marketId)
    {
        return $query->where('market_id', $marketId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLatestSnapshot($query)
    {
        return $query->orderByDesc('calculated_at');
    }
}
