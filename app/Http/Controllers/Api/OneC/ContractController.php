<?php
# app/Http/Controllers/Api/OneC/ContractController.php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\IntegrationExchange;
use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use App\Services\TenantContracts\SafeContractSpaceLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ContractController extends Controller
{
    private const ENDPOINT = '/api/1c/contracts';
    private const ENTITY_TYPE = 'contracts';

    /**
     * Приём snapshot-ов договоров/размещений из 1С.
     * Ключ: contract_external_id (external_id в tenant_contracts).
     * Привязка места: market_space_code -> market_spaces (code/number) через нормализованные варианты.
     *
     * ВАЖНО: если ключ места неоднозначный (коллизия) — НЕ привязываем договор к месту.
     */
    public function store(
        Request $request,
        SafeContractSpaceLinker $safeLinker,
        ContractDocumentClassifier $classifier
    ): JsonResponse
    {
        $startedAt = now();

        /** @var IntegrationExchange|null $exchange */
        $exchange = null;

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
                        'token_sha256' => hash('sha256', $token),
                    ],
                ]);

                return response()->json(
                    ['message' => 'Invalid or inactive token'],
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $marketId = (int) $integration->market_id;

            $exchange = new IntegrationExchange();
            $exchange->market_id = $marketId;
            $exchange->direction = IntegrationExchange::DIRECTION_IN;
            $exchange->entity_type = self::ENTITY_TYPE;
            $exchange->status = IntegrationExchange::STATUS_IN_PROGRESS;
            $exchange->created_by = null;
            $exchange->started_at = $startedAt;
            $exchange->finished_at = null;
            $exchange->payload = [
                'endpoint' => self::ENDPOINT,
                'market_integration_id' => (int) $integration->id,
                'request_meta' => [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ],
            ];
            $exchange->save();

            try {
                $validated = $request->validate([
                    'calculated_at' => ['required', 'date_format:Y-m-d H:i:s'],
                    'items' => ['required', 'array', 'min:1'],

                    'items.*.contract_external_id' => ['required', 'string', 'max:255'],
                    'items.*.tenant_external_id' => ['required', 'string', 'max:255'],

                    'items.*.market_space_code' => ['nullable', 'string', 'max:255'],

                    'items.*.contract_number' => ['nullable', 'string', 'max:255'],
                    'items.*.status' => ['nullable', 'string', 'max:20'],
                    'items.*.is_active' => ['nullable', 'boolean'],

                    'items.*.starts_at' => ['required', 'date_format:Y-m-d'],
                    'items.*.ends_at' => ['nullable', 'date_format:Y-m-d'],
                    'items.*.signed_at' => ['nullable', 'date_format:Y-m-d'],

                    'items.*.monthly_rent' => ['nullable', 'numeric'],
                    'items.*.currency' => ['nullable', 'string', 'size:3'],

                    'items.*.inn' => ['nullable', 'string', 'max:32'],
                    'items.*.kpp' => ['nullable', 'string', 'max:32'],
                    'items.*.tenant_name' => ['nullable', 'string', 'max:255'],
                ]);
            } catch (ValidationException $e) {
                $items = $request->input('items');
                $received = is_array($items) ? count($items) : 0;
                $calculatedAt = is_string($request->input('calculated_at')) ? (string) $request->input('calculated_at') : null;

                $this->writeImportLog([
                    'status' => 'validation_error',
                    'endpoint' => self::ENDPOINT,
                    'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'market_id' => $marketId,
                    'integration_id' => (int) $integration->id,
                    'received' => $received,
                    'inserted' => 0,
                    'skipped' => 0,
                    'calculated_at' => $calculatedAt,
                    'error_message' => 'Validation failed',
                    'meta' => [
                        'errors' => $e->errors(),
                        'ip' => $request->ip(),
                    ],
                ]);

                if ($exchange) {
                    $exchange->status = IntegrationExchange::STATUS_ERROR;
                    $exchange->error = 'Validation failed';
                    $exchange->finished_at = now();
                    $exchange->payload = array_merge((array) ($exchange->payload ?? []), [
                        'status' => 'validation_error',
                        'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                        'calculated_at' => $calculatedAt,
                        'received' => $received,
                        'inserted' => 0,
                        'skipped' => 0,
                        'validation_errors' => $e->errors(),
                        'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds(now())),
                    ]);
                    $exchange->save();
                }

                throw $e;
            }

            $now = now();
            $calculatedAt = (string) $validated['calculated_at'];

            $received = count($validated['items']);
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $manualSpaceMappingsPreserved = 0;

            $tenantsCreated = 0;
            $tenantsUpdatedByInn = 0;

            // Явная статистика по 4 сценариям привязки
            $linkedContracts = 0;           // место найдено однозначно
            $missingSpaceKey = 0;           // нет market_space_code в payload
            $spaceNotFound = 0;             // ключ есть, но место не найдено
            $spaceAmbiguous = 0;            // ключ матчится на несколько мест

            // Safe auto-link статистика
            $safeLinkedContracts = 0;       // привязано через safe linker
            $safeLinkedByBridge = 0;        // привязано через bridge rule
            $safeLinkedByNumber = 0;        // привязано через number rule

            // Примеры проблемных ключей для диагностики
            $suspectedCurrentDuplicateGroups = 0;
            $suspectedCurrentDuplicateRows = 0;
            $suspectedCurrentDuplicateSamples = [];
            $seenDuplicateSignatures = [];

            $missingKeysSample = [];
            $notFoundKeysSample = [];
            $ambiguousKeysSample = [];
            $samplesLimit = 10;

            // Индекс мест строим 1 раз на запрос — быстро (у вас ~245 мест).
            [$spaceIndex, $keysWithCollisions] = $this->buildSpaceIndex($marketId);

            DB::beginTransaction();

            foreach ($validated['items'] as $item) {
                $tenantExternalId = trim((string) $item['tenant_external_id']);
                $contractExternalId = trim((string) $item['contract_external_id']);

                if ($tenantExternalId === '' || $contractExternalId === '') {
                    $skipped++;
                    continue;
                }

                // === Tenant upsert (как в ContractDebtController) ===
                $tenant = Tenant::query()
                    ->where('market_id', $marketId)
                    ->where('external_id', $tenantExternalId)
                    ->first();

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

                            $newTenant->is_active = true;
                            $newTenant->one_c_data = $this->safeJsonEncode([
                                'created_from' => 'contracts',
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
                        $kpp = trim((string) ($item['kpp'] ?? ''));
                        $tenantName = trim((string) ($item['tenant_name'] ?? ''));

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
                            'created_from' => 'contracts',
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
                    $skipped++;
                    continue;
                }

                // === Space mapping (safe) ===
                $marketSpaceId = null;
                $spaceKey = trim((string) ($item['market_space_code'] ?? ''));
                
                // Явное разделение на 4 сценария
                if ($spaceKey === '') {
                    // Сценарий 1: missing_space_key — нет ключа в payload
                    $missingSpaceKey++;
                    if (count($missingKeysSample) < $samplesLimit) {
                        $missingKeysSample[] = [
                            'contract_external_id' => $contractExternalId,
                            'tenant_external_id' => $tenantExternalId,
                        ];
                    }
                } else {
                    // Нормализация ключа (trim + uppercase)
                    $normalizedKey = mb_strtoupper(trim($spaceKey), 'UTF-8');
                    
                    [$resolvedId, $state, $ids] = $this->resolveMarketSpaceId($spaceIndex, $spaceKey);

                    if ($state === 'ok') {
                        // Сценарий 2: linked — место найдено однозначно
                        $marketSpaceId = $resolvedId;
                        $linkedContracts++;
                    } elseif ($state === 'not_found') {
                        // Сценарий 3: space_not_found — ключ есть, но место не найдено
                        $spaceNotFound++;
                        if (count($notFoundKeysSample) < $samplesLimit) {
                            $notFoundKeysSample[] = [
                                'contract_external_id' => $contractExternalId,
                                'tenant_external_id' => $tenantExternalId,
                                'market_space_code' => $spaceKey,
                                'normalized_key' => $normalizedKey,
                            ];
                        }
                    } else { // ambiguous
                        // Сценарий 4: space_ambiguous — ключ матчится на несколько мест
                        $spaceAmbiguous++;
                        if (count($ambiguousKeysSample) < $samplesLimit) {
                            $ambiguousKeysSample[] = [
                                'contract_external_id' => $contractExternalId,
                                'tenant_external_id' => $tenantExternalId,
                                'market_space_code' => $spaceKey,
                                'matched_space_ids' => $ids,
                            ];
                        }

                        // ВАЖНО: НЕ привязываем (оставляем null), чтобы не повредить данные.
                        $marketSpaceId = null;
                    }
                }

                $currency = strtoupper(trim((string) ($item['currency'] ?? 'RUB')));
                if ($currency === '') {
                    $currency = 'RUB';
                }

                $status = trim((string) ($item['status'] ?? 'active'));
                if ($status === '') {
                    $status = 'active';
                }

                $isActive = array_key_exists('is_active', $item)
                    ? (bool) $item['is_active']
                    : ($status !== 'cancelled');

                $number = trim((string) ($item['contract_number'] ?? ''));
                if ($number === '') {
                    $number = '1C-' . $contractExternalId;
                }

                $contract = TenantContract::query()->firstOrNew([
                    'market_id' => $marketId,
                    'external_id' => $contractExternalId,
                ]);

                $wasRecentlyCreated = ! $contract->exists;
                $currentSpaceMappingMode = $contract->effectiveSpaceMappingMode();
                $usesLockedSpaceMapping = ! $wasRecentlyCreated
                    && $contract->usesLockedSpaceMapping();

                $contract->fill([
                    'tenant_id' => (int) $tenant->id,
                    'number' => $number,
                    'status' => $status,
                    'starts_at' => (string) $item['starts_at'],
                    'ends_at' => $item['ends_at'] ?? null,
                    'signed_at' => $item['signed_at'] ?? null,
                    'monthly_rent' => $item['monthly_rent'] ?? null,
                    'currency' => $currency,
                    'is_active' => $isActive,
                ]);

                $contract->space_mapping_mode = $currentSpaceMappingMode;

                if (
                    $usesLockedSpaceMapping
                    && $marketSpaceId !== null
                    && (int) ($contract->market_space_id ?? 0) !== (int) $marketSpaceId
                ) {
                    $manualSpaceMappingsPreserved++;
                }

                // Respect manually locked local mapping. In auto mode 1C may still
                // update the place link when it provides a reliable market_space_code.
                if (! $usesLockedSpaceMapping && ($marketSpaceId !== null || $wasRecentlyCreated)) {
                    $contract->market_space_id = $marketSpaceId;
                }

                $contract->save();

                $duplicateWarning = $this->detectSuspiciousCurrentDuplicate($contract, $classifier);
                if ($duplicateWarning !== null) {
                    $signature = $duplicateWarning['signature'];

                    if (! isset($seenDuplicateSignatures[$signature])) {
                        $seenDuplicateSignatures[$signature] = true;
                        $suspectedCurrentDuplicateGroups++;
                        $suspectedCurrentDuplicateRows += $duplicateWarning['duplicate_rows'];

                        if (count($suspectedCurrentDuplicateSamples) < $samplesLimit) {
                            unset($duplicateWarning['signature']);
                            $suspectedCurrentDuplicateSamples[] = $duplicateWarning;
                        }
                    }
                }

                // Safe auto-link for contracts without market_space_id
                if ($contract->market_space_id === null) {
                    $linkResult = $safeLinker->link($contract);
                    if ($linkResult['state'] === 'matched') {
                        $safeLinker->apply($contract, $linkResult);
                        $safeLinkedContracts++;
                        if ($linkResult['source'] === 'bridge') {
                            $safeLinkedByBridge++;
                        } elseif ($linkResult['source'] === 'number') {
                            $safeLinkedByNumber++;
                        }
                    }
                }

                if ($wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();

            $durationMs = (int) max(0, $startedAt->diffInMilliseconds(now()));

            // Итоговая статистика по 4 сценариям
            $linkageStats = [
                'total_contracts' => $received,
                'linked_contracts' => $linkedContracts,
                'contracts_without_space_key' => $missingSpaceKey,
                'contracts_space_not_found' => $spaceNotFound,
                'contracts_space_ambiguous' => $spaceAmbiguous,
                'linkage_rate_percent' => $received > 0 ? round(($linkedContracts / $received) * 100, 2) : 0,
            ];

            // Примеры проблемных ключей
            $diagnostics = [];
            if ($missingSpaceKey > 0) {
                $diagnostics['missing_space_key'] = [
                    'count' => $missingSpaceKey,
                    'samples' => $missingKeysSample,
                ];
            }
            if ($spaceNotFound > 0) {
                $diagnostics['space_not_found'] = [
                    'count' => $spaceNotFound,
                    'samples' => $notFoundKeysSample,
                ];
            }
            if ($spaceAmbiguous > 0) {
                $diagnostics['space_ambiguous'] = [
                    'count' => $spaceAmbiguous,
                    'samples' => $ambiguousKeysSample,
                ];
            }

            $warnings = [
                'spaces_not_found' => $spaceNotFound,
                'spaces_ambiguous' => $spaceAmbiguous,
                'contracts_without_space' => $missingSpaceKey,
                'manual_space_mappings_preserved' => $manualSpaceMappingsPreserved,
                'space_key_collisions' => $keysWithCollisions,
                'suspected_current_duplicate_contract_groups' => $suspectedCurrentDuplicateGroups,
                'suspected_current_duplicate_contract_rows' => $suspectedCurrentDuplicateRows,
                'linkage_stats' => $linkageStats,
                'safe_auto_link' => [
                    'linked_total' => $safeLinkedContracts,
                    'linked_by_bridge' => $safeLinkedByBridge,
                    'linked_by_number' => $safeLinkedByNumber,
                ],
            ];

            if ($diagnostics !== []) {
                $warnings['diagnostics'] = $diagnostics;
            }

            if ($suspectedCurrentDuplicateSamples !== []) {
                $warnings['suspected_current_duplicate_contracts'] = [
                    'count' => $suspectedCurrentDuplicateGroups,
                    'rows' => $suspectedCurrentDuplicateRows,
                    'samples' => $suspectedCurrentDuplicateSamples,
                ];
            }

            $response = [
                'status' => 'ok',
                'market_id' => $marketId,
                'received' => $received,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'calculated_at' => $calculatedAt,
                'linkage_stats' => $linkageStats,
                'warnings' => $warnings,
            ];

            $this->writeImportLog([
                'status' => 'ok',
                'endpoint' => self::ENDPOINT,
                'http_status' => Response::HTTP_OK,
                'market_id' => $marketId,
                'integration_id' => (int) $integration->id,
                'received' => $received,
                'inserted' => $created,
                'skipped' => $skipped,
                'calculated_at' => $calculatedAt,
                'duration_ms' => $durationMs,
                'meta' => array_merge($linkageStats, [
                    'created' => $created,
                    'updated' => $updated,
                    'tenants_created' => $tenantsCreated,
                    'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                    'manual_space_mappings_preserved' => $manualSpaceMappingsPreserved,
                    'suspected_current_duplicate_contract_groups' => $suspectedCurrentDuplicateGroups,
                    'suspected_current_duplicate_contract_rows' => $suspectedCurrentDuplicateRows,
                    'ip' => $request->ip(),
                ]),
            ]);

            // Финализируем exchange = ok
            if ($exchange) {
                $exchange->status = IntegrationExchange::STATUS_OK;
                $exchange->finished_at = now();
                $exchange->error = null;
                $exchange->payload = array_merge((array) ($exchange->payload ?? []), [
                    'status' => 'ok',
                    'http_status' => Response::HTTP_OK,
                    'calculated_at' => $calculatedAt,
                    'received' => $received,
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'duration_ms' => $durationMs,
                    'tenants_created' => $tenantsCreated,
                    'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                    'manual_space_mappings_preserved' => $manualSpaceMappingsPreserved,
                    'linkage_stats' => $linkageStats,
                    'warnings' => $warnings,
                ]);
                $exchange->save();
            }

            return response()->json($response);
        } catch (Throwable $e) {
            try {
                DB::rollBack();
            } catch (Throwable) {
                // ignore
            }

            $items = $request->input('items');
            $durationMs = (int) max(0, $startedAt->diffInMilliseconds(now()));

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

            if ($exchange) {
                $exchange->status = IntegrationExchange::STATUS_ERROR;
                $exchange->finished_at = now();
                $exchange->error = $e->getMessage();

                $exchange->payload = array_merge((array) ($exchange->payload ?? []), [
                    'status' => 'exception',
                    'http_status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                    'received' => is_array($items) ? count($items) : 0,
                    'inserted' => 0,
                    'skipped' => 0,
                    'calculated_at' => is_string($request->input('calculated_at')) ? (string) $request->input('calculated_at') : null,
                    'duration_ms' => $durationMs,
                    'exception_class' => $e::class,
                ]);

                $exchange->save();
            }

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

    /**
     * Индекс: нормализованный ключ -> список id.
     *
     * @return array{0:array<string,list<int>>,1:int}
     */
    private function buildSpaceIndex(int $marketId): array
    {
        $index = [];

        $spaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->get(['id', 'code', 'number']);

        foreach ($spaces as $space) {
            $id = (int) $space->id;

            foreach ([(string) ($space->code ?? ''), (string) ($space->number ?? '')] as $rawKey) {
                $rawKey = trim($rawKey);
                if ($rawKey === '') {
                    continue;
                }

                foreach ($this->spaceKeyVariants($rawKey) as $variant) {
                    $index[$variant] ??= [];
                    // уникализируем внутри ключа
                    if (! in_array($id, $index[$variant], true)) {
                        $index[$variant][] = $id;
                    }
                }
            }
        }

        $keysWithCollisions = 0;
        foreach ($index as $ids) {
            if (count($ids) > 1) {
                $keysWithCollisions++;
            }
        }

        return [$index, $keysWithCollisions];
    }

    /**
     * @return array{0:?int,1:'ok'|'not_found'|'ambiguous',2:?list<int>}
     */
    private function resolveMarketSpaceId(array $spaceIndex, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, 'not_found', null];
        }

        foreach ($this->spaceKeyVariants($raw) as $variant) {
            if (! isset($spaceIndex[$variant])) {
                continue;
            }

            $ids = $spaceIndex[$variant];

            if (count($ids) === 1) {
                return [(int) $ids[0], 'ok', $ids];
            }

            // неоднозначно — отдаём список id
            return [null, 'ambiguous', array_values($ids)];
        }

        return [null, 'not_found', null];
    }

    /**
     * @return list<string>
     */
    private function spaceKeyVariants(string $raw): array
    {
        $rawTrim = trim($raw);

        $upper = mb_strtoupper($rawTrim, 'UTF-8');

        $noSpaces = preg_replace('/\s+/u', '', $upper) ?? $upper;
        $normalizedSlashes = str_replace(['\\', '／'], '/', $noSpaces);
        $normalizedDashes = str_replace(['–', '—'], '-', $normalizedSlashes);
        $collapsedSlashes = preg_replace('#/+#', '/', $normalizedDashes) ?? $normalizedDashes;

        $compact = str_replace(['/', '-'], '', $collapsedSlashes);

        $slashToDash = str_replace('/', '-', $collapsedSlashes);
        $dashToSlash = str_replace('-', '/', $collapsedSlashes);

        $slug = Str::lower(Str::slug($rawTrim, '-'));

        $alnum = preg_replace('/[^0-9A-ZА-ЯЁ]/u', '', $collapsedSlashes) ?? $collapsedSlashes;

        $variants = [
            $rawTrim,
            $upper,
            $collapsedSlashes,
            $compact,
            $slashToDash,
            str_replace(['/', '-'], '', $slashToDash),
            $dashToSlash,
            str_replace(['/', '-'], '', $dashToSlash),
            $slug,
            $alnum,
        ];

        $variants = array_filter($variants, static fn ($v) => is_string($v) && trim($v) !== '');

        return array_values(array_unique($variants));
    }

    /**
     * @return array{
     *   signature: string,
     *   tenant_id: int,
     *   market_space_id: int,
     *   place_token: string,
     *   document_date: string,
     *   contract_ids: list<int>,
     *   external_ids: list<string>,
     *   numbers: list<string>,
     *   duplicate_rows: int
     * }|null
     */
    private function detectSuspiciousCurrentDuplicate(
        TenantContract $contract,
        ContractDocumentClassifier $classifier
    ): ?array {
        if (! $contract->is_active || $contract->market_space_id === null || $contract->tenant_id === null) {
            return null;
        }

        if ($contract->effectiveSpaceMappingMode() === TenantContract::SPACE_MAPPING_MODE_EXCLUDED) {
            return null;
        }

        $classification = $classifier->classify((string) ($contract->number ?? ''));
        if (($classification['category'] ?? null) !== 'primary_contract') {
            return null;
        }

        $placeToken = trim((string) ($classification['place_token'] ?? ''));
        $documentDate = trim((string) ($classification['document_date'] ?? ''));
        $externalId = trim((string) ($contract->external_id ?? ''));

        if ($placeToken === '' || $documentDate === '' || $externalId === '') {
            return null;
        }

        $matched = [];

        $duplicates = TenantContract::query()
            ->where('market_id', (int) $contract->market_id)
            ->where('tenant_id', (int) $contract->tenant_id)
            ->where('market_space_id', (int) $contract->market_space_id)
            ->where('is_active', true)
            ->where('id', '!=', (int) $contract->id)
            ->where('external_id', '!=', $externalId)
            ->get(['id', 'external_id', 'number', 'space_mapping_mode']);

        foreach ($duplicates as $candidate) {
            if ($candidate->effectiveSpaceMappingMode() === TenantContract::SPACE_MAPPING_MODE_EXCLUDED) {
                continue;
            }

            $candidateClassification = $classifier->classify((string) ($candidate->number ?? ''));
            if (($candidateClassification['category'] ?? null) !== 'primary_contract') {
                continue;
            }

            if (($candidateClassification['place_token'] ?? null) !== $placeToken) {
                continue;
            }

            if (($candidateClassification['document_date'] ?? null) !== $documentDate) {
                continue;
            }

            $matched[] = $candidate;
        }

        if ($matched === []) {
            return null;
        }

        $groupContracts = collect($matched)
            ->push($contract)
            ->sortBy('id')
            ->values();

        $contractIds = $groupContracts
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $externalIds = $groupContracts
            ->pluck('external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->all();

        $numbers = $groupContracts
            ->pluck('number')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->all();

        return [
            'signature' => implode('|', [
                (int) $contract->tenant_id,
                (int) $contract->market_space_id,
                $placeToken,
                $documentDate,
            ]),
            'tenant_id' => (int) $contract->tenant_id,
            'market_space_id' => (int) $contract->market_space_id,
            'place_token' => $placeToken,
            'document_date' => $documentDate,
            'contract_ids' => $contractIds,
            'external_ids' => $externalIds,
            'numbers' => $numbers,
            'duplicate_rows' => count($contractIds),
        ];
    }
}
