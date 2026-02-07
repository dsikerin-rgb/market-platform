<?php
# app/Http/Controllers/Api/OneC/ContractDebtController.php

namespace App\Http\Controllers\Api\OneC;

use App\Http\Controllers\Controller;
use App\Models\MarketIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
            ->where('is_active', true)
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $integration) {
            return response()->json(
                ['message' => 'Invalid or inactive token'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // ⚠️ ВАЖНО:
        // $integration->market_id — это рынок,
        // из которого пришли данные 1С

        return response()->json([
            'status' => 'ok',
            'market_id' => $integration->market_id,
            'message' => 'Token accepted, ready to receive data',
        ]);
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
