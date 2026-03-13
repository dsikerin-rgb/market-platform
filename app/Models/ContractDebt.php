<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractDebt extends Model
{
    protected $table = 'contract_debts';

    protected $guarded = [];

    protected $casts = [
        'accrued_amount' => 'float',
        'paid_amount' => 'float',
        'debt_amount' => 'float',
        'calculated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'raw_payload' => 'array',
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
        return $this->belongsTo(TenantContract::class, 'contract_external_id', 'external_id');
    }
}
