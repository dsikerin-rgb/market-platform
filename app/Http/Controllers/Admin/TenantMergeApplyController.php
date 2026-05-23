<?php
# app/Http/Controllers/Admin/TenantMergeApplyController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenants\TenantMergeBackupService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TenantMergeApplyController extends Controller
{
    public function __invoke(Request $request, TenantMergeBackupService $backupService): JsonResponse
    {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'source_tenant_id' => ['required', 'integer', 'min:1', 'different:canonical_tenant_id'],
            'canonical_tenant_id' => ['required', 'integer', 'min:1'],
            'confirmation' => ['required', 'string', 'in:MERGE_TENANTS'],
        ]);

        $sourceTenantId = (int) $validated['source_tenant_id'];
        $canonicalTenantId = (int) $validated['canonical_tenant_id'];

        $tenantError = $this->validateTenantPair($sourceTenantId, $canonicalTenantId);
        if ($tenantError instanceof JsonResponse) {
            return $tenantError;
        }

        $dryRun = $this->runMergeCommand($sourceTenantId, $canonicalTenantId, false);
        if ($dryRun['exit_code'] !== 0 || str_contains($dryRun['output'], 'Preflight failed')) {
            return response()->json([
                'ok' => false,
                'message' => 'Проверка перед слиянием нашла проблему. Данные не изменены.',
                'raw_output' => $dryRun['output'],
            ], 422);
        }

        try {
            $backup = $backupService->createBeforeTenantMerge($sourceTenantId, $canonicalTenantId);
        } catch (\Throwable $exception) {
            Log::warning('Tenant merge backup failed from UI', [
                'source_tenant_id' => $sourceTenantId,
                'canonical_tenant_id' => $canonicalTenantId,
                'user_id' => (int) ($user->id ?? 0),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $merge = $this->runMergeCommand($sourceTenantId, $canonicalTenantId, true);
        $ok = $merge['exit_code'] === 0;

        Log::notice('Tenant merge applied from UI', [
            'ok' => $ok,
            'source_tenant_id' => $sourceTenantId,
            'canonical_tenant_id' => $canonicalTenantId,
            'user_id' => (int) ($user->id ?? 0),
            'backup_path' => $backup['relative_path'],
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Дубль слит. Backup создан, данные перенесены.'
                : 'Backup создан, но слияние не выполнено. Данные в транзакции откатились.',
            'backup' => [
                'path' => $backup['relative_path'],
                'size_bytes' => $backup['size_bytes'],
                'created_at' => $backup['created_at'],
            ],
            'raw_output' => $merge['output'],
        ], $ok ? 200 : 422);
    }

    private function validateTenantPair(int $sourceTenantId, int $canonicalTenantId): ?JsonResponse
    {
        $sourceTenant = Tenant::query()->find($sourceTenantId);
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

        return null;
    }

    /**
     * @return array{exit_code:int,output:string}
     */
    private function runMergeCommand(int $sourceTenantId, int $canonicalTenantId, bool $apply): array
    {
        $arguments = [
            'from' => $sourceTenantId,
            'to' => $canonicalTenantId,
            '--preflight-limit' => 20,
        ];

        if ($apply) {
            $arguments['--execute'] = true;
        } else {
            $arguments['--dry-run'] = true;
        }

        $exitCode = Artisan::call('tenants:merge', $arguments);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }
}
