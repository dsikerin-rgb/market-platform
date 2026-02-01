<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationPayloadValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Market;

class Operation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'entity_type',
        'entity_id',
        'type',
        'effective_at',
        'effective_tz',
        'effective_month',
        'status',
        'payload',
        'comment',
        'created_by',
        'cancels_operation_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'effective_month' => 'date',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $operation->payload = OperationPayloadValidator::normalize($operation->type, $payload);

            if (! $operation->entity_type && isset($operation->payload['market_space_id'])) {
                $operation->entity_type = 'market_space';
            }

            if (! $operation->entity_id && isset($operation->payload['market_space_id'])) {
                $operation->entity_id = (int) $operation->payload['market_space_id'];
            }

            if (! $operation->created_by) {
                $operation->created_by = Auth::id();
            }

            $market = Market::query()->find($operation->market_id);
            $resolver = app(MarketPeriodResolver::class);
            $tz = $market?->timezone ?: (string) config('app.timezone', 'UTC');
            $operation->effective_tz = $operation->effective_tz ?: $tz;

            $effectiveAt = $operation->effective_at
                ? CarbonImmutable::parse($operation->effective_at)
                : ($market ? $resolver->marketNow($market) : CarbonImmutable::now($tz));

            $operation->effective_at = $effectiveAt->utc();

            if ($market) {
                $operation->effective_month = $resolver->resolveMarketPeriod($market, $effectiveAt->timezone($tz)->toDateString());
            } else {
                $operation->effective_month = CarbonImmutable::parse($effectiveAt->timezone($tz)->toDateString(), $tz)->startOfMonth();
            }
        });
    }
}
