<?php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\MarketIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractDebtController extends Controller
{
    /**
     * Приём snapshot-ов задолженности из 1С
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Извлекаем Bearer-токен
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Authorization token is missing',
            ], 401);
        }

        /**
         * 2. Пытаемся найти интеграцию
         * Если не нашли — создаём автоматически (staging / MVP)
         */
        $integration = MarketIntegration::query()
            ->where('type', 'one_c')
            ->where('token', $token)
            ->first();

        if (!$integration) {
            $integration = MarketIntegration::create([
                'type'      => 'one_c',
                'token'     => $token,
                'is_active' => true,
                'name'      => '1C integration (auto-created)',
            ]);
        }

        if (!$integration->is_active) {
            return response()->json([
                'message' => 'Integration is disabled',
            ], 403);
        }

        // 3. TODO: валидация payload
        // 4. TODO: идемпотентное сохранение snapshot-ов

        return response()->json([
            'status'  => 'ok',
            'message' => 'Authorized. Endpoint is ready to accept data',
        ]);
    }
}
