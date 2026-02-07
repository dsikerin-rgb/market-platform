<?php
# app/Http/Controllers/Api/OneC/ContractDebtController.php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ContractDebtController extends Controller
{
    /**
     * Приём snapshot-ов задолженности из 1С.
     *
     * Ожидается:
     * - Authorization: Bearer <token>
     * - JSON с массивом items
     *
     * Бизнес-логика (сохранение) будет добавлена следующим шагом.
     */
    public function store(Request $request): JsonResponse
    {
        // TODO:
        // 1. Проверка токена (через market_integrations)
        // 2. Валидация payload
        // 3. Идемпотентность (market + contract_external_id + calculated_at)
        // 4. Сохранение snapshot-ов в contract_debts

        return response()->json([
            'status' => 'ok',
            'message' => 'Endpoint is ready to accept data',
        ]);
    }
}
