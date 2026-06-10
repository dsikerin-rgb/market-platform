<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSettlementBalance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'opening_debit' => 'float',
        'opening_credit' => 'float',
        'turnover_debit' => 'float',
        'turnover_credit' => 'float',
        'closing_debit' => 'float',
        'closing_credit' => 'float',
        'payload' => 'array',
        'imported_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenantContract(): BelongsTo
    {
        return $this->belongsTo(TenantContract::class);
    }
}
