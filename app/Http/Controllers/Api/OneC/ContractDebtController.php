<?php
# app/Http/Controllers/Api/OneC/ContractDebtController.php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\MarketIntegration;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->where('status', 'active')
            ->where('auth_token', $token)
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
            'calculated_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.contract_external_id' => ['required', 'string', 'max:255'],
            'items.*.tenant_external_id'   => ['required', 'string', 'max:255'],
            'items.*.accrued_amount'       => ['required', 'numeric'],
            'items.*.paid_amount'          => ['required', 'numeric'],
            'items.*.debt_amount'          => ['required', 'numeric'],
            'items.*.period'               => ['required', 'string', 'size:7'], // YYYY-MM
            'items.*.currency'             => ['nullable', 'string', 'size:3'],
        ]);

        $now = now();
        $marketId = $integration->market_id;
        $calculatedAt = $validated['calculated_at'];

        $rows = [];
        $skipped = 0;
        $notFoundTenants = [];

        foreach ($validated['items'] as $item) {
            // Поиск арендатора по внешнему идентификатору из 1С
            $tenant = Tenant::query()
                ->where('market_id', $marketId)
                ->where('external_id', $item['tenant_external_id'])
                ->first();

            if (! $tenant) {
                $notFoundTenants[] = $item['tenant_external_id'];
                $skipped++;
                continue;
            }

            // Генерация хэша для защиты от дубликатов
            $hash = hash('sha256', json_encode([
                'market_id' => $marketId,
                'tenant_id' => $tenant->id,
                'contract_external_id' => $item['contract_external_id'],
                'period' => $item['period'],
                'calculated_at' => $calculatedAt,
            ], JSON_UNESCAPED_UNICODE));

            $rows[] = [
                'market_id' => $marketId,
                'tenant_id' => $tenant->id,
                'tenant_external_id' => $item['tenant_external_id'],
                'contract_external_id' => $item['contract_external_id'],
                'period' => $item['period'],
                'accrued_amount' => $item['accrued_amount'],
                'paid_amount' => $item['paid_amount'],
                'debt_amount' => $item['debt_amount'],
                'calculated_at' => $calculatedAt,
                'source' => '1c',
                'market_external_id' => 'VDNH',
                'currency' => $item['currency'] ?? 'RUB',
                'hash' => $hash,
                'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        /**
         * Идемпотентная вставка:
         * дубли по уникальному индексу (hash) будут проигнорированы
         */
        $inserted = 0;
        if (! empty($rows)) {
            $inserted = DB::table('contract_debts')->insertOrIgnore($rows);
        }

        $response = [
            'status' => 'ok',
            'market_id' => $marketId,
            'received' => count($validated['items']),
            'inserted' => $inserted,
            'skipped' => $skipped,
            'calculated_at' => $calculatedAt,
        ];

        if (! empty($notFoundTenants)) {
            $response['warnings'] = [
                'message' => 'Some tenants not found by external_id',
                'not_found_external_ids' => array_unique($notFoundTenants),
            ];
        }

        return response()->json($response);
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