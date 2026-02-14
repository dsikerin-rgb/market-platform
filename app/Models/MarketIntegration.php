<?php
# app/Models/MarketIntegration.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketIntegration extends Model
{
    use HasFactory;

    protected $table = 'market_integrations';

    protected $fillable = [
        'market_id',
        'type',
        'token_hash',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'meta' => 'array',
    ];

    /**
     * Типы интеграций.
     * Сейчас нужен только 1C, но модель готова к расширению.
     */
    public const TYPE_1C = '1c';

    /**
     * Рынок, к которому привязана интеграция.
     */
    public function market()
    {
        return $this->belongsTo(Market::class);
    }
}
