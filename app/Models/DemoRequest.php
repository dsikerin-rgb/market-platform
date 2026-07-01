<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoRequest extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REJECTED = 'rejected';

    public const TYPE_DEMO = 'demo';
    public const TYPE_PILOT = 'pilot';
    public const TYPE_FREE = 'free';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'request_type',
        'name',
        'organization',
        'email',
        'phone',
        'city',
        'market_format',
        'spaces_count',
        'message',
        'source',
        'ip_hash',
        'user_agent',
        'metadata',
        'notified_at',
        'processed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'notified_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PILOT => 'ограниченный пилот',
            self::TYPE_FREE => 'бесплатная версия',
            default => 'демо',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Новая',
            self::STATUS_CONTACTED => 'Связались',
            self::STATUS_QUALIFIED => 'Квалифицирована',
            self::STATUS_REJECTED => 'Не подходит',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::statusOptions()[$status ?: self::STATUS_NEW] ?? (string) ($status ?: self::STATUS_NEW);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_CONTACTED => 'info',
            self::STATUS_QUALIFIED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'warning',
        };
    }
}
