<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoRequest extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';

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
}
