<?php

# app/Http/Controllers/Api/OneCTenantController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\MarketIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OneCTenantController extends Controller
{
    /**
     * POST /api/one-c/tenants
     * Приём и синхронизация арендаторов от 1С
     */
    public function sync(Request $request)
    {
        // 1. Проверка токена
        $token = $this->extractToken($request);
        if (!$token) {
            return response()->json([
                'error' => 'Missing authorization token'
            ], 401);
        }

        $integration = MarketIntegration::where('token', $token)->first();
        if (!$integration) {
            Log::channel('one_c')->warning('Invalid token attempt', ['token' => substr($token, 0, 10) . '...']);
            return response()->json([
                'error' => 'Invalid or expired token'
            ], 401);
        }

        $marketId = $integration->market_id;

        // 2. Валидация payload
        $validated = Validator::make($request->all(), [
            'tenants' => 'required|array|min:1',
            'tenants.*.external_id' => 'required|string|max:255',
            'tenants.*.name' => 'required|string|max:255',
            'tenants.*.inn' => 'required|string|max:12',
            'tenants.*.kpp' => 'nullable|string|max:9',
            'tenants.*.ogrn' => 'nullable|string|max:15',
            'tenants.*.phone' => 'nullable|string|max:20',
            'tenants.*.email' => 'nullable|email',
            'tenants.*.contact_person' => 'nullable|string|max:255',
            'tenants.*.type' => 'nullable|string|max:50',
            'tenants.*.country' => 'nullable|string|max:100',
        ]);

        if ($validated->fails()) {
            Log::channel('one_c')->warning('Validation failed', [
                'errors' => $validated->errors(),
                'market_id' => $marketId
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validated->errors()
            ], 422);
        }

        // 3. Обработка арендаторов (upsert)
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];

        DB::beginTransaction();
        try {
            foreach ($request->input('tenants') as $data) {
                try {
                    $tenant = Tenant::updateOrCreate(
                        [
                            'external_id' => $data['external_id'],
                            'market_id' => $marketId
                        ],
                        [
                            'market_id' => $marketId,
                            'name' => $data['name'],
                            'inn' => $data['inn'],
                            'kpp' => $data['kpp'] ?? null,
                            'ogrn' => $data['ogrn'] ?? null,
                            'phone' => $data['phone'] ?? null,
                            'email' => $data['email'] ?? null,
                            'contact_person' => $data['contact_person'] ?? null,
                            'type' => $data['type'] ?? null,
                            'status' => 'active',
                            'is_active' => true,
                            'one_c_data' => [
                                'external_id' => $data['external_id'],
                                'country' => $data['country'] ?? 'RUSSIA',
                                'synced_at' => now()->toIso8601String()
                            ]
                        ]
                    );

                    if ($tenant->wasRecentlyCreated) {
                        $results['created']++;
                    } else {
                        $results['updated']++;
                    }

                    Log::channel('one_c')->info('Tenant synced', [
                        'tenant_id' => $tenant->id,
                        'external_id' => $data['external_id'],
                        'name' => $data['name'],
                        'action' => $tenant->wasRecentlyCreated ? 'created' : 'updated'
                    ]);

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'external_id' => $data['external_id'],
                        'error' => $e->getMessage()
                    ];
                    Log::channel('one_c')->error('Tenant sync failed', [
                        'external_id' => $data['external_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'results' => $results
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::channel('one_c')->error('Transaction failed', [
                'error' => $e->getMessage(),
                'market_id' => $marketId
            ]);
            return response()->json([
                'error' => 'Service unavailable'
            ], 503);
        }
    }

    /**
     * Извлечение токена из заголовка Authorization
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        return substr($header, 7);
    }
}