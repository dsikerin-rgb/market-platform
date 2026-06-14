<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffFeedPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'author_user_id',
        'type',
        'body',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
