<?php
# app/Http/Controllers/Admin/TenantDuplicateIgnoreController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantDuplicateIgnoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'tenant_a_id' => ['required', 'integer', 'min:1', 'different:tenant_b_id'],
            'tenant_b_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenantAId = (int) $validated['tenant_a_id'];
        $tenantBId = (int) $validated['tenant_b_id'];

        $tenantA = Tenant::query()->find($tenantAId);
        $tenantB = Tenant::query()->find($tenantBId);

        if (! $tenantA || ! $tenantB) {
            return response()->json([
                'ok' => false,
                'message' => 'Один из арендаторов не найден. Обновите страницу и попробуйте ещё раз.',
            ], 404);
        }

        if ((int) $tenantA->market_id !== (int) $tenantB->market_id) {
            return response()->json([
                'ok' => false,
                'message' => 'Арендаторы относятся к разным рынкам. Проверку нельзя скрыть.',
            ], 422);
        }

        [$leftTenantId, $rightTenantId] = [min($tenantAId, $tenantBId), max($tenantAId, $tenantBId)];
        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'different_tenants';
        $comment = trim((string) ($validated['comment'] ?? ''));

        DB::table('tenant_duplicate_ignores')->updateOrInsert(
            [
                'market_id' => (int) $tenantA->market_id,
                'tenant_left_id' => $leftTenantId,
                'tenant_right_id' => $rightTenantId,
            ],
            [
                'reason' => $reason,
                'comment' => $comment !== '' ? $comment : null,
                'ignored_by_user_id' => (int) ($user->id ?? 0) ?: null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        Log::notice('Tenant duplicate pair ignored from UI', [
            'market_id' => (int) $tenantA->market_id,
            'tenant_left_id' => $leftTenantId,
            'tenant_right_id' => $rightTenantId,
            'reason' => $reason,
            'user_id' => (int) ($user->id ?? 0),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Пара скрыта из списка дублей.',
            'tenant_left_id' => $leftTenantId,
            'tenant_right_id' => $rightTenantId,
        ]);
    }
}
