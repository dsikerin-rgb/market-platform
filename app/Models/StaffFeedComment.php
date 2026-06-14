<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffFeedComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_feed_post_id',
        'author_user_id',
        'body',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(StaffFeedPost::class, 'staff_feed_post_id');
    }
}
