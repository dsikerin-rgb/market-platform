<?php
# app/Models/MarketSpaceType.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSpaceType extends Model
{
    use HasFactory;

    public const CATEGORY_COMMERCIAL = 'commercial';

    public const CATEGORY_SERVICE = 'service';

    public const CATEGORY_COMMON_AREA = 'common_area';

    public const CATEGORY_INFRASTRUCTURE = 'infrastructure';

    public const CATEGORY_COMMON_AREAS = [
        self::CATEGORY_COMMON_AREA,
        self::CATEGORY_INFRASTRUCTURE,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'name_ru',
        'code',
        'unit',
        'price',
        'currency',
        'category',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function isAccountingCategory(): bool
    {
        $category = trim((string) ($this->category ?? ''));

        if ($category === '') {
            return true;
        }

        return ! in_array($category, self::CATEGORY_COMMON_AREAS, true);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
