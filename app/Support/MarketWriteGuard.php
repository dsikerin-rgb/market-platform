<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MarketWriteGuard
{
    public function __construct(
        private readonly MarketContext $context,
    ) {
    }

    public function enabled(): bool
    {
        return $this->context->writeGuardsEnabled();
    }

    public function isSameMarket(Model $left, Model $right): bool
    {
        $leftMarketId = $this->marketId($left);
        $rightMarketId = $this->marketId($right);

        return $leftMarketId !== null
            && $rightMarketId !== null
            && $leftMarketId === $rightMarketId;
    }

    public function assertSameMarket(
        Model $left,
        Model $right,
        string $field = 'market_id',
        ?string $message = null,
    ): void {
        $leftMarketId = $this->requireModelMarketId($left);
        $rightMarketId = $this->requireModelMarketId($right);

        if ($leftMarketId === $rightMarketId) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $message ?? 'Selected record belongs to another market.',
        ]);
    }

    public function assertBelongsToMarket(
        Model $record,
        int $marketId,
        string $field = 'market_id',
        ?string $message = null,
    ): void {
        if ($marketId <= 0) {
            throw new InvalidArgumentException('Market id must be a positive integer.');
        }

        if ($this->requireModelMarketId($record) === $marketId) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $message ?? 'Selected record belongs to another market.',
        ]);
    }

    public function assertBelongsToCurrentMarket(
        Model $record,
        string $field = 'market_id',
        ?string $message = null,
    ): void {
        $this->assertBelongsToMarket(
            $record,
            $this->context->requireMarketId(),
            $field,
            $message,
        );
    }

    private function requireModelMarketId(Model $record): int
    {
        $marketId = $this->marketId($record);

        if ($marketId === null) {
            throw new InvalidArgumentException('Record market_id must be a positive integer.');
        }

        return $marketId;
    }

    private function marketId(Model $record): ?int
    {
        $value = $record->getAttribute('market_id');

        if ($value === null || $value === '' || $value === false || ! is_numeric($value)) {
            return null;
        }

        $marketId = (int) $value;

        return $marketId > 0 ? $marketId : null;
    }
}
