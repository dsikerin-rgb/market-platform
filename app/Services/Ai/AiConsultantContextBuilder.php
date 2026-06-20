<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ContractDebt;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiConsultantContextBuilder
{
    /**
     * Build a bounded read-only database context for the current user and market.
     *
     * @return array<string, mixed>
     */
    public function build(User $user, int $marketId, string $question): array
    {
        if ($marketId <= 0) {
            return [
                'scope' => [
                    'market_id' => null,
                    'note' => 'Рынок не выбран. Для консультации по данным нужен текущий рынок.',
                ],
                'question_terms' => $this->terms($question),
                'overview' => [],
                'matches' => [],
                'attention' => [],
            ];
        }

        $terms = $this->terms($question);

        return [
            'scope' => [
                'market_id' => $marketId,
                'user_id' => (int) $user->id,
                'role' => $this->userRole($user),
                'user_name' => $this->friendlyUserName($user),
            ],
            'question_terms' => $terms,
            'overview' => $this->overview($marketId),
            'matches' => [
                'tenants' => $this->matchingTenants($marketId, $terms),
                'spaces' => $this->matchingSpaces($marketId, $terms),
                'contracts' => $this->matchingContracts($marketId, $terms),
                'tickets' => $this->matchingTickets($marketId, $terms),
            ],
            'attention' => [
                'debt_tenants' => $this->debtTenants($marketId),
                'top_debt_tenants' => $this->topDebtTenants($marketId),
                'rent_rate_extremes' => $this->rentRateExtremes($marketId),
                'map_review_spaces' => $this->mapReviewSpaces($marketId),
                'open_tickets' => $this->openTickets($marketId),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function terms(string $question): array
    {
        $tokens = preg_split('/[^\pL\pN\-]+/u', Str::lower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($tokens)
            ->map(static fn (string $term): string => trim($term))
            ->filter(static fn (string $term): bool => $term !== '' && (mb_strlen($term) >= 2 || preg_match('/^\d+$/u', $term) === 1))
            ->reject(static fn (string $term): bool => in_array($term, [
                'что', 'как', 'где', 'или', 'для', 'это', 'есть', 'нет', 'по', 'на', 'за', 'из', 'от', 'до', 'при',
                'the', 'and', 'for', 'with',
            ], true))
            ->unique()
            ->values()
            ->take(8)
            ->all();
    }

    private function userRole(User $user): string
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return 'super-admin';
        }

        if (method_exists($user, 'getRoleNames')) {
            return $user->getRoleNames()->implode(',');
        }

        return 'staff';
    }

    private function friendlyUserName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return trim((string) ($parts[0] ?? ''));
    }

    /**
     * @return array<string, int>
     */
    private function overview(int $marketId): array
    {
        return [
            'tenants_total' => $this->countForMarket(Tenant::query(), $marketId),
            'tenants_active' => $this->countForMarket(Tenant::query()->where('is_active', true), $marketId),
            'spaces_total' => $this->countForMarket(MarketSpace::query(), $marketId),
            'spaces_active' => $this->countForMarket(MarketSpace::query()->where('is_active', true), $marketId),
            'spaces_occupied' => $this->countForMarket(MarketSpace::query()->where('status', 'occupied'), $marketId),
            'spaces_vacant' => $this->countForMarket(MarketSpace::query()->whereIn('status', ['free', 'vacant']), $marketId),
            'contracts_active' => $this->countForMarket(TenantContract::query()->where('is_active', true), $marketId),
            'open_tickets' => $this->countForMarket(Ticket::query()->whereNotIn('status', ['resolved', 'closed', 'cancelled']), $marketId),
        ];
    }

    private function countForMarket(Builder $query, int $marketId): int
    {
        return (int) $query->where('market_id', $marketId)->count();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function matchingTenants(int $marketId, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        return Tenant::query()
            ->withCount(['spaces', 'contracts', 'accruals'])
            ->where('market_id', $marketId)
            ->where(fn (Builder $query): Builder => $this->applyTerms($query, ['name', 'short_name', 'inn', 'phone', 'email'], $terms))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => (int) $tenant->id,
                'name' => $tenant->display_name,
                'full_name' => (string) $tenant->name,
                'inn' => (string) ($tenant->inn ?? ''),
                'status' => (string) ($tenant->status ?? ''),
                'is_active' => (bool) $tenant->is_active,
                'debt_status' => (string) ($tenant->debt_status ?? ''),
                'debt_label' => $tenant->debt_status_label,
                'spaces_count' => (int) $tenant->spaces_count,
                'contracts_count' => (int) $tenant->contracts_count,
                'accruals_count' => (int) $tenant->accruals_count,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function matchingSpaces(int $marketId, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        return MarketSpace::query()
            ->with(['tenant:id,name,short_name'])
            ->withCount(['mapShapes', 'tenantContracts'])
            ->where('market_id', $marketId)
            ->where(fn (Builder $query): Builder => $this->applyTerms($query, ['number', 'display_name', 'code', 'notes'], $terms))
            ->orderBy('number')
            ->limit(10)
            ->get()
            ->map(fn (MarketSpace $space): array => [
                'id' => (int) $space->id,
                'number' => (string) ($space->number ?? ''),
                'display_name' => (string) ($space->display_name ?? ''),
                'status' => (string) ($space->status ?? ''),
                'map_review_status' => (string) ($space->map_review_status ?? ''),
                'area_sqm' => (string) ($space->area_sqm ?? ''),
                'tenant' => $space->tenant?->display_name,
                'tenant_id' => $space->tenant_id ? (int) $space->tenant_id : null,
                'map_shapes_count' => (int) $space->map_shapes_count,
                'contracts_count' => (int) $space->tenant_contracts_count,
                'group_role' => (string) ($space->space_group_role ?? ''),
                'group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function matchingContracts(int $marketId, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        return TenantContract::query()
            ->with(['tenant:id,name,short_name', 'marketSpace:id,number,display_name'])
            ->where('market_id', $marketId)
            ->where(fn (Builder $query): Builder => $this->applyTerms($query, ['number', 'external_id', 'notes'], $terms))
            ->orderByDesc('is_active')
            ->orderByDesc('starts_at')
            ->limit(8)
            ->get()
            ->map(fn (TenantContract $contract): array => [
                'id' => (int) $contract->id,
                'number' => (string) ($contract->number ?? ''),
                'status' => (string) ($contract->status ?? ''),
                'is_active' => (bool) $contract->is_active,
                'starts_at' => $contract->starts_at?->toDateString(),
                'ends_at' => $contract->ends_at?->toDateString(),
                'tenant' => $contract->tenant?->display_name,
                'tenant_id' => $contract->tenant_id ? (int) $contract->tenant_id : null,
                'space' => $contract->marketSpace
                    ? trim((string) ($contract->marketSpace->number.' '.$contract->marketSpace->display_name))
                    : null,
                'market_space_id' => $contract->market_space_id ? (int) $contract->market_space_id : null,
                'space_mapping_mode' => $contract->effectiveSpaceMappingMode(),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function matchingTickets(int $marketId, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        return Ticket::query()
            ->with(['tenant:id,name,short_name'])
            ->where('market_id', $marketId)
            ->where(fn (Builder $query): Builder => $this->applyTerms($query, ['subject', 'description'], $terms))
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'status' => (string) $ticket->status,
                'priority' => (string) $ticket->priority,
                'tenant' => $ticket->tenant?->display_name,
                'updated_at' => $ticket->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function debtTenants(int $marketId): array
    {
        return Tenant::query()
            ->where('market_id', $marketId)
            ->whereIn('debt_status', ['orange', 'red'])
            ->orderByRaw("CASE debt_status WHEN 'red' THEN 0 WHEN 'orange' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => (int) $tenant->id,
                'name' => $tenant->display_name,
                'debt_status' => (string) $tenant->debt_status,
                'debt_label' => $tenant->debt_status_label,
                'updated_at' => $tenant->debt_status_updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topDebtTenants(int $marketId): array
    {
        if (
            ! Schema::hasTable('contract_debts')
            || ! Schema::hasColumn('contract_debts', 'market_id')
            || ! Schema::hasColumn('contract_debts', 'tenant_id')
            || ! Schema::hasColumn('contract_debts', 'debt_amount')
        ) {
            return [];
        }

        return DB::query()
            ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
            ->leftJoin('tenants as t', 't.id', '=', 'cd.tenant_id')
            ->where('cd.market_id', $marketId)
            ->where('cd.debt_amount', '>', 0)
            ->selectRaw('cd.tenant_id')
            ->selectRaw("COALESCE(MAX(t.short_name), MAX(t.name), 'Арендатор #' || cd.tenant_id) as tenant_name")
            ->selectRaw('SUM(cd.debt_amount) as debt_amount')
            ->selectRaw('MAX(cd.period) as latest_period')
            ->selectRaw('COUNT(DISTINCT cd.contract_external_id) as contracts_count')
            ->groupBy('cd.tenant_id')
            ->orderByDesc(DB::raw('SUM(cd.debt_amount)'))
            ->limit(10)
            ->get()
            ->map(static fn (object $row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'tenant_name' => (string) $row->tenant_name,
                'debt_amount_rub' => round((float) $row->debt_amount, 2),
                'latest_period' => (string) ($row->latest_period ?? ''),
                'contracts_count' => (int) $row->contracts_count,
                'source' => 'contract_debts latest current state',
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rentRateExtremes(int $marketId): array
    {
        $result = [];

        if (
            Schema::hasTable('tenant_accruals')
            && Schema::hasColumn('tenant_accruals', 'market_id')
            && Schema::hasColumn('tenant_accruals', 'period')
            && Schema::hasColumn('tenant_accruals', 'rent_rate')
        ) {
            $latestPeriod = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->whereNotNull('rent_rate')
                ->where('rent_rate', '>', 0)
                ->max('period');

            if ($latestPeriod !== null) {
                $latestBase = DB::table('tenant_accruals as ta')
                    ->leftJoin('tenants as t', 't.id', '=', 'ta.tenant_id')
                    ->leftJoin('market_spaces as ms', 'ms.id', '=', 'ta.market_space_id')
                    ->where('ta.market_id', $marketId)
                    ->where('ta.period', $latestPeriod)
                    ->whereNotNull('ta.rent_rate')
                    ->where('ta.rent_rate', '>', 0)
                    ->selectRaw('ta.tenant_id')
                    ->selectRaw("COALESCE(NULLIF(MAX(t.short_name), ''), NULLIF(MAX(t.name), ''), 'Арендатор') as tenant_name")
                    ->selectRaw('ta.market_space_id')
                    ->selectRaw('MAX(COALESCE(ms.number, ta.source_place_code)) as space_number')
                    ->selectRaw('MAX(COALESCE(ms.display_name, ta.source_place_name)) as space_name')
                    ->selectRaw('MIN(ta.rent_rate) as rent_rate')
                    ->selectRaw('MAX(ta.area_sqm) as area_sqm')
                    ->selectRaw('MAX(ta.rent_amount) as rent_amount')
                    ->selectRaw('MAX(ta.period) as period')
                    ->groupBy('ta.tenant_id', 'ta.market_space_id');

                $result['latest_accrual_period'] = (string) $latestPeriod;
                $result['lowest_latest_accrual_rates'] = $this->rentRateRows((clone $latestBase)->orderBy('rent_rate')->limit(5)->get(), 'latest_accruals');
                $result['highest_latest_accrual_rates'] = $this->rentRateRows((clone $latestBase)->orderByDesc('rent_rate')->limit(5)->get(), 'latest_accruals');
            }
        }

        if (
            Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'market_id')
            && Schema::hasColumn('market_space_tenant_bindings', 'ended_at')
            && Schema::hasColumn('market_space_tenant_bindings', 'rent_rate')
        ) {
            $bindingBase = DB::table('market_space_tenant_bindings as b')
                ->leftJoin('tenants as t', 't.id', '=', 'b.tenant_id')
                ->leftJoin('market_spaces as ms', 'ms.id', '=', 'b.market_space_id')
                ->where('b.market_id', $marketId)
                ->whereNull('b.ended_at')
                ->whereNotNull('b.rent_rate')
                ->where('b.rent_rate', '>', 0)
                ->selectRaw('b.tenant_id')
                ->selectRaw("COALESCE(NULLIF(t.short_name, ''), NULLIF(t.name, ''), 'Арендатор') as tenant_name")
                ->selectRaw('b.market_space_id')
                ->selectRaw('ms.number as space_number')
                ->selectRaw('ms.display_name as space_name')
                ->selectRaw('b.rent_rate')
                ->selectRaw('b.area_sqm')
                ->selectRaw('NULL as rent_amount')
                ->selectRaw('NULL as period');

            $result['lowest_active_binding_rates'] = $this->rentRateRows((clone $bindingBase)->orderBy('b.rent_rate')->limit(5)->get(), 'active_bindings');
            $result['highest_active_binding_rates'] = $this->rentRateRows((clone $bindingBase)->orderByDesc('b.rent_rate')->limit(5)->get(), 'active_bindings');
        }

        if (
            Schema::hasTable('market_spaces')
            && Schema::hasColumn('market_spaces', 'market_id')
            && Schema::hasColumn('market_spaces', 'rent_rate_value')
        ) {
            $spaceBase = DB::table('market_spaces as ms')
                ->leftJoin('tenants as t', 't.id', '=', 'ms.tenant_id')
                ->where('ms.market_id', $marketId)
                ->whereNotNull('ms.tenant_id')
                ->whereNotNull('ms.rent_rate_value')
                ->where('ms.rent_rate_value', '>', 0)
                ->selectRaw('ms.tenant_id')
                ->selectRaw("COALESCE(NULLIF(t.short_name, ''), NULLIF(t.name, ''), 'Арендатор') as tenant_name")
                ->selectRaw('ms.id as market_space_id')
                ->selectRaw('ms.number as space_number')
                ->selectRaw('ms.display_name as space_name')
                ->selectRaw('ms.rent_rate_value as rent_rate')
                ->selectRaw('ms.rent_rate_unit')
                ->selectRaw('ms.area_sqm')
                ->selectRaw('NULL as rent_amount')
                ->selectRaw('NULL as period');

            if (Schema::hasColumn('market_spaces', 'is_active')) {
                $spaceBase->where('ms.is_active', true);
            }

            $result['lowest_space_card_rates'] = $this->rentRateRows((clone $spaceBase)->orderBy('ms.rent_rate_value')->limit(5)->get(), 'space_cards');
            $result['highest_space_card_rates'] = $this->rentRateRows((clone $spaceBase)->orderByDesc('ms.rent_rate_value')->limit(5)->get(), 'space_cards');
        }

        return collect($result)
            ->reject(static fn (mixed $value): bool => $value === [] || $value === null)
            ->all();
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function rentRateRows(iterable $rows, string $source): array
    {
        return collect($rows)
            ->map(static function (object $row) use ($source): array {
                $space = trim((string) ($row->space_number ?? '').' '.(string) ($row->space_name ?? ''));

                return [
                    'tenant_id' => $row->tenant_id !== null ? (int) $row->tenant_id : null,
                    'tenant_name' => (string) ($row->tenant_name ?? ''),
                    'market_space_id' => $row->market_space_id !== null ? (int) $row->market_space_id : null,
                    'space' => $space !== '' ? $space : null,
                    'rent_rate_rub' => round((float) $row->rent_rate, 2),
                    'rent_rate_unit' => (string) ($row->rent_rate_unit ?? ''),
                    'period' => $row->period !== null ? (string) $row->period : null,
                    'area_sqm' => $row->area_sqm !== null ? round((float) $row->area_sqm, 2) : null,
                    'rent_amount_rub' => $row->rent_amount !== null ? round((float) $row->rent_amount, 2) : null,
                    'source' => $source,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapReviewSpaces(int $marketId): array
    {
        return MarketSpace::query()
            ->with(['tenant:id,name,short_name'])
            ->where('market_id', $marketId)
            ->whereNotNull('map_review_status')
            ->whereNotIn('map_review_status', ['matched', ''])
            ->orderByDesc('map_reviewed_at')
            ->limit(8)
            ->get()
            ->map(fn (MarketSpace $space): array => [
                'id' => (int) $space->id,
                'number' => (string) ($space->number ?? ''),
                'display_name' => (string) ($space->display_name ?? ''),
                'map_review_status' => (string) ($space->map_review_status ?? ''),
                'tenant' => $space->tenant?->display_name,
                'reviewed_at' => $space->map_reviewed_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTickets(int $marketId): array
    {
        return Ticket::query()
            ->with(['tenant:id,name,short_name'])
            ->where('market_id', $marketId)
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'status' => (string) $ticket->status,
                'priority' => (string) $ticket->priority,
                'tenant' => $ticket->tenant?->display_name,
                'updated_at' => $ticket->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $terms
     */
    private function applyTerms(Builder $query, array $columns, array $terms): Builder
    {
        $table = $query->getModel()->getTable();
        $availableColumns = collect($columns)
            ->filter(static fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values()
            ->all();

        if ($availableColumns === [] || $terms === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($terms as $term) {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

            foreach ($availableColumns as $column) {
                $query->orWhereRaw('LOWER('.$column.') LIKE ?', [$pattern]);
            }
        }

        return $query;
    }
}
