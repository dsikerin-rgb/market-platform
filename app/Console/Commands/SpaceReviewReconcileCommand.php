<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\SpaceReviewDecision;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SpaceReviewReconcileCommand extends Command
{
    protected $signature = 'space-review:reconcile
        {--market= : Market ID filter (optional)}
        {--limit=50 : Maximum number of operations to process}
        {--json : Output machine-readable JSON}
        {--apply : Actually apply auto-closes (default: read-only)}
        {--max-auto-closes=10 : Maximum number of operations to auto-close (only with --apply)}';

    protected $description = 'Reconcile tenant_changed_on_site operations with exact bindings (use --apply to execute)';

    public function handle(): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $limit = (int) ($this->option('limit') ?? 50);
        $json = (bool) $this->option('json');
        $apply = (bool) $this->option('apply');
        $maxAutoCloses = (int) ($this->option('max-auto-closes') ?? 10);

        if ($apply) {
            $this->warn("⚠️  WARNING: --apply is enabled. This WILL write to the database.");
            $this->warn("   Max auto-closes: {$maxAutoCloses}");
            $this->newLine();
        }

        if ($json) {
            $this->runJsonMode($marketId, $limit, $apply, $maxAutoCloses);
        } else {
            $this->runTableMode($marketId, $limit, $apply, $maxAutoCloses);
        }

        return self::SUCCESS;
    }

    private function runTableMode(?int $marketId, int $limit, bool $apply, int $maxAutoCloses): void
    {
        $this->info('=== SPACE REVIEW RECONCILIATION ===');
        $this->newLine();

        if ($marketId) {
            $this->line("Market ID: {$marketId}");
        }
        $this->line("Limit: {$limit}");
        $this->line("Mode: " . ($apply ? "APPLY (will write to DB)" : "read-only"));
        if ($apply) {
            $this->line("Max auto-closes: {$maxAutoCloses}");
        }
        $this->newLine();

        $observedOps = $this->fetchObservedOperations($marketId, $limit);
        $total = count($observedOps);

        if ($total === 0) {
            $this->info('No observed tenant_changed_on_site operations found.');
            return;
        }

        $this->info("Found {$total} observed operation(s):\n");

        $candidates = [];
        $withExact = 0;
        $matched = 0;
        $appliedCount = 0;

        $headers = ['Operation ID', 'Space ID', 'Observed Tenant', 'Exact Tenant', 'Match', 'Binding ID', 'Status'];
        $rows = [];

        foreach ($observedOps as $op) {
            if ($apply && $appliedCount >= $maxAutoCloses) {
                $this->newLine();
                $this->warn("⚠️  Reached max-auto-closes limit ({$maxAutoCloses}). Stopping.");
                break;
            }

            $spaceId = (int) ($op->payload['market_space_id'] ?? $op->entity_id ?? 0);
            $observedTenant = (string) ($op->payload['observed_tenant_name'] ?? 'N/A');

            $exactBinding = $this->findExactBinding($spaceId, $op->market_id, $observedTenant);

            $match = false;
            $exactTenant = 'N/A';
            $bindingId = null;
            $status = '✗';

            if ($exactBinding) {
                $withExact++;
                $exactTenant = $exactBinding->tenant?->name ?? 'N/A';
                $bindingId = $exactBinding->id;

                if ($this->tenantNamesMatch($observedTenant, $exactTenant)) {
                    $match = true;
                    $matched++;

                    if ($apply) {
                        $this->applyAutoClose($op, $exactBinding, $spaceId);
                        $appliedCount++;
                        $status = '✓ applied';
                    } else {
                        $status = '✓ candidate';
                    }

                    $candidates[] = [
                        'operation_id' => $op->id,
                        'space_id' => $spaceId,
                        'observed' => $observedTenant,
                        'exact' => $exactTenant,
                        'binding_id' => $bindingId,
                    ];
                }
            }

            $rows[] = [
                $op->id,
                $spaceId,
                $observedTenant,
                $exactTenant,
                $match ? '✓' : '✗',
                $bindingId ?? '-',
                $status,
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();

        $this->info('=== SUMMARY ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total observed operations', $total],
                ['With exact binding', $withExact],
                ['Tenant matches', $matched],
                ['Auto-close candidates', count($candidates)],
                ['Actually applied', $apply ? $appliedCount : '-'],
            ]
        );

        if (count($candidates) > 0 && !$apply) {
            $this->newLine();
            $this->info("✓ {$matched} candidate(s) found. Run with --apply to close them.");
        }

        if ($apply && $appliedCount > 0) {
            $this->newLine();
            $this->info("✓ Successfully auto-closed {$appliedCount} operation(s).");
        }
    }

    private function runJsonMode(?int $marketId, int $limit, bool $apply, int $maxAutoCloses): void
    {
        $observedOps = $this->fetchObservedOperations($marketId, $limit);
        $total = count($observedOps);

        $candidates = [];
        $withExact = 0;
        $matched = 0;
        $appliedCount = 0;

        foreach ($observedOps as $op) {
            if ($apply && $appliedCount >= $maxAutoCloses) {
                break;
            }

            $spaceId = (int) ($op->payload['market_space_id'] ?? $op->entity_id ?? 0);
            $observedTenant = (string) ($op->payload['observed_tenant_name'] ?? 'N/A');

            $exactBinding = $this->findExactBinding($spaceId, $op->market_id, $observedTenant);

            if ($exactBinding) {
                $withExact++;
                $exactTenant = $exactBinding->tenant?->name ?? 'N/A';

                if ($this->tenantNamesMatch($observedTenant, $exactTenant)) {
                    $matched++;

                    if ($apply) {
                        $this->applyAutoClose($op, $exactBinding, $spaceId);
                        $appliedCount++;
                    }

                    $candidates[] = [
                        'operation_id' => (int) $op->id,
                        'space_id' => $spaceId,
                        'observed' => $observedTenant,
                        'exact' => $exactTenant,
                        'binding_id' => (int) $exactBinding->id,
                        'match' => true,
                        'applied' => $apply,
                    ];
                }
            }
        }

        $output = [
            'summary' => [
                'total' => $total,
                'with_exact' => $withExact,
                'matched' => $matched,
                'auto_close_candidates' => count($candidates),
                'actually_applied' => $apply ? $appliedCount : 0,
            ],
            'candidates' => $candidates,
        ];

        $this->line(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Operation>
     */
    private function fetchObservedOperations(?int $marketId, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        $query = Operation::query()
            ->where('type', 'space_review')
            ->where('payload->decision', SpaceReviewDecision::TENANT_CHANGED_ON_SITE)
            ->where('status', 'observed')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        return $query->get();
    }

    private function findExactBinding(int $spaceId, int $marketId, string $observedTenant): ?MarketSpaceTenantBinding
    {
        $obsNorm = $this->normalizeTenantName($observedTenant);

        if ($obsNorm === '') {
            return null;
        }

        return MarketSpaceTenantBinding::query()
            ->where('market_space_id', $spaceId)
            ->where('market_id', $marketId)
            ->where('binding_type', 'exact')
            ->where(function ($query) {
                $query->whereNull('ended_at')
                      ->orWhere('resolution_reason', 'superseded_by_contract_binding');
            })
            ->whereHas('tenant', function ($query) use ($obsNorm) {
                $query->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(LOWER(name), \' \', \'\'), \'.\', \'\'), \',\', \'\')) = ?', [$obsNorm])
                      ->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(LOWER(name), \' \', \'\'), \'.\', \'\'), \',\', \'\')) LIKE ?', ["%{$obsNorm}%"]);
            })
            ->first();
    }

    private function tenantNamesMatch(string $observed, string $exact): bool
    {
        $obsNorm = $this->normalizeTenantName($observed);
        $exactNorm = $this->normalizeTenantName($exact);

        if ($obsNorm === '' || $exactNorm === '') {
            return false;
        }

        return $obsNorm === $exactNorm
            || strpos($exactNorm, $obsNorm) !== false
            || strpos($obsNorm, $exactNorm) !== false;
    }

    private function normalizeTenantName(string $name): string
    {
        return mb_strtolower(
            preg_replace('/[^а-яа-ёa-z0-9]/u', '', $name)
        );
    }

    private function applyAutoClose(Operation $operation, MarketSpaceTenantBinding $binding, int $spaceId): void
    {
        DB::transaction(function () use ($operation, $binding, $spaceId) {
            // 1. Получаем текущий payload как массив
            $currentPayload = $operation->payload ?? [];
            if (is_string($currentPayload)) {
                $currentPayload = json_decode($currentPayload, true) ?? [];
            }

            // 2. Добавляем поля авто-закрытия
            $newPayload = array_merge($currentPayload, [
                'auto_closed_by_reconciliation' => true,
                'auto_close_at' => now()->toDateTimeString(),
                'auto_close_binding_id' => $binding->id,
            ]);

            // 3. Обновляем operation
            DB::table('operations')->where('id', $operation->id)->update([
                'status' => 'applied',
                'payload' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // 4. Обновляем статус места на changed_tenant (стандартный статус для tenant_changed_on_site)
            DB::table('market_spaces')->where('id', $spaceId)->update([
                'map_review_status' => 'changed_tenant',
                'map_reviewed_at' => now(),
                'map_reviewed_by' => null,
            ]);

            // 5. Логирование
            \Log::info('Auto-closed tenant_changed_on_site', [
                'operation_id' => $operation->id,
                'space_id' => $operation->entity_id,
                'binding_id' => $binding->id,
                'tenant_match' => $currentPayload['observed_tenant_name'] ?? 'N/A',
            ]);

            $this->line("  → Applied: operation #{$operation->id} (space #{$spaceId}, binding #{$binding->id})");
        });
    }
}

