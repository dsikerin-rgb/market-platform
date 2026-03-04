<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketHolidayTaskLink extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'market_holiday_id',
        'task_id',
        'scenario_key',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'market_holiday_id' => 'integer',
        'task_id' => 'integer',
    ];

    public function marketHoliday(): BelongsTo
    {
        return $this->belongsTo(MarketHoliday::class, 'market_holiday_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}

