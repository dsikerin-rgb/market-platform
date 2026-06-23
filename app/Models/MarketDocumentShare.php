<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
