<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payment_date' => 'date',
        'period' => 'date',
        'amount' => 'float',
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
