<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\IntegrationExchange;
use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AccrualController extends Controller
{
    private const ENDPOINT = '/api/1c/accruals';
    private const ENTITY_TYPE = 'accruals';

    public function store(Request $request, TenantAccrualContractResolver $contractResolver): JsonResponse
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

                    'items.*.tenant_external_id' => ['required', 'string', 'max:255'],
                    'items.*.contract_external_id' => ['nullable', 'string', 'max:255'],
                    'items.*.period' => ['required', 'regex:/^\d{4}-\d{2}$/'],

                    'items.*.market_space_code' => ['nullable', 'string', 'max:255'],
                    'items.*.source_place_code' => ['nullable', 'string', 'max:255'],
                    'items.*.source_place_name' => ['nullable', 'string', 'max:255'],
                    'items.*.activity_type' => ['nullable', 'string', 'max:255'],
                    'items.*.days' => ['nullable', 'integer', 'min:0'],

                    'items.*.inn' => ['nullable', 'string', 'max:32'],
                    'items.*.kpp' => ['nullable', 'string', 'max:32'],
                    'items.*.tenant_name' => ['nullable', 'string', 'max:255'],

                    'items.*.area_sqm' => ['nullable', 'numeric'],
                    'items.*.rent_rate' => ['nullable', 'numeric'],
                    'items.*.rent_amount' => ['nullable', 'numeric'],
                    'items.*.management_fee' => ['nullable', 'numeric'],
                    'items.*.utilities_amount' => ['nullable', 'numeric'],
                    'items.*.electricity_amount' => ['nullable', 'numeric'],
                    'items.*.total_no_vat' => ['nullable', 'numeric'],
                    'items.*.vat_rate' => ['nullable', 'numeric'],
                    'items.*.total_with_vat' => ['nullable', 'numeric'],
                    'items.*.cash_amount' => ['nullable', 'numeric'],
                    'items.*.currency' => ['nullable', 'string', 'size:3'],
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

            [$spaceIndex, $keysWithCollisions] = $this->buildSpaceIndex($marketId);
            $calculatedAt = (string) $validated['calculated_at'];
            $received = count($validated['items']);
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $tenantsCreated = 0;
            $tenantsUpdatedByInn = 0;
            $linkedContracts = 0;
            $exactContractLinks = 0;
            $resolvedContractLinks = 0;
            $ambiguousContracts = 0;
            $resolvedSpaces = 0;
            $unresolvedContracts = 0;
            $unresolvedSpaces = 0;
            $notFoundTenants = [];
            $now = now();

            DB::beginTransaction();

            foreach ($validated['items'] as $index => $item) {
                $tenantExternalId = trim((string) $item['tenant_external_id']);
                $contractExternalId = trim((string) ($item['contract_external_id'] ?? ''));
                $periodYm = trim((string) $item['period']);
                $periodDate = $periodYm . '-01';
                $spaceKey = trim((string) ($item['market_space_code'] ?? $item['source_place_code'] ?? ''));
                $sourcePlaceCode = trim((string) ($item['source_place_code'] ?? $spaceKey));
                $sourcePlaceName = trim((string) ($item['source_place_name'] ?? ''));
                $activityType = trim((string) ($item['activity_type'] ?? ''));

                if ($tenantExternalId === '' || $periodYm === '') {
                    $skipped++;
                    continue;
                }

                $tenant = $this->resolveTenant(
                    $marketId,
                    $tenantExternalId,
                    $item,
                    $now,
                    $tenantsCreated,
                    $tenantsUpdatedByInn,
                );

                if (! $tenant) {
                    $notFoundTenants[] = $tenantExternalId;
                    $skipped++;
                    continue;
                }

                $marketSpaceId = null;
                if ($spaceKey !== '') {
                    [$resolvedId, $spaceState] = $this->resolveMarketSpaceId($spaceIndex, $spaceKey);
                    if ($spaceState === 'ok') {
                        $marketSpaceId = $resolvedId;
                        $resolvedSpaces++;
                    } else {
                        $unresolvedSpaces++;
                    }
                }

                $match = $contractResolver->resolveMatch(
                    marketId: $marketId,
                    tenantId: (int) $tenant->id,
                    marketSpaceId: $marketSpaceId,
                    period: CarbonImmutable::createFromFormat('Y-m-d', $periodDate),
                    contractExternalId: $contractExternalId !== '' ? $contractExternalId : null,
                );

                $tenantContractId = $match->tenantContractId;

                if ($match->isLinked()) {
                    $linkedContracts++;

                    if ($match->status === TenantAccrual::CONTRACT_LINK_STATUS_EXACT) {
                        $exactContractLinks++;
                    } else {
                        $resolvedContractLinks++;
                    }
                } elseif ($match->status === TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS) {
                    $ambiguousContracts++;
                    $unresolvedContracts++;
                } elseif ($contractExternalId !== '' || $marketSpaceId) {
                    $unresolvedContracts++;
                }

                $hash = $this->makeAccrualHash(
                    $marketId,
                    $periodYm,
                    $tenantExternalId,
                    $contractExternalId,
                    $spaceKey,
                    $sourcePlaceName,
                    $activityType,
                );

                $exists = DB::table('tenant_accruals')
                    ->where('market_id', $marketId)
                    ->whereDate('period', $periodDate)
                    ->where('source_row_hash', $hash)
                    ->exists();

                DB::table('tenant_accruals')->updateOrInsert(
                    [
                        'market_id' => $marketId,
                        'period' => $periodDate,
                        'source_row_hash' => $hash,
                    ],
                    [
                        'tenant_id' => (int) $tenant->id,
                        'contract_external_id' => $contractExternalId !== '' ? $contractExternalId : null,
                        'tenant_contract_id' => $tenantContractId,
                        'contract_link_status' => $match->status,
                        'contract_link_source' => $match->source,
                        'contract_link_note' => $match->note,
                        'market_space_id' => $marketSpaceId,
                        'source_place_code' => $sourcePlaceCode !== '' ? $sourcePlaceCode : null,
                        'source_place_name' => $sourcePlaceName !== '' ? $sourcePlaceName : null,
                        'activity_type' => $activityType !== '' ? $activityType : null,
                        'days' => $item['days'] ?? null,
                        'area_sqm' => $this->normalizeNumeric($item['area_sqm'] ?? null),
                        'rent_rate' => $this->normalizeNumeric($item['rent_rate'] ?? null),
                        'rent_amount' => $this->normalizeMoney($item['rent_amount'] ?? null),
                        'management_fee' => $this->normalizeMoney($item['management_fee'] ?? null),
                        'utilities_amount' => $this->normalizeMoney($item['utilities_amount'] ?? null),
                        'electricity_amount' => $this->normalizeMoney($item['electricity_amount'] ?? null),
                        'total_no_vat' => $this->normalizeMoney($item['total_no_vat'] ?? null),
                        'vat_rate' => $this->normalizeNumeric($item['vat_rate'] ?? null),
                        'total_with_vat' => $this->normalizeMoney($item['total_with_vat'] ?? null),
                        'cash_amount' => $this->normalizeMoney($item['cash_amount'] ?? null),
                        'currency' => $this->normalizeCurrency($item['currency'] ?? null),
                        'status' => 'imported',
                        'source' => '1c',
                        'source_file' => '1c:accruals',
                        'source_row_number' => $index + 1,
                        'payload' => $this->safeJsonEncode(array_merge($item, ['calculated_at' => $calculatedAt])),
                        'imported_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $exists ? DB::raw('created_at') : $now,
                    ],
                );

                if ($exists) {
                    $updated++;
                } else {
                    $inserted++;
                }
            }

            DB::commit();

            $durationMs = (int) max(0, $startedAt->diffInMilliseconds(now()));
            $warnings = [
                'space_key_collisions' => $keysWithCollisions,
                'contracts_linked' => $linkedContracts,
                'contracts_linked_exact' => $exactContractLinks,
                'contracts_linked_resolved' => $resolvedContractLinks,
                'contracts_ambiguous' => $ambiguousContracts,
                'spaces_resolved' => $resolvedSpaces,
                'contracts_unresolved' => $unresolvedContracts,
                'spaces_unresolved' => $unresolvedSpaces,
            ];

            if ($notFoundTenants !== []) {
                $warnings['not_found_tenants'] = array_values(array_unique($notFoundTenants));
            }

            $response = [
                'status' => 'ok',
                'market_id' => $marketId,
                'received' => $received,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'calculated_at' => $calculatedAt,
                'warnings' => $warnings,
            ];

            $this->writeImportLog([
                'status' => 'ok',
                'endpoint' => self::ENDPOINT,
                'http_status' => Response::HTTP_OK,
                'market_id' => $marketId,
                'integration_id' => (int) $integration->id,
                'received' => $received,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'calculated_at' => $calculatedAt,
                'duration_ms' => $durationMs,
                'meta' => [
                    'updated' => $updated,
                    'tenants_created' => $tenantsCreated,
                    'tenants_updated_by_inn' => $tenantsUpdatedByInn,
                    'warnings' => $warnings,
                    'ip' => $request->ip(),
                ],
            ]);

            if ($exchange) {
                $exchange->status = IntegrationExchange::STATUS_OK;
                $exchange->finished_at = now();
                $exchange->error = null;
                $exchange->payload = array_merge((array) ($exchange->payload ?? []), [
                    'status' => 'ok',
                    'http_status' => Response::HTTP_OK,
                    'received' => $received,
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'calculated_at' => $calculatedAt,
                    'duration_ms' => $durationMs,
                    'warnings' => $warnings,
                ]);
                $exchange->save();
            }

            return response()->json($response);
        } catch (Throwable $e) {
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

    private function resolveTenant(
        int $marketId,
        string $tenantExternalId,
        array $item,
        $now,
        int &$tenantsCreated,
        int &$tenantsUpdatedByInn,
    ): ?Tenant {
        $tenant = Tenant::query()
            ->where('market_id', $marketId)
            ->where('external_id', $tenantExternalId)
            ->first();

        if ($tenant) {
            return $tenant;
        }

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

                $tenantsUpdatedByInn++;

                return $tenantByInn;
            }
        }

        $newTenant = new Tenant();
        $newTenant->market_id = $marketId;
        $newTenant->inn = $inn !== '' ? $inn : null;
        $newTenant->kpp = $kpp !== '' ? $kpp : null;
        $newTenant->name = $tenantName !== '' ? $tenantName : ('1C tenant ' . $tenantExternalId);
        $newTenant->external_id = $tenantExternalId;
        $newTenant->is_active = true;

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $tenantExternalId)) {
            $newTenant->one_c_uid = $tenantExternalId;
        }

        $newTenant->one_c_data = $this->safeJsonEncode([
            'created_from' => 'accruals',
            'first_seen' => $now->toDateTimeString(),
            'last_seen' => $now->toDateTimeString(),
            'inn' => $inn,
            'kpp' => $kpp,
            'tenant_name' => $tenantName,
        ]);

        $newTenant->save();
        $tenantsCreated++;

        return $newTenant;
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

    private function normalizeMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeCurrency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'RUB')));

        return $currency !== '' ? $currency : 'RUB';
    }

    private function makeAccrualHash(
        int $marketId,
        string $periodYm,
        string $tenantExternalId,
        string $contractExternalId,
        string $spaceKey,
        string $sourcePlaceName,
        string $activityType,
    ): string {
        return hash('sha256', implode('|', [
            $marketId,
            $periodYm,
            $tenantExternalId,
            trim($contractExternalId),
            trim($spaceKey),
            trim($sourcePlaceName),
            trim($activityType),
        ]));
    }

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

    private function resolveMarketSpaceId(array $spaceIndex, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, 'not_found'];
        }

        foreach ($this->spaceKeyVariants($raw) as $variant) {
            if (! isset($spaceIndex[$variant])) {
                continue;
            }

            $ids = $spaceIndex[$variant];

            if (count($ids) === 1) {
                return [(int) $ids[0], 'ok'];
            }

            return [null, 'ambiguous'];
        }

        return [null, 'not_found'];
    }

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
            // no-op
        }
    }
}
