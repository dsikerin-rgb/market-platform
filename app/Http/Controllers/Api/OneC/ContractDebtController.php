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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ContractDebtController extends Controller
{
    private const ENDPOINT = '/api/1c/contract-debts';

    /**
     * Приём snapshot-ов задолженности из 1С.
     *
     * Важно: идемпотентность обеспечиваем по СТАБИЛЬНЫМ данным строки долга
     * (без calculated_at и без "временных" метаданных).
     */
    public function store(Request $request): JsonResponse
    {
        $startedAt = now();

        try {
            $token = $this->extractBearerToken($request);

            if ($token === null) {
                $this->writeImportLog([
                    'status' => 'auth_missing',
                    'endpoint' => self::ENDPOINT,
                    'http_status' => Response::HTTP_UNAUTHORIZED,
                    'received' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'calculated_at' => null,
                    'error_message' => 'Authorization token missing',
                    'meta' => [
                        'ip' => $request->ip(),
                        'user_agent' => (string) $request->userAgent(),
                    ],
                ]);

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
                $this->writeImportLog([
                    'status' => 'auth_invalid',
                    'endpoint' => self::ENDPOINT,
                    'http_status' => Response::HTTP_UNAUTHORIZED,
                    'received' => 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'calculated_at' => null,
                    'error_message' => 'Invalid or inactive token',
                    'meta' => [
                        'ip' => $request->ip(),
                        'user_agent' => (string) $request->userAgent(),
                        // не пишем токен, максимум – его хеш (без утечки)
                        'token_sha256' => hash('sha256', $token),
                    ],
                ]);

                return response()->json(
                    ['message' => 'Invalid or inactive token'],
                    Response::HTTP_UNAUTHORIZED
                );
            }

            try {
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
            } catch (ValidationException $e) {
                $items = $request->input('items');

                $this->writeImportLog([
                    'status' => 'validation_error',
                    'endpoint' => self::ENDPOINT,
                    'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'market_id' => (int) $integration->market_id,
                    'integration_id' => (int) $integration->id,
                    'received' => is_array($items) ? count($items) : 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'calculated_at' => is_string($request->input('calculated_at')) ? (string) $request->input('calculated_at') : null,
                    'error_message' => 'Validation failed',
                    'meta' => [
                        'errors' => $e->errors(),
                        'ip' => $request->ip(),
                    ],
                ]);

                throw $e; // Laravel сам вернёт 422
            }

            $now = now();
            $marketId = (int) $integration->market_id;
            $calculatedAt = (string) $validated['calculated_at'];

            $rows = [];
            $skipped = 0;
            $notFoundTenants = [];
            $tenantsCreated = 0;
            $tenantsUpdatedByInn = 0;

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

                            // КРИТИЧНО: one_c_data пишем как JSON-строку (и устойчиво к не-UTF8)
                            $tenantByInn->one_c_data = $this->safeJsonEncode($existing);

                            $tenantByInn->save();
                            $tenant = $tenantByInn;

                            $tenantsUpdatedByInn++;
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

                            $newTenant->one_c_data = $this->safeJsonEncode([
                                'created_from' => 'contract_debts',
                                'first_seen' => $now->toDateTimeString(),
                                'last_seen' => $now->toDateTimeString(),
                                'inn' => $inn,
                                'kpp' => $kpp,
                                'tenant_name' => $tenantName,
                            ]);

                            $newTenant->save();
                            $tenant = $newTenant;

                            $tenantsCreated++;
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

                        $newTenant->one_c_data = $this->safeJsonEncode([
                            'created_from' => 'contract_debts',
                            'first_seen' => $now->toDateTimeString(),
                            'last_seen' => $now->toDateTimeString(),
                            'inn' => null,
                            'kpp' => $kpp,
                            'tenant_name' => $tenantName,
                        ]);

                        $newTenant->save();
                        $tenant = $newTenant;

                        $tenantsCreated++;
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
                    'raw_payload' => $this->safeJsonEncode($item),
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

            $this->writeImportLog([
                'status' => 'ok',
                'endpoint' => self::ENDPOINT,
                'http_status' => Response::HTTP_OK,
                'market_id' => $marketId,
                'integration_id' => (int) $integration->id,
                'received' => count($validated['items']),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'calculated_at' => $calculatedAt,
                'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds(now())),
                'meta' => [
                    'tenants_created' => $tenantsCreated,
                    'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                    'warnings' => $response['warnings'] ?? null,
                    'ip' => $request->ip(),
                ],
            ]);

            return response()->json($response);
        } catch (Throwable $e) {
            $items = $request->input('items');

            $this->writeImportLog([
                'status' => 'exception',
                'endpoint' => self::ENDPOINT,
                'http_status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'received' => is_array($items) ? count($items) : 0,
                'inserted' => 0,
                'skipped' => 0,
                'calculated_at' => is_string($request->input('calculated_at')) ? (string) $request->input('calculated_at') : null,
                'error_message' => $e->getMessage(),
                'meta' => [
                    'exception_class' => $e::class,
                    'ip' => $request->ip(),
                ],
            ]);

            throw $e;
        }
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

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
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

    /**
     * Безопасный json_encode, устойчивый к не-UTF8 данным.
     */
    private function safeJsonEncode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '{}';
    }

    private function writeImportLog(array $data): void
    {
        try {
            if (! Schema::hasTable('one_c_import_logs')) {
                return;
            }

            $calculatedAt = $data['calculated_at'] ?? null;
            if (! is_string($calculatedAt) || trim($calculatedAt) === '') {
                $calculatedAt = null;
            }

            DB::table('one_c_import_logs')->insert([
                'market_id' => $data['market_id'] ?? null,
                'market_integration_id' => $data['integration_id'] ?? null,
                'endpoint' => (string) ($data['endpoint'] ?? self::ENDPOINT),
                'status' => (string) ($data['status'] ?? 'unknown'),
                'http_status' => (int) ($data['http_status'] ?? 0),
                'calculated_at' => $calculatedAt,
                'received' => (int) ($data['received'] ?? 0),
                'inserted' => (int) ($data['inserted'] ?? 0),
                'skipped' => (int) ($data['skipped'] ?? 0),
                'duration_ms' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
                'error_message' => isset($data['error_message']) ? (string) $data['error_message'] : null,
                'meta' => isset($data['meta']) ? $this->safeJsonEncode($data['meta']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // не валим запрос из-за логирования
        }
    }
}