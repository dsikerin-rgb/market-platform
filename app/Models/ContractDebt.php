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
    public const CALCULATION_ACCOUNTS = ['62', '76.07'];

    /**
     * @var list<string>
     */
    public const CALCULATION_ACCOUNT_PREFIXES = ['62.'];

    /**
     * @var list<string>
     */
    public const SECURITY_DEPOSIT_ACCOUNTS = ['76.06'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'tenant_external_id',
        'contract_external_id',
        'period',
        'organization_external_id',
        'organization_name',
        'account',
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
        return static::latestStateQuery(
            marketId: $marketId,
            alias: $alias,
            identityColumns: static::currentStateIdentityColumns((new static())->getTable()),
            exactAccounts: static::CALCULATION_ACCOUNTS,
            accountPrefixes: static::CALCULATION_ACCOUNT_PREFIXES,
        );
    }

    public static function latestContractStateQuery(?int $marketId = null, string $alias = 'cd'): QueryBuilder
    {
        return static::latestStateQuery(
            marketId: $marketId,
            alias: $alias,
            identityColumns: static::latestContractStateIdentityColumns((new static())->getTable()),
            exactAccounts: static::CALCULATION_ACCOUNTS,
            accountPrefixes: static::CALCULATION_ACCOUNT_PREFIXES,
        );
    }

    public static function securityDepositStateQuery(?int $marketId = null, string $alias = 'cd'): QueryBuilder
    {
        return static::latestStateQuery(
            marketId: $marketId,
            alias: $alias,
            identityColumns: static::latestContractStateIdentityColumns((new static())->getTable()),
            exactAccounts: static::SECURITY_DEPOSIT_ACCOUNTS,
        );
    }

    public static function securityDepositAmountForTenant(int $marketId, int $tenantId): float
    {
        $table = (new static())->getTable();

        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'tenant_id')
            || ! Schema::hasColumn($table, 'debt_amount')
            || ! Schema::hasColumn($table, 'account')
        ) {
            return 0.0;
        }

        return (float) DB::query()
            ->fromSub(static::securityDepositStateQuery($marketId), 'cd')
            ->where('cd.tenant_id', $tenantId)
            ->sum('cd.debt_amount');
    }

    /**
     * @param list<string> $identityColumns
     * @param list<string>|null $exactAccounts
     * @param list<string> $accountPrefixes
     */
    private static function latestStateQuery(
        ?int $marketId,
        string $alias,
        array $identityColumns,
        ?array $exactAccounts = null,
        array $accountPrefixes = [],
    ): QueryBuilder
    {
        $table = (new static())->getTable();
        $base = DB::table("{$table} as {$alias}")->select("{$alias}.*");

        if ($marketId !== null) {
            $base->where("{$alias}.market_id", $marketId);
        }

        if (Schema::hasColumn($table, 'account') && $exactAccounts !== null) {
            static::applyAccountFilter($base, "{$alias}.account", $exactAccounts, $accountPrefixes);
        }

        $versionColumn = static::currentStateVersionColumn($table);

        if ($versionColumn === null || $identityColumns === []) {
            return $base;
        }

        $latestPerIdentity = DB::table("{$table} as snap");

        if ($marketId !== null) {
            $latestPerIdentity->where('snap.market_id', $marketId);
        }

        if (Schema::hasColumn($table, 'account') && $exactAccounts !== null) {
            static::applyAccountFilter($latestPerIdentity, 'snap.account', $exactAccounts, $accountPrefixes);
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
                if (in_array($column, ['organization_external_id', 'organization_name', 'account'], true)) {
                    $join->whereRaw("COALESCE({$alias}.{$column}, '') = COALESCE(latest_current.{$column}, '')");
                    continue;
                }

                $join->on("{$alias}.{$column}", '=', "latest_current.{$column}");
            }

            $join->on("{$alias}.{$versionColumn}", '=', 'latest_current.latest_version_value');
        });
    }

    /**
     * @param list<string> $exactAccounts
     * @param list<string> $accountPrefixes
     */
    private static function applyAccountFilter(QueryBuilder $query, string $column, array $exactAccounts, array $accountPrefixes = []): void
    {
        $query->where(function (QueryBuilder $accounts) use ($column, $exactAccounts, $accountPrefixes): void {
            if ($exactAccounts !== []) {
                $accounts->whereIn($column, $exactAccounts);
            }

            foreach ($accountPrefixes as $prefix) {
                $method = $exactAccounts === [] ? 'where' : 'orWhere';
                $accounts->{$method}($column, 'like', $prefix . '%');
            }
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
            'organization_external_id',
            'organization_name',
            'account',
        ];

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @return list<string>
     */
    private static function latestContractStateIdentityColumns(string $table): array
    {
        $columns = [
            'market_id',
            'tenant_external_id',
            'contract_external_id',
            'organization_external_id',
            'organization_name',
            'account',
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
