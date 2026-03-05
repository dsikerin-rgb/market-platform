<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceFavorite extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'buyer_user_id',
        'product_id',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceProduct::class, 'product_id');
    }
}

