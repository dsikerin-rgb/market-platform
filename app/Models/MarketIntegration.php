<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketIntegration extends Model
{
    protected $table = 'market_integrations';

    protected $fillable = [
        'market_id',
        'type',
        'token_hash',
        'is_active',
    ];

    public const TYPE_1C = '1c';

    protected $casts = [
        'is_active' => 'bool',
    ];
}
