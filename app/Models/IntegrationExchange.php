<?php
# app/Models/IntegrationExchange.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Каноническая запись обмена интеграции (бизнес-событие).
 *
 * 1 входящий/исходящий обмен = 1 строка.
 * Технические подробности (сырые payload/файлы) могут храниться здесь или в отдельном аудите.
 *
 * @property int $id
 * @property int $market_id
 * @property string $direction
 * @property string $entity_type
 * @property string $status
 * @property string|null $file_path
 * @property array|null $payload
 * @property string|null $error
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property-read int|null $duration_ms
 */
class IntegrationExchange extends Model
{
    use HasFactory;

    // direction
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    // status
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'direction',
        'entity_type',
        'status',
        'file_path',
        'payload',
        'error',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'created_by' => 'integer',
        'payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Время выполнения обмена в миллисекундах (если есть started_at и finished_at).
     */
    public function getDurationMsAttribute(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return (int) $this->finished_at->diffInMilliseconds($this->started_at);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markStarted(): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->started_at = now();
        $this->finished_at = null;
        $this->error = null;
    }

    public function markFinishedOk(?array $payload = null): void
    {
        $this->status = self::STATUS_OK;
        $this->finished_at = now();

        if ($payload !== null) {
            $this->payload = $payload;
        }
    }

    public function markFinishedError(string $error, ?array $payload = null): void
    {
        $this->status = self::STATUS_ERROR;
        $this->error = $error;
        $this->finished_at = now();

        if ($payload !== null) {
            $this->payload = $payload;
        }
    }
}