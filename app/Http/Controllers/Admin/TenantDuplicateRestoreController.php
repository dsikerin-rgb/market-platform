<?php
# app/Http/Controllers/Admin/TenantDuplicateRestoreController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantDuplicateRestoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Filament::auth()->user();

        abort_unless($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'tenant_a_id' => ['required', 'integer', 'min:1', 'different:tenant_b_id'],
            'tenant_b_id' => ['required', 'integer', 'min:1'],
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
                'message' => 'Арендаторы относятся к разным рынкам. Пару нельзя вернуть в этот список.',
            ], 422);
        }

        [$leftTenantId, $rightTenantId] = [min($tenantAId, $tenantBId), max($tenantAId, $tenantBId)];

        $deleted = DB::table('tenant_duplicate_ignores')
            ->where('market_id', (int) $tenantA->market_id)
            ->where('tenant_left_id', $leftTenantId)
            ->where('tenant_right_id', $rightTenantId)
            ->delete();

        Log::notice('Tenant duplicate pair restored from UI', [
            'market_id' => (int) $tenantA->market_id,
            'tenant_left_id' => $leftTenantId,
            'tenant_right_id' => $rightTenantId,
            'deleted' => $deleted,
            'user_id' => (int) ($user->id ?? 0),
        ]);

        return response()->json([
            'ok' => true,
            'message' => $deleted > 0 ? 'Пара возвращена в список проверки.' : 'Пара уже была в списке проверки.',
            'tenant_left_id' => $leftTenantId,
            'tenant_right_id' => $rightTenantId,
        ]);
    }
}
