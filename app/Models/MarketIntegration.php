<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketIntegration extends Model
{
    use HasFactory;

    protected $table = 'market_integrations';

    protected $fillable = [
        'market_id',
        'type',
        'name',
        'auth_token',
        'status',
        'last_sync_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    /**
     * Типы интеграций.
     */
    public const TYPE_1C = 'one_c';

    /**
     * Рынок, к которому привязана интеграция.
     */
    public function market()
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * Проверка активности интеграции.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Проверка типа интеграции.
     */
    public function isOneC(): bool
    {
        return $this->type === self::TYPE_1C;
    }

    /**
     * Валидация токена.
     */
    public function isValidToken(string $token): bool
    {
        return $this->auth_token === $token && $this->isActive();
    }
}
