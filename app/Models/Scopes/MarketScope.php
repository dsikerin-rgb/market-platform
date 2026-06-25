<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\MarketContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class MarketScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var MarketContext $context */
        $context = app(MarketContext::class);

        if (! $context->scopeEnabled()) {
            return;
        }

        $marketId = $context->currentMarketId();

        if ($marketId === null) {
            if ($context->strictMissingContext()) {
                $context->requireMarketId();
            }

            return;
        }

        $builder->where($this->qualifiedMarketColumn($model), $marketId);
    }

    private function qualifiedMarketColumn(Model $model): string
    {
        return $model->qualifyColumn($this->marketColumn($model));
    }

    private function marketColumn(Model $model): string
    {
        if (method_exists($model, 'marketScopeColumn')) {
            $column = $model->marketScopeColumn();

            if (is_string($column) && trim($column) !== '') {
                return $column;
            }
        }

        return 'market_id';
    }
}
