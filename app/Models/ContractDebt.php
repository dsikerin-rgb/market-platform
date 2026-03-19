<?php
# app/Models/ContractDebt.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContractDebt extends Model
{
    protected $table = 'contract_debts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'tenant_external_id',
        'contract_external_id',
        'period',
        'accrued_amount',
        'paid_amount',
        'debt_amount',
        'calculated_at',
        'source',
        'currency',
        'hash',
        'raw_payload',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'tenant_id' => 'integer',
        'accrued_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'calculated_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForMarket($query, int $marketId)
    {
        return $query->where('market_id', $marketId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLatestSnapshot($query)
    {
        return $query->orderByDesc('calculated_at');
    }

    public static function currentStateQuery(?int $marketId = null, string $alias = 'cd'): QueryBuilder
    {
        $table = (new static())->getTable();
        $base = DB::table("{$table} as {$alias}")->select("{$alias}.*");

        if ($marketId !== null) {
            $base->where("{$alias}.market_id", $marketId);
        }

        $versionColumn = static::currentStateVersionColumn($table);
        $identityColumns = static::currentStateIdentityColumns($table);

        if ($versionColumn === null || $identityColumns === []) {
            return $base;
        }

        $latestPerIdentity = DB::table("{$table} as snap");

        if ($marketId !== null) {
            $latestPerIdentity->where('snap.market_id', $marketId);
        }

        foreach ($identityColumns as $column) {
            $latestPerIdentity->addSelect("snap.{$column}");
        }

        $latestPerIdentity
            ->selectRaw("MAX(snap.{$versionColumn}) as latest_version_value")
            ->groupBy(array_map(
                static fn (string $column): string => "snap.{$column}",
                $identityColumns,
            ));

        return $base->joinSub($latestPerIdentity, 'latest_current', function ($join) use ($alias, $identityColumns, $versionColumn): void {
            foreach ($identityColumns as $column) {
                $join->on("{$alias}.{$column}", '=', "latest_current.{$column}");
            }

            $join->on("{$alias}.{$versionColumn}", '=', 'latest_current.latest_version_value');
        });
    }

    /**
     * @return list<string>
     */
    private static function currentStateIdentityColumns(string $table): array
    {
        $columns = [
            'market_id',
            'tenant_external_id',
            'contract_external_id',
            'period',
        ];

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
    }

    private static function currentStateVersionColumn(string $table): ?string
    {
        foreach (['calculated_at', 'created_at', 'period'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
