<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurgeMergedTenantsCommand extends Command
{
    protected $signature = 'tenants:purge-merged
        {--execute : Выполнить удаление (без опции работает dry-run)}
        {--limit=500 : Сколько кандидатов проверять за один запуск}';

    protected $description = 'Удаляет уже слитые merged tenants без оставшихся ссылок';

    /**
     * @var list<array{table:string,column:string}>
     */
    private array $referenceTargets = [
        ['table' => 'market_spaces', 'column' => 'tenant_id'],
        ['table' => 'tenant_contracts', 'column' => 'tenant_id'],
        ['table' => 'tenant_requests', 'column' => 'tenant_id'],
        ['table' => 'tenant_accruals', 'column' => 'tenant_id'],
        ['table' => 'tenant_documents', 'column' => 'tenant_id'],
        ['table' => 'tickets', 'column' => 'tenant_id'],
        ['table' => 'users', 'column' => 'tenant_id'],
        ['table' => 'contract_debts', 'column' => 'tenant_id'],
        ['table' => 'market_space_tenant_histories', 'column' => 'old_tenant_id'],
        ['table' => 'market_space_tenant_histories', 'column' => 'new_tenant_id'],
        ['table' => 'tenant_showcases', 'column' => 'tenant_id'],
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = Tenant::query()
            ->where('is_active', false)
            ->where('notes', 'like', '%merged_into_tenant_id=%')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'notes']);

        if ($candidates->isEmpty()) {
            $this->info('No merged inactive tenants found');
            return self::SUCCESS;
        }

        $purgeIds = [];

        foreach ($candidates as $tenant) {
            $counts = $this->collectReferenceCounts((int) $tenant->id);
            $total = array_sum($counts);

            if ($total === 0) {
                $purgeIds[] = (int) $tenant->id;
                $this->line("purge-ready id={$tenant->id} name=" . (string) ($tenant->name ?? ''));
                continue;
            }

            $parts = [];
            foreach ($counts as $key => $count) {
                if ($count > 0) {
                    $parts[] = "{$key}={$count}";
                }
            }

            $this->warn("skip id={$tenant->id} name=" . (string) ($tenant->name ?? '') . ' refs: ' . implode(', ', $parts));
        }

        if (! $execute) {
            $this->info('DRY RUN: nothing deleted');
            $this->info('ready_to_purge=' . count($purgeIds));
            return self::SUCCESS;
        }

        if ($purgeIds === []) {
            $this->info('Nothing to delete');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            Tenant::query()->whereIn('id', $purgeIds)->delete();
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Purge failed, rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Deleted merged tenants: ' . implode(', ', $purgeIds));

        return self::SUCCESS;
    }

    /**
     * @return array<string,int>
     */
    private function collectReferenceCounts(int $tenantId): array
    {
        $counts = [];

        foreach ($this->referenceTargets as $target) {
            $table = $target['table'];
            $column = $target['column'];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $counts["{$table}.{$column}"] = (int) DB::table($table)->where($column, $tenantId)->count();
        }

        return $counts;
    }
}
