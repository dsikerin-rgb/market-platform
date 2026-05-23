<?php
# app/Http/Controllers/Admin/TenantMergePreflightController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class TenantMergePreflightController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'source_tenant_id' => ['required', 'integer', 'min:1', 'different:canonical_tenant_id'],
            'canonical_tenant_id' => ['required', 'integer', 'min:1'],
        ]);

        $sourceTenantId = (int) $validated['source_tenant_id'];
        $canonicalTenantId = (int) $validated['canonical_tenant_id'];

        /** @var Tenant|null $sourceTenant */
        $sourceTenant = Tenant::query()->find($sourceTenantId);
        /** @var Tenant|null $canonicalTenant */
        $canonicalTenant = Tenant::query()->find($canonicalTenantId);

        if (! $sourceTenant || ! $canonicalTenant) {
            return response()->json([
                'ok' => false,
                'message' => 'Один из арендаторов не найден. Обновите страницу и попробуйте ещё раз.',
            ], 404);
        }

        if ((int) $sourceTenant->market_id !== (int) $canonicalTenant->market_id) {
            return response()->json([
                'ok' => false,
                'message' => 'Арендаторы относятся к разным рынкам. Слияние запрещено.',
            ], 422);
        }

        $exitCode = Artisan::call('tenants:merge', [
            'from' => $sourceTenantId,
            'to' => $canonicalTenantId,
            '--dry-run' => true,
            '--preflight-limit' => 20,
        ]);

        $output = Artisan::output();
        $summary = $this->summarizeOutput($output, $sourceTenantId, $canonicalTenantId);

        return response()->json([
            'ok' => $exitCode === 0,
            'message' => $exitCode === 0
                ? 'Проверка прошла. Данные не изменены.'
                : 'Проверка нашла проблему. Слияние сейчас запускать нельзя.',
            'summary' => $summary,
            'raw_output' => $output,
        ], $exitCode === 0 ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeOutput(string $output, int $sourceTenantId, int $canonicalTenantId): array
    {
        $lines = preg_split('/\R/u', $output) ?: [];
        $referenceCounts = [];
        $nonZeroTransfers = [];
        $aliasCount = 0;
        $accrualConflictCount = 0;
        $showcaseAction = 'none';
        $totalReferences = 0;

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if (preg_match('/^-\s+([a-z_]+\.[a-z_]+):\s+(\d+)$/u', $line, $match)) {
                $key = (string) $match[1];
                $count = (int) $match[2];
                $label = $this->referenceLabel($key);

                $referenceCounts[] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => $count,
                ];

                $totalReferences += $count;

                if ($count > 0) {
                    $nonZeroTransfers[] = [
                        'key' => $key,
                        'label' => $label,
                        'count' => $count,
                    ];
                }

                continue;
            }

            if (preg_match('/^Showcase action:\s+(.+)$/u', $line, $match)) {
                $showcaseAction = trim((string) $match[1]);
                continue;
            }

            if (preg_match('/^External aliases to keep for future 1C imports:\s+(\d+)$/u', $line, $match)) {
                $aliasCount = (int) $match[1];
                continue;
            }

            if (preg_match('/tenant_accruals\(.*\):\s+(\d+)$/u', $line, $match)) {
                $accrualConflictCount = (int) $match[1];
            }
        }

        return [
            'source_tenant_id' => $sourceTenantId,
            'canonical_tenant_id' => $canonicalTenantId,
            'total_references' => $totalReferences,
            'reference_counts' => $referenceCounts,
            'non_zero_transfers' => $nonZeroTransfers,
            'alias_count' => $aliasCount,
            'accrual_conflict_count' => $accrualConflictCount,
            'showcase_action' => $this->showcaseActionLabel($showcaseAction),
            'has_blocking_conflicts' => $accrualConflictCount > 0,
            'has_alias_table' => Schema::hasTable('tenant_external_aliases'),
        ];
    }

    private function referenceLabel(string $key): string
    {
        return match ($key) {
            'market_spaces.tenant_id' => 'торговые места',
            'tenant_contracts.tenant_id' => 'договоры',
            'tenant_contract_mappings.tenant_id' => 'связи договоров 1С',
            'tenant_requests.tenant_id' => 'заявки арендатора',
            'tenant_accruals.tenant_id' => 'начисления',
            'tenant_documents.tenant_id' => 'документы',
            'tickets.tenant_id' => 'обращения',
            'users.tenant_id' => 'пользователи',
            'contract_debts.tenant_id' => 'долги по договорам',
            'market_space_tenant_histories.old_tenant_id' => 'история мест: старый арендатор',
            'market_space_tenant_histories.new_tenant_id' => 'история мест: новый арендатор',
            'tenant_showcases.tenant_id' => 'витрины арендатора',
            default => $key,
        };
    }

    private function showcaseActionLabel(string $action): string
    {
        return match ($action) {
            'reassign' => 'витрина будет перенесена',
            'merge_and_delete' => 'две витрины будут объединены',
            default => 'действий с витриной нет',
        };
    }
}
