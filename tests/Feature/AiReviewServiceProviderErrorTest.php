<?php
# tests/Feature/AiReviewServiceProviderErrorTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\AiReviewService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiReviewServiceProviderErrorTest extends TestCase
{
    public function test_ai_review_service_maps_gigachat_http_402_to_provider_billing(): void
    {
        Cache::flush();

        config()->set('gigachat.auth_key', 'test-auth-key');
        config()->set('gigachat.scope', 'GIGACHAT_API.PERS');
        config()->set('gigachat.model', 'GigaChat-2');
        config()->set('gigachat.verify_ssl', true);

        $http = new \Illuminate\Http\Client\Factory();
        $http->fake([
            \App\Services\Ai\GigaChatClient::authUrl() => Http::response([
                'access_token' => 'test-token',
            ], 200),
            \App\Services\Ai\GigaChatClient::chatUrl() => Http::response([
                'status' => 402,
                'message' => 'Payment Required',
            ], 402),
        ]);

        app()->instance(\Illuminate\Http\Client\Factory::class, $http);
        app()->instance(\App\Services\Ai\AiContextPackBuilder::class, new class
        {
            public function build(int $spaceId, int $marketId): array
            {
                return [
                    'map_review_status' => 'conflict',
                    'space_snapshot' => [
                        'id' => $spaceId,
                        'number' => 'AI-402',
                        'display_name' => 'AI 402',
                        'status' => 'occupied',
                        'area_sqm' => 10,
                        'has_map_shape' => true,
                        'is_active' => true,
                    ],
                    'tenant_context' => [
                        'has_tenant' => true,
                        'tenant' => ['display_name' => 'Tenant 402'],
                        'contracts' => [],
                        'other_spaces_total' => 0,
                        'other_spaces' => [],
                        'contract_contour' => [
                            'active_current_total' => 0,
                            'historical_total' => 0,
                            'has_historical_tail' => false,
                            'active_current_contracts' => [],
                            'historical_contracts' => [],
                        ],
                        'contract_override' => null,
                    ],
                    'accrual_context' => [
                        'count' => 0,
                        'latest_period' => null,
                        'latest_total_with_vat' => null,
                        'latest_source' => null,
                    ],
                    'debt_context' => [
                        'debt_status' => 'none',
                        'debt_label' => 'Нет',
                        'debt_scope' => 'none',
                        'total_debt' => 0,
                        'overdue_days' => 0,
                        'source_marker' => 'none',
                    ],
                    'review_history' => [],
                    'reviewer_note' => '',
                    'relation_context' => [
                        'current_space' => [
                            'id' => $spaceId,
                            'number' => 'AI-402',
                            'display_name' => 'AI 402',
                            'status' => 'occupied',
                            'is_active' => true,
                            'relation_counts' => [],
                            'canonical_score' => 0,
                        ],
                        'same_tenant_candidates' => [],
                        'likely_canonical_candidate_id' => 0,
                        'duplicate_review_hint' => '',
                    ],
                    'decision_options' => [
                        'relevant_decisions' => [
                            [
                                'decision' => 'occupancy_conflict',
                                'label' => 'Conflict',
                                'is_applied' => false,
                                'is_observed' => true,
                            ],
                        ],
                    ],
                    'allowed_actions' => [
                        [
                            'code' => 'occupancy_conflict',
                            'label' => 'Conflict',
                            'category' => 'observed',
                        ],
                    ],
                ];
            }
        });

        $result = app(AiReviewService::class)->getReviewForSpace(89, 1);

        $this->assertNull($result['review']);
        $this->assertSame('provider_billing', $result['error_type']);
        $this->assertFalse(Cache::has('gigachat_connectivity_down:market:1'));
    }
}
