<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}

