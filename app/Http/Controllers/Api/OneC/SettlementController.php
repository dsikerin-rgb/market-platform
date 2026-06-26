<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\IntegrationExchange;
use App\Models\MarketIntegration;
use App\Models\TenantContract;
use App\Models\TenantSettlementBalance;
use App\Services\Tenants\OneCTenantResolver;
use App\Support\MarketContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SettlementController extends Controller
{
    private const ENDPOINT = '/api/1c/settlements';

    private const ENTITY_TYPE = 'settlements';

    public function store(Request $request, OneCTenantResolver $tenantResolver): JsonResponse
    {
        $startedAt = now();
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

                return response()->json(['message' => 'Authorization token missing'], Response::HTTP_UNAUTHORIZED);
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

                return response()->json(['message' => 'Invalid or inactive token'], Response::HTTP_UNAUTHORIZED);
            }

            $marketId = (int) $integration->market_id;

            return app(MarketContext::class)->withMarket(
                $marketId,
                function () use ($request, $tenantResolver, $integration, $marketId, $startedAt, &$exchange): JsonResponse {
                    $exchange = IntegrationExchange::query()->create([
                        'market_id' => $marketId,
                        'direction' => IntegrationExchange::DIRECTION_IN,
                        'entity_type' => self::ENTITY_TYPE,
                        'status' => IntegrationExchange::STATUS_IN_PROGRESS,
                        'created_by' => null,
                        'started_at' => $startedAt,
                        'finished_at' => null,
                        'payload' => [
                            'endpoint' => self::ENDPOINT,
                            'market_integration_id' => (int) $integration->id,
                            'request_meta' => [
                                'ip' => $request->ip(),
                                'user_agent' => (string) $request->userAgent(),
                            ],
                        ],
                    ]);

                    try {
                        $validated = $request->validate([
                            'calculated_at' => ['required', 'date_format:Y-m-d H:i:s'],
                            'period_from' => ['required', 'date_format:Y-m-d'],
                            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
                            'account' => ['required', 'string', 'max:64'],
                            'items' => ['required', 'array', 'min:1'],

                            'items.*.tenant_external_id' => ['required', 'string', 'max:255'],
                            'items.*.tenant_name' => ['nullable', 'string', 'max:255'],
                            'items.*.inn' => ['nullable', 'string', 'max:32'],
                            'items.*.kpp' => ['nullable', 'string', 'max:32'],

                            'items.*.contract_external_id' => ['nullable', 'string', 'max:255'],
                            'items.*.contract_name' => ['nullable', 'string', 'max:255'],

                            'items.*.settlement_document_external_id' => ['nullable', 'string', 'max:255'],
                            'items.*.settlement_document_name' => ['nullable', 'string'],

                            'items.*.organization_external_id' => ['nullable', 'string', 'max:255'],
                            'items.*.organization_name' => ['nullable', 'string', 'max:255'],
                            'items.*.account' => ['nullable', 'string', 'max:64'],
                            'items.*.currency' => ['nullable', 'string', 'size:3'],

                            'items.*.opening_debit' => ['nullable', 'numeric'],
                            'items.*.opening_credit' => ['nullable', 'numeric'],
                            'items.*.turnover_debit' => ['nullable', 'numeric'],
                            'items.*.turnover_credit' => ['nullable', 'numeric'],
                            'items.*.closing_debit' => ['nullable', 'numeric'],
                            'items.*.closing_credit' => ['nullable', 'numeric'],
                        ]);
                    } catch (ValidationException $e) {
                        $received = is_array($request->input('items')) ? count($request->input('items')) : 0;

                        $this->writeImportLog([
                            'status' => 'validation_error',
                            'endpoint' => self::ENDPOINT,
                            'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                            'market_id' => $marketId,
                            'integration_id' => (int) $integration->id,
                            'received' => $received,
                            'inserted' => 0,
                            'skipped' => 0,
                            'calculated_at' => is_string($request->input('calculated_at')) ? (string) $request->input('calculated_at') : null,
                            'error_message' => 'Validation failed',
                            'meta' => [
                                'errors' => $e->errors(),
                                'ip' => $request->ip(),
                            ],
                        ]);

                        $exchange->markFinishedError('Validation failed', array_merge((array) ($exchange->payload ?? []), [
                            'status' => 'validation_error',
                            'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                            'received' => $received,
                            'inserted' => 0,
                            'skipped' => 0,
                            'validation_errors' => $e->errors(),
                            'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds(now())),
                        ]));
                        $exchange->save();

                        throw $e;
                    }

                    $periodFrom = (string) $validated['period_from'];
                    $periodTo = (string) $validated['period_to'];
                    $accountScope = trim((string) $validated['account']);
                    $received = count($validated['items']);
                    $inserted = 0;
                    $updated = 0;
                    $skipped = 0;
                    $linkedContracts = 0;
                    $unresolvedContracts = 0;
                    $tenantsCreated = 0;
                    $tenantsUpdatedByInn = 0;
                    $snapshotDeleted = 0;
                    $snapshotSyncSkipped = false;
                    $touchedBalanceIds = [];
                    $now = now();

                    DB::beginTransaction();

                    foreach ($validated['items'] as $item) {
                        $tenantExternalId = trim((string) $item['tenant_external_id']);
                        $contractExternalId = trim((string) ($item['contract_external_id'] ?? ''));
                        $contractName = trim((string) ($item['contract_name'] ?? ''));
                        $settlementDocumentExternalId = trim((string) ($item['settlement_document_external_id'] ?? ''));
                        $settlementDocumentName = trim((string) ($item['settlement_document_name'] ?? ''));
                        $organizationExternalId = trim((string) ($item['organization_external_id'] ?? ''));
                        $organizationName = trim((string) ($item['organization_name'] ?? ''));
                        $account = trim((string) ($item['account'] ?? ''));
                        if ($account === '') {
                            $account = $accountScope;
                        }
                        $currency = strtoupper(trim((string) ($item['currency'] ?? 'RUB')));

                        if ($currency === '') {
                            $currency = 'RUB';
                        }

                        $tenantResolution = $tenantResolver->resolve(
                            $marketId,
                            $tenantExternalId,
                            $item,
                            'settlements',
                            $now,
                            ['activate_resolved_tenant' => true],
                        );

                        $tenant = $tenantResolution['tenant'];

                        if (! $tenant) {
                            $snapshotSyncSkipped = true;
                            $skipped++;

                            continue;
                        }

                        if ($tenantResolution['mode'] === 'created') {
                            $tenantsCreated++;
                        } elseif ($tenantResolution['mode'] === 'matched_inn') {
                            $tenantsUpdatedByInn++;
                        }

                        $contract = $this->resolveContract($marketId, (int) $tenant->id, $contractExternalId);

                        if ($contract) {
                            $linkedContracts++;
                        } elseif ($contractExternalId !== '') {
                            $unresolvedContracts++;
                        }

                        $amounts = [
                            'opening_debit' => $this->normalizeMoney($item['opening_debit'] ?? null),
                            'opening_credit' => $this->normalizeMoney($item['opening_credit'] ?? null),
                            'turnover_debit' => $this->normalizeMoney($item['turnover_debit'] ?? null),
                            'turnover_credit' => $this->normalizeMoney($item['turnover_credit'] ?? null),
                            'closing_debit' => $this->normalizeMoney($item['closing_debit'] ?? null),
                            'closing_credit' => $this->normalizeMoney($item['closing_credit'] ?? null),
                        ];

                        $sourceRowHash = $this->makeSettlementHash(
                            $marketId,
                            $periodFrom,
                            $periodTo,
                            $tenantExternalId,
                            $contractExternalId,
                            $contractName,
                            $settlementDocumentExternalId,
                            $settlementDocumentName,
                            $organizationExternalId,
                            $organizationName,
                            $account,
                            $currency,
                            $amounts,
                        );

                        $values = [
                            'market_id' => $marketId,
                            'tenant_id' => (int) $tenant->id,
                            'tenant_contract_id' => $contract?->id,
                            'period_from' => $periodFrom,
                            'period_to' => $periodTo,
                            'tenant_external_id' => $tenantExternalId,
                            'tenant_name' => $this->nullableString($item['tenant_name'] ?? null, 255),
                            'inn' => $this->nullableString($item['inn'] ?? null, 32),
                            'kpp' => $this->nullableString($item['kpp'] ?? null, 32),
                            'contract_external_id' => $contractExternalId !== '' ? $contractExternalId : null,
                            'contract_name' => $contractName !== '' ? mb_substr($contractName, 0, 255) : null,
                            'settlement_document_external_id' => $settlementDocumentExternalId !== '' ? $settlementDocumentExternalId : null,
                            'settlement_document_name' => $settlementDocumentName !== '' ? $settlementDocumentName : null,
                            'organization_external_id' => $organizationExternalId !== '' ? $organizationExternalId : null,
                            'organization_name' => $organizationName !== '' ? mb_substr($organizationName, 0, 255) : null,
                            'account' => mb_substr($account, 0, 64),
                            'currency' => $currency,
                            ...$amounts,
                            'source' => '1c',
                            'source_file' => '1c:settlements',
                            'payload' => $item,
                            'imported_at' => $now,
                            'source_row_hash' => $sourceRowHash,
                        ];

                        $existing = TenantSettlementBalance::query()
                            ->where('market_id', $marketId)
                            ->where('account', $account)
                            ->whereDate('period_from', $periodFrom)
                            ->whereDate('period_to', $periodTo)
                            ->where('source_row_hash', $sourceRowHash)
                            ->first();

                        if ($existing) {
                            $existing->forceFill($values)->save();
                            $touchedBalanceIds[] = (int) $existing->id;
                            $updated++;
                        } else {
                            $balance = TenantSettlementBalance::query()->create($values);
                            $touchedBalanceIds[] = (int) $balance->id;
                            $inserted++;
                        }
                    }

                    if (! $snapshotSyncSkipped && $touchedBalanceIds !== []) {
                        $snapshotDeleted = TenantSettlementBalance::query()
                            ->where('market_id', $marketId)
                            ->where('account', $accountScope)
                            ->whereDate('period_from', $periodFrom)
                            ->whereDate('period_to', $periodTo)
                            ->where('source', '1c')
                            ->where('source_file', '1c:settlements')
                            ->whereNotIn('id', array_values(array_unique($touchedBalanceIds)))
                            ->delete();
                    } else {
                        $snapshotSyncSkipped = true;
                    }

                    DB::commit();

                    $warnings = [
                        'snapshot_deleted' => $snapshotDeleted,
                        'snapshot_sync_skipped' => $snapshotSyncSkipped,
                    ];

                    $payload = [
                        'status' => 'ok',
                        'http_status' => Response::HTTP_OK,
                        'calculated_at' => (string) $validated['calculated_at'],
                        'period_from' => $periodFrom,
                        'period_to' => $periodTo,
                        'account' => $accountScope,
                        'received' => $received,
                        'inserted' => $inserted,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'linked_contracts' => $linkedContracts,
                        'unresolved_contracts' => $unresolvedContracts,
                        'tenants_created' => $tenantsCreated,
                        'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                        'warnings' => $warnings,
                        'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds(now())),
                    ];

                    $exchange->markFinishedOk(array_merge((array) ($exchange->payload ?? []), $payload));
                    $exchange->save();

                    $this->writeImportLog([
                        'status' => 'ok',
                        'endpoint' => self::ENDPOINT,
                        'http_status' => Response::HTTP_OK,
                        'market_id' => $marketId,
                        'integration_id' => (int) $integration->id,
                        'received' => $received,
                        'inserted' => $inserted,
                        'skipped' => $skipped,
                        'calculated_at' => (string) $validated['calculated_at'],
                        'duration_ms' => $payload['duration_ms'],
                        'meta' => [
                            'period_from' => $periodFrom,
                            'period_to' => $periodTo,
                            'account' => $accountScope,
                            'updated' => $updated,
                            'linked_contracts' => $linkedContracts,
                            'unresolved_contracts' => $unresolvedContracts,
                            'tenants_created' => $tenantsCreated,
                            'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                            'snapshot_deleted' => $snapshotDeleted,
                            'snapshot_sync_skipped' => $snapshotSyncSkipped,
                        ],
                    ]);

                    return response()->json($payload);
                },
            );
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($exchange && $exchange->status !== IntegrationExchange::STATUS_ERROR) {
                $exchange->markFinishedError($e->getMessage(), array_merge((array) ($exchange->payload ?? []), [
                    'status' => 'error',
                    'http_status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                    'error' => $e->getMessage(),
                    'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds(now())),
                ]));
                $exchange->save();
            }

            throw $e;
        }
    }

    private function resolveContract(int $marketId, int $tenantId, string $contractExternalId): ?TenantContract
    {
        if ($contractExternalId === '') {
            return null;
        }

        return TenantContract::query()
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('external_id', $contractExternalId)
            ->first();
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

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
     * @param  array<string, string>  $amounts
     */
    private function makeSettlementHash(
        int $marketId,
        string $periodFrom,
        string $periodTo,
        string $tenantExternalId,
        string $contractExternalId,
        string $contractName,
        string $settlementDocumentExternalId,
        string $settlementDocumentName,
        string $organizationExternalId,
        string $organizationName,
        string $account,
        string $currency,
        array $amounts,
    ): string {
        return hash('sha256', implode('|', [
            (string) $marketId,
            $periodFrom,
            $periodTo,
            $tenantExternalId,
            $contractExternalId,
            $contractName,
            $settlementDocumentExternalId,
            $settlementDocumentName,
            $organizationExternalId,
            $organizationName,
            $account,
            $currency,
            $amounts['opening_debit'],
            $amounts['opening_credit'],
            $amounts['turnover_debit'],
            $amounts['turnover_credit'],
            $amounts['closing_debit'],
            $amounts['closing_credit'],
        ]));
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
            // Import logging must not break the 1C exchange.
        }
    }
}
