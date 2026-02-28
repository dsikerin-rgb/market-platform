<?php

declare(strict_types=1);

# app/Http/Controllers/Api/OneC/ContractDebtController.php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\MarketIntegration;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ContractDebtController extends Controller
{
    /**
     * Приём snapshot-ов задолженности из 1С.
     *
     * Важно: идемпотентность обеспечиваем по СТАБИЛЬНЫМ данным строки долга
     * (без calculated_at и без "временных" метаданных).
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

        $validated = $request->validate([
            'calculated_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'items' => ['required', 'array', 'min:1'],

            'items.*.contract_external_id' => ['required', 'string', 'max:255'],
            'items.*.tenant_external_id'   => ['required', 'string', 'max:255'],

            // fallback-поля (если external_id в нашей БД ещё не был проставлен)
            'items.*.inn'         => ['nullable', 'string', 'max:32'],
            'items.*.kpp'         => ['nullable', 'string', 'max:32'],
            'items.*.tenant_name' => ['nullable', 'string', 'max:255'],

            'items.*.accrued_amount' => ['required', 'numeric'],
            'items.*.paid_amount'    => ['required', 'numeric'],
            'items.*.debt_amount'    => ['required', 'numeric'],
            'items.*.period'         => ['required', 'string', 'size:7'], // YYYY-MM
            'items.*.currency'       => ['nullable', 'string', 'size:3'],
        ]);

        $now = now();
        $marketId = (int) $integration->market_id;
        $calculatedAt = (string) $validated['calculated_at'];

        $rows = [];
        $skipped = 0;
        $notFoundTenants = [];

        foreach ($validated['items'] as $item) {
            $tenantExternalId = trim((string) $item['tenant_external_id']);
            $contractExternalId = trim((string) $item['contract_external_id']);
            $period = trim((string) $item['period']);

            $currency = strtoupper(trim((string) ($item['currency'] ?? 'RUB')));
            if ($currency === '') {
                $currency = 'RUB';
            }

            // Нормализуем суммы в строку с 2 знаками после запятой — детерминированно для hash/сравнений
            $accruedAmount = $this->normalizeMoney($item['accrued_amount']);
            $paidAmount = $this->normalizeMoney($item['paid_amount']);
            $debtAmount = $this->normalizeMoney($item['debt_amount']);

            // 1) основной путь — по external_id
            $tenant = Tenant::query()
                ->where('market_id', $marketId)
                ->where('external_id', $tenantExternalId)
                ->first();

            // 2) fallback — по ИНН (и привязать external_id), либо создать арендатора
            if (! $tenant) {
                $inn = trim((string) ($item['inn'] ?? ''));
                $kpp = trim((string) ($item['kpp'] ?? ''));
                $tenantName = trim((string) ($item['tenant_name'] ?? ''));

                if ($inn !== '') {
                    $tenantByInn = Tenant::query()
                        ->where('market_id', $marketId)
                        ->where('inn', $inn)
                        ->first();

                    if ($tenantByInn) {
                        $tenantByInn->external_id = $tenantExternalId;

                        if (preg_match('/^[0-9a-fA-F-]{36}$/', $tenantExternalId)) {
                            $tenantByInn->one_c_uid = $tenantExternalId;
                        }

                        if (($tenantByInn->inn === null || $tenantByInn->inn === '') && $inn !== '') {
                            $tenantByInn->inn = $inn;
                        }

                        if (($tenantByInn->kpp === null || $tenantByInn->kpp === '') && $kpp !== '') {
                            $tenantByInn->kpp = $kpp;
                        }

                        if ($tenantName !== '' && ($tenantByInn->name === null || $tenantByInn->name === '')) {
                            $tenantByInn->name = $tenantName;
                        }

                        $existing = $this->decodeOneCData($tenantByInn->one_c_data);

                        $existing = array_merge($existing, [
                            'last_seen' => $now->toDateTimeString(),
                            'inn' => $inn,
                            'kpp' => $kpp,
                            'tenant_name' => $tenantName,
                        ]);

                        // КРИТИЧНО: one_c_data пишем как JSON-строку
                        $tenantByInn->one_c_data = json_encode(
                            $existing,
                            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                        );

                        $tenantByInn->save();
                        $tenant = $tenantByInn;
                    } else {
                        $newTenant = new Tenant();
                        $newTenant->market_id = $marketId;
                        $newTenant->inn = $inn;
                        $newTenant->kpp = $kpp !== '' ? $kpp : null;
                        $newTenant->name = $tenantName !== '' ? $tenantName : ('1C tenant ' . $inn);
                        $newTenant->external_id = $tenantExternalId;

                        if (preg_match('/^[0-9a-fA-F-]{36}$/', $tenantExternalId)) {
                            $newTenant->one_c_uid = $tenantExternalId;
                        }

                        // чтобы был видимым в админке
                        $newTenant->is_active = true;

                        $newTenant->one_c_data = json_encode([
                            'created_from' => 'contract_debts',
                            'first_seen' => $now->toDateTimeString(),
                            'last_seen' => $now->toDateTimeString(),
                            'inn' => $inn,
                            'kpp' => $kpp,
                            'tenant_name' => $tenantName,
                        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                        $newTenant->save();
                        $tenant = $newTenant;
                    }
                } else {
                    // ИНН отсутствует — всё равно создаём арендатора (требование: автосоздание)
                    $newTenant = new Tenant();
                    $newTenant->market_id = $marketId;
                    $newTenant->inn = null;
                    $newTenant->kpp = $kpp !== '' ? $kpp : null;

                    $newTenant->name = $tenantName !== '' ? $tenantName : ('1C tenant ' . $tenantExternalId);
                    $newTenant->external_id = $tenantExternalId;

                    if (preg_match('/^[0-9a-fA-F-]{36}$/', $tenantExternalId)) {
                        $newTenant->one_c_uid = $tenantExternalId;
                    }

                    $newTenant->is_active = true;

                    $newTenant->one_c_data = json_encode([
                        'created_from' => 'contract_debts',
                        'first_seen' => $now->toDateTimeString(),
                        'last_seen' => $now->toDateTimeString(),
                        'inn' => $inn,
                        'kpp' => $kpp,
                        'tenant_name' => $tenantName,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                    $newTenant->save();
                    $tenant = $newTenant;
                }
            }

            if (! $tenant) {
                $notFoundTenants[] = $tenantExternalId;
                $skipped++;
                continue;
            }

            /**
             * Идемпотентность:
             * Считаем "стабильный" hash без calculated_at.
             * Дополнительно: перед вставкой проверяем, есть ли уже строка с такими же данными,
             * чтобы не плодить дубликаты даже если ранее hash считался иначе.
             */
            $hash = $this->makeDebtHash(
                $marketId,
                $tenantExternalId,
                $contractExternalId,
                $period,
                $currency,
                $accruedAmount,
                $paidAmount,
                $debtAmount
            );

            $alreadyExists = DB::table('contract_debts')
                ->where('market_id', $marketId)
                ->where('tenant_external_id', $tenantExternalId)
                ->where('contract_external_id', $contractExternalId)
                ->where('period', $period)
                ->where('currency', $currency)
                ->where('accrued_amount', $accruedAmount)
                ->where('paid_amount', $paidAmount)
                ->where('debt_amount', $debtAmount)
                ->exists();

            if ($alreadyExists) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'market_id' => $marketId,
                'tenant_id' => $tenant->id,
                'tenant_external_id' => $tenantExternalId,
                'contract_external_id' => $contractExternalId,
                'period' => $period,
                'accrued_amount' => $accruedAmount,
                'paid_amount' => $paidAmount,
                'debt_amount' => $debtAmount,
                'calculated_at' => $calculatedAt,
                'source' => '1c',
                'market_external_id' => 'VDNH',
                'currency' => $currency,
                'hash' => $hash,
                'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

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
                'not_found_external_ids' => array_values(array_unique($notFoundTenants)),
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

    /**
     * one_c_data может быть: null | array (если где-то есть cast) | JSON string.
     * Нормализуем в array.
     */
    private function decodeOneCData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Детерминированная нормализация денег в строку с 2 знаками после запятой.
     */
    private function normalizeMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Стабильный hash для идемпотентности (без calculated_at).
     */
    private function makeDebtHash(
        int $marketId,
        string $tenantExternalId,
        string $contractExternalId,
        string $period,
        string $currency,
        string $accruedAmount,
        string $paidAmount,
        string $debtAmount
    ): string {
        $payload = implode('|', [
            (string) $marketId,
            $tenantExternalId,
            $contractExternalId,
            $period,
            $currency,
            $accruedAmount,
            $paidAmount,
            $debtAmount,
        ]);

        return hash('sha256', $payload);
    }
}