<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class MarketDocumentFolder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'owner_user_id',
        'created_by_user_id',
        'parent_id',
        'visibility',
        'name',
        'sort_order',
        'archived_at',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'owner_user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketDocumentFolder $folder): void {
            $user = Auth::user();

            if (! $folder->created_by_user_id && $user) {
                $folder->created_by_user_id = (int) $user->id;
            }

            if (! $folder->market_id && $user?->market_id) {
                $folder->market_id = (int) $user->market_id;
            }

            if ($folder->visibility === MarketDocument::VISIBILITY_PERSONAL && ! $folder->owner_user_id && $user) {
                $folder->owner_user_id = (int) $user->id;
            }
        });

        static::saving(function (MarketDocumentFolder $folder): void {
            $folder->visibility = MarketDocument::normalizeVisibility((string) $folder->visibility);
            $folder->name = trim((string) $folder->name);

            if ($folder->parent_id && Schema::hasTable('market_document_folders')) {
                $parent = self::query()->find((int) $folder->parent_id);

                if ($parent) {
                    if ($folder->market_id) {
                        app(MarketWriteGuard::class)->assertSameMarketId(
                            $folder->market_id,
                            $parent->market_id,
                            'parent_id',
                            'Parent folder belongs to another market.',
                        );
                    }

                    $folder->market_id = $parent->market_id;
                    $folder->visibility = $parent->visibility;
                    $folder->owner_user_id = $parent->owner_user_id;
                }
            }

            if ($folder->visibility === MarketDocument::VISIBILITY_SHARED) {
                $folder->owner_user_id = null;
            }

            $folder->assertOwnerBelongsToFolderMarket();
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MarketDocument::class, 'folder_id');
    }

    public function scopeVisibleFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if (! $user->market_id) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('market_id', (int) $user->market_id)
            ->where(function (Builder $inner) use ($user): void {
                $inner
                    ->where('visibility', MarketDocument::VISIBILITY_SHARED)
                    ->orWhere('owner_user_id', (int) $user->id);
            });
    }

    public function displayName(): string
    {
        $name = trim((string) $this->name);

        if ($this->parent && trim((string) $this->parent->name) !== '') {
            return trim((string) $this->parent->name) . ' / ' . $name;
        }

        return $name !== '' ? $name : 'Папка';
    }

    private function assertOwnerBelongsToFolderMarket(): void
    {
        if (! $this->owner_user_id || ! $this->market_id || ! Schema::hasTable('users')) {
            return;
        }

        $ownerMarketId = User::query()
            ->whereKey((int) $this->owner_user_id)
            ->value('market_id');

        if ($ownerMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $ownerMarketId,
            'owner_user_id',
            'Folder owner belongs to another market.',
        );
    }
}
