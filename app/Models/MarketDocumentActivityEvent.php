<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketDocumentActivityEvent extends Model
{
    public const ACTION_UPLOADED = 'uploaded';
    public const ACTION_SHARED = 'shared';
    public const ACTION_RENAMED = 'renamed';
    public const ACTION_MOVED = 'moved';
    public const ACTION_TRASHED = 'trashed';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_DELETED_PERMANENTLY = 'deleted_permanently';
    public const ACTION_PURGED_BY_RETENTION = 'purged_by_retention';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'market_document_id',
        'actor_user_id',
        'target_user_id',
        'folder_id',
        'action',
        'visibility',
        'document_name',
        'file_path',
        'ip_address',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'market_document_id' => 'integer',
        'actor_user_id' => 'integer',
        'target_user_id' => 'integer',
        'folder_id' => 'integer',
        'payload' => 'array',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MarketDocument::class, 'market_document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function scopeVisibleFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isMarketAdmin() && $user->market_id) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return [
            self::ACTION_UPLOADED => 'Загрузка',
            self::ACTION_SHARED => 'Доступ открыт',
            self::ACTION_RENAMED => 'Переименование',
            self::ACTION_MOVED => 'Перенос',
            self::ACTION_TRASHED => 'В корзину',
            self::ACTION_RESTORED => 'Восстановление',
            self::ACTION_DELETED_PERMANENTLY => 'Окончательное удаление',
            self::ACTION_PURGED_BY_RETENTION => 'Автоочистка корзины',
        ];
    }

    public function actionLabel(): string
    {
        return self::actionOptions()[$this->action] ?? (string) $this->action;
    }
}
