<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\IntegrationExchange;
use App\Models\MarketIntegration;
use App\Models\TenantContract;
use App\Models\TenantPayment;
use App\Services\Tenants\OneCTenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentController extends Controller
{
    private const ENDPOINT = '/api/1c/payments';
    private const ENTITY_TYPE = 'payments';

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
                    'items' => ['required', 'array', 'min:1'],

                    'items.*.tenant_external_id' => ['required', 'string', 'max:255'],
                    'items.*.contract_external_id' => ['nullable', 'string', 'max:255'],
                    'items.*.payment_external_id' => ['nullable', 'string', 'max:255'],
                    'items.*.document_number' => ['nullable', 'string', 'max:255'],
                    'items.*.payment_date' => ['required', 'date_format:Y-m-d'],
                    'items.*.organization_external_id' => ['nullable', 'string', 'max:255'],
                    'items.*.organization_name' => ['nullable', 'string', 'max:255'],
                    'items.*.account' => ['nullable', 'string', 'max:64'],
                    'items.*.amount' => ['required', 'numeric'],
                    'items.*.currency' => ['nullable', 'string', 'size:3'],
                    'items.*.purpose' => ['nullable', 'string'],

                    'items.*.inn' => ['nullable', 'string', 'max:32'],
                    'items.*.kpp' => ['nullable', 'string', 'max:32'],
                    'items.*.tenant_name' => ['nullable', 'string', 'max:255'],
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

            $received = count($validated['items']);
            $inserted = 0;
            $skipped = 0;
            $linkedContracts = 0;
            $unresolvedContracts = 0;
            $tenantsCreated = 0;
            $tenantsUpdatedByInn = 0;
            $now = now();

            DB::beginTransaction();

            foreach ($validated['items'] as $item) {
                $tenantExternalId = trim((string) $item['tenant_external_id']);
                $contractExternalId = trim((string) ($item['contract_external_id'] ?? ''));
                $paymentExternalId = trim((string) ($item['payment_external_id'] ?? ''));
                $documentNumber = trim((string) ($item['document_number'] ?? ''));
                $paymentDate = trim((string) $item['payment_date']);
                $organizationExternalId = trim((string) ($item['organization_external_id'] ?? ''));
                $organizationName = trim((string) ($item['organization_name'] ?? ''));
                $account = trim((string) ($item['account'] ?? ''));
                $currency = strtoupper(trim((string) ($item['currency'] ?? 'RUB')));
                $purpose = trim((string) ($item['purpose'] ?? ''));
                $amount = $this->normalizeMoney($item['amount']);

                if ($currency === '') {
                    $currency = 'RUB';
                }

                $tenantResolution = $tenantResolver->resolve(
                    $marketId,
                    $tenantExternalId,
                    $item,
                    'payments',
                    $now,
                    ['activate_resolved_tenant' => true],
                );

                $tenant = $tenantResolution['tenant'];

                if (! $tenant) {
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

                $sourceRowHash = $this->makePaymentHash(
                    $marketId,
                    $tenantExternalId,
                    $contractExternalId,
                    $paymentExternalId,
                    $documentNumber,
                    $paymentDate,
                    $organizationExternalId,
                    $organizationName,
                    $account,
                    $currency,
                    $amount,
                    $purpose,
                );

                $existing = TenantPayment::query()
                    ->where('market_id', $marketId)
                    ->where('source_row_hash', $sourceRowHash)
                    ->first();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                TenantPayment::query()->create([
                    'market_id' => $marketId,
                    'tenant_id' => (int) $tenant->id,
                    'tenant_contract_id' => $contract?->id,
                    'tenant_external_id' => $tenantExternalId,
                    'contract_external_id' => $contractExternalId !== '' ? $contractExternalId : null,
                    'payment_external_id' => $paymentExternalId !== '' ? $paymentExternalId : null,
                    'document_number' => $documentNumber !== '' ? $documentNumber : null,
                    'payment_date' => $paymentDate,
                    'organization_external_id' => $organizationExternalId !== '' ? $organizationExternalId : null,
                    'organization_name' => $organizationName !== '' ? $organizationName : null,
                    'account' => $account !== '' ? $account : null,
                    'amount' => $amount,
                    'currency' => $currency,
                    'purpose' => $purpose !== '' ? $purpose : null,
                    'payload' => $item,
                    'imported_at' => $now,
                    'source_row_hash' => $sourceRowHash,
                ]);

                $inserted++;
            }

            DB::commit();

            $payload = [
                'status' => 'ok',
                'http_status' => Response::HTTP_OK,
                'calculated_at' => (string) $validated['calculated_at'],
                'received' => $received,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'linked_contracts' => $linkedContracts,
                'unresolved_contracts' => $unresolvedContracts,
                'tenants_created' => $tenantsCreated,
                'tenants_updated_by_inn' => $tenantsUpdatedByInn,
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
                    'linked_contracts' => $linkedContracts,
                    'unresolved_contracts' => $unresolvedContracts,
                    'tenants_created' => $tenantsCreated,
                    'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                ],
            ]);

            return response()->json($payload);
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

    private function makePaymentHash(
        int $marketId,
        string $tenantExternalId,
        string $contractExternalId,
        string $paymentExternalId,
        string $documentNumber,
        string $paymentDate,
        string $organizationExternalId,
        string $organizationName,
        string $account,
        string $currency,
        string $amount,
        string $purpose,
    ): string {
        return hash('sha256', implode('|', [
            (string) $marketId,
            $tenantExternalId,
            $contractExternalId,
            $paymentExternalId,
            $documentNumber,
            $paymentDate,
            $organizationExternalId,
            $organizationName,
            $account,
            $currency,
            $amount,
            $purpose,
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
