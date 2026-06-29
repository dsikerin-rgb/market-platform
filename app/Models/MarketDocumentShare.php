<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class MarketDocumentShare extends Model
{
    public const ACCESS_VIEW = 'view';
    public const ACCESS_EDIT = 'edit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_document_id',
        'shared_with_user_id',
        'shared_by_user_id',
        'access_level',
        'revoked_at',
    ];

    protected $casts = [
        'market_document_id' => 'integer',
        'shared_with_user_id' => 'integer',
        'shared_by_user_id' => 'integer',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (MarketDocumentShare $share): void {
            $share->assertUsersBelongToDocumentMarket();
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MarketDocument::class, 'market_document_id');
    }

    public function sharedWith(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    private function assertUsersBelongToDocumentMarket(): void
    {
        if (! $this->market_document_id || ! Schema::hasTable('market_documents') || ! Schema::hasTable('users')) {
            return;
        }

        $documentMarketId = MarketDocument::query()
            ->whereKey((int) $this->market_document_id)
            ->value('market_id');

        if ($documentMarketId === null) {
            return;
        }

        $guard = app(MarketWriteGuard::class);

        if ($this->shared_with_user_id) {
            $recipientMarketId = User::query()
                ->whereKey((int) $this->shared_with_user_id)
                ->value('market_id');

            $guard->assertSameMarketId(
                $documentMarketId,
                $recipientMarketId,
                'shared_with_user_id',
                'Share recipient belongs to another market.',
            );
        }

        if ($this->shared_by_user_id) {
            $author = User::query()->find((int) $this->shared_by_user_id, ['id', 'market_id']);

            if ($author && ! $author->isSuperAdmin() && $author->market_id !== null) {
                $guard->assertSameMarketId(
                    $documentMarketId,
                    $author->market_id,
                    'shared_by_user_id',
                    'Share author belongs to another market.',
                );
            }
        }
    }
}
