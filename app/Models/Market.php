<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'code',
        'address',
        'timezone',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(MarketLocation::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(MarketSpace::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
