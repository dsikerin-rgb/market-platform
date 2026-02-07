<?php
# app/Http/Controllers/Api/OneC/ContractDebtController.php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\MarketIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ContractDebtController extends Controller
{
    /**
     * Приём snapshot-ов задолженности из 1С.
     */
    public function store(Request $request): JsonResponse
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return response()->json(
                ['message' => 'Authorization token missing'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $integration = MarketIntegration::query()
            ->where('type', MarketIntegration::TYPE_1C)
            ->where('is_active', true)
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $integration) {
            return response()->json(
                ['message' => 'Invalid or inactive token'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        /**
         * Валидация входного payload
         */
        $validated = $request->validate([
            'calculated_at' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.contract_external_id' => ['required', 'string', 'max:255'],
            'items.*.tenant_external_id'   => ['required', 'string', 'max:255'],
            'items.*.debt_amount'          => ['required', 'numeric'],
            'items.*.currency'             => ['nullable', 'string', 'size:3'],
        ]);

        $now = now();
        $marketId = $integration->market_id;
        $calculatedAt = $validated['calculated_at'];

        $rows = [];
        foreach ($validated['items'] as $item) {
            $rows[] = [
                'market_id'             => $marketId,
                'contract_external_id'  => $item['contract_external_id'],
                'tenant_external_id'    => $item['tenant_external_id'],
                'debt_amount'           => $item['debt_amount'],
                'currency'              => $item['currency'] ?? null,
                'calculated_at'         => $calculatedAt,
                'raw_payload'           => json_encode($item, JSON_UNESCAPED_UNICODE),
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        /**
         * Идемпотентная вставка:
         * дубли по (market_id, contract_external_id, calculated_at)
         * будут автоматически проигнорированы
         */
        $inserted = DB::table('contract_debts')->insertOrIgnore($rows);

        return response()->json([
            'status'        => 'ok',
            'market_id'     => $marketId,
            'received'      => count($rows),
            'inserted'      => $inserted,
            'skipped'       => count($rows) - $inserted,
            'calculated_at' => $calculatedAt,
        ]);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }
}
