<?php
# app/Models/TenantAccrual.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccrual extends Model
{
    protected $table = 'tenant_accruals';

    // Filament сохраняет через mass assignment.
    // Нам нужно разрешить сохранение хотя бы notes.
    protected $guarded = [];

    protected $casts = [
        'period' => 'date',
        'area_sqm' => 'float',
        'rent_rate' => 'float',
        'days' => 'integer',

        'rent_amount' => 'float',
        'management_fee' => 'float',
        'utilities_amount' => 'float',
        'electricity_amount' => 'float',

        'total_no_vat' => 'float',
        'vat_rate' => 'float',
        'total_with_vat' => 'float',

        'cash_amount' => 'float',

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
        return $this->belongsTo(TenantContract::class, 'tenant_contract_id');
    }

    public function marketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class);
    }
}
