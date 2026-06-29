<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class MarketplaceChat extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'market_space_id',
        'buyer_user_id',
        'product_id',
        'subject',
        'status',
        'last_message_at',
        'buyer_unread_count',
        'tenant_unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $chat): void {
            $chat->assertTenantBelongsToChatMarket();
            $chat->assertSpaceBelongsToChatMarket();
            $chat->assertProductBelongsToChatMarket();
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function marketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'product_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceChatMessage::class, 'chat_id');
    }

    private function assertTenantBelongsToChatMarket(): void
    {
        if (! $this->market_id || ! $this->tenant_id || ! Schema::hasTable('tenants')) {
            return;
        }

        $tenantMarketId = Tenant::query()
            ->whereKey((int) $this->tenant_id)
            ->value('market_id');

        if ($tenantMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $tenantMarketId,
            'tenant_id',
            'Marketplace chat tenant belongs to another market.',
        );
    }

    private function assertSpaceBelongsToChatMarket(): void
    {
        if (! $this->market_id || ! $this->market_space_id || ! Schema::hasTable('market_spaces')) {
            return;
        }

        $spaceMarketId = MarketSpace::query()
            ->whereKey((int) $this->market_space_id)
            ->value('market_id');

        if ($spaceMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $spaceMarketId,
            'market_space_id',
            'Marketplace chat space belongs to another market.',
        );
    }

    private function assertProductBelongsToChatMarket(): void
    {
        if (! $this->market_id || ! $this->product_id || ! Schema::hasTable('marketplace_products')) {
            return;
        }

        $productMarketId = MarketplaceProduct::query()
            ->whereKey((int) $this->product_id)
            ->value('market_id');

        if ($productMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $productMarketId,
            'product_id',
            'Marketplace chat product belongs to another market.',
        );
    }
}
