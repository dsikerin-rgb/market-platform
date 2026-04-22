<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContractDebt;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiReviewAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_pack_includes_other_spaces_for_same_tenant(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Зоомир ООО',
            'short_name' => 'Зоомир',
            'external_id' => 'tenant-7',
            'is_active' => true,
        ]));

        $currentSpace = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'П/3',
            'code' => 'p-3',
            'display_name' => 'Зоомир',
            'status' => 'vacant',
            'map_review_status' => 'conflict',
            'is_active' => true,
        ]));

        $canonicalSpace = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'П3/1',
            'code' => 'p3-1',
            'display_name' => 'Зоомир',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $canonicalSpace->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 1, 'y' => 1],
                ['x' => 2, 'y' => 1],
                ['x' => 2, 'y' => 2],
            ],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        TenantContract::withoutEvents(fn () => TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonicalSpace->id,
            'external_id' => 'contract-573',
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_AUTO,
            'number' => '573',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'ends_at' => null,
            'is_active' => true,
        ]));

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonicalSpace->id,
            'period' => '2026-04-01',
            'source' => '1c',
            'total_with_vat' => 11761.57,
        ]);

        ContractDebt::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_external_id' => 'tenant-7',
            'contract_external_id' => 'contract-573',
            'period' => '2026-04',
            'accrued_amount' => 11761.57,
            'paid_amount' => 5000,
            'debt_amount' => 6761.57,
            'calculated_at' => '2026-04-10 12:00:00',
            'source' => '1c',
            'currency' => 'RUB',
            'hash' => sha1('contract-573-2026-04'),
        ]);

        $pack = app(AiContextPackBuilder::class)->build((int) $currentSpace->id, (int) $market->id);

        $this->assertSame(1, $pack['tenant_context']['other_spaces_total']);
        $this->assertCount(1, $pack['tenant_context']['other_spaces']);
        $this->assertSame(0, $pack['accrual_context']['count']);
        $this->assertSame('1.2.0', $pack['meta']['context_pack_version']);

        $otherSpace = $pack['tenant_context']['other_spaces'][0];

        $this->assertSame((int) $canonicalSpace->id, $otherSpace['id']);
        $this->assertSame('П3/1', $otherSpace['number']);
        $this->assertTrue($otherSpace['has_map_shape']);
        $this->assertTrue($otherSpace['has_exact_contract_link']);
        $this->assertSame(1, $otherSpace['contracts_count']);
        $this->assertSame(1, $otherSpace['accruals_count']);
        $this->assertSame('2026-04-01', $otherSpace['latest_accrual_period']);

        $relations = $pack['relation_context'];
        $this->assertSame((int) $currentSpace->id, $relations['current_space']['id']);
        $this->assertSame(0, $relations['current_space']['relation_counts']['contracts']);
        $this->assertSame((int) $canonicalSpace->id, $relations['likely_canonical_candidate_id']);
        $this->assertStringContainsString('больше подтверждённых связей', $relations['duplicate_review_hint']);

        $candidateRelations = $relations['same_tenant_candidates'][0];
        $this->assertSame((int) $canonicalSpace->id, $candidateRelations['id']);
        $this->assertSame(1, $candidateRelations['relation_counts']['map_shapes']);
        $this->assertSame(1, $candidateRelations['relation_counts']['contracts']);
        $this->assertSame(1, $candidateRelations['relation_counts']['accruals']);
        $this->assertSame(6761.57, $candidateRelations['relation_counts']['debt_total']);
        $this->assertGreaterThan($relations['current_space']['canonical_score'], $candidateRelations['canonical_score']);
    }

    public function test_context_pack_separates_active_contract_contour_from_historical_tail(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Арендатор Тест ООО',
            'short_name' => 'Арендатор Тест',
            'external_id' => 'tenant-active',
            'is_active' => true,
        ]));

        $space = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'A-17',
            'code' => 'a-17',
            'display_name' => 'Арендатор Тест',
            'status' => 'occupied',
            'map_review_status' => 'conflict',
            'is_active' => true,
        ]));

        TenantContract::withoutEvents(fn () => TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-active-17',
            'number' => 'A-17-ACT',
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => null,
            'is_active' => true,
        ]));

        TenantContract::withoutEvents(fn () => TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-old-17',
            'number' => 'A-17-OLD',
            'status' => 'terminated',
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subMonths(2),
            'is_active' => false,
        ]));

        ContractDebt::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_external_id' => 'tenant-active',
            'contract_external_id' => 'contract-active-17',
            'period' => '2026-04',
            'accrued_amount' => 5000,
            'paid_amount' => 0,
            'debt_amount' => 5000,
            'calculated_at' => '2026-04-20 12:00:00',
            'source' => '1c',
            'currency' => 'RUB',
            'hash' => sha1('contract-active-17-2026-04'),
        ]);

        ContractDebt::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_external_id' => 'tenant-active',
            'contract_external_id' => 'contract-old-17',
            'period' => '2025-12',
            'accrued_amount' => 9000,
            'paid_amount' => 0,
            'debt_amount' => 9000,
            'calculated_at' => '2026-04-20 12:00:00',
            'source' => '1c',
            'currency' => 'RUB',
            'hash' => sha1('contract-old-17-2025-12'),
        ]);

        $pack = app(AiContextPackBuilder::class)->build((int) $space->id, (int) $market->id);

        $this->assertSame(2, count($pack['tenant_context']['contracts']));
        $this->assertSame(1, $pack['tenant_context']['contract_contour']['active_current_total']);
        $this->assertSame(1, $pack['tenant_context']['contract_contour']['historical_total']);
        $this->assertTrue($pack['tenant_context']['contract_contour']['has_historical_tail']);
        $this->assertSame('contract-active-17', $pack['tenant_context']['contract_contour']['active_current_contracts'][0]['external_id']);
        $this->assertSame('contract-old-17', $pack['tenant_context']['contract_contour']['historical_contracts'][0]['external_id']);
        $this->assertSame(5000.0, $pack['debt_context']['total_debt']);
        $this->assertSame(1, $pack['relation_context']['current_space']['relation_counts']['contracts']);
        $this->assertSame(1, $pack['relation_context']['current_space']['relation_counts']['historical_contracts']);
    }

    public function test_review_service_builds_prompt_with_contract_contour_and_relation_context(): void
    {
        $pack = [
            'map_review_status' => 'conflict',
            'space_snapshot' => [
                'id' => 230,
                'number' => 'П/3',
                'display_name' => 'Зоомир',
                'status' => 'vacant',
                'area_sqm' => 185.7,
                'has_map_shape' => true,
            ],
            'tenant_context' => [
                'has_tenant' => true,
                'tenant' => ['display_name' => 'Зоомир ООО'],
                'contracts' => [],
                'other_spaces_total' => 1,
                'other_spaces' => [[
                    'id' => 231,
                    'number' => 'П3/1',
                    'display_name' => 'Зоомир',
                    'status' => 'occupied',
                    'is_active' => true,
                    'has_map_shape' => true,
                    'has_exact_contract_link' => true,
                    'contracts_count' => 1,
                    'accruals_count' => 1,
                    'latest_accrual_period' => '2026-04-01',
                ]],
                'contract_contour' => [
                    'active_current_total' => 0,
                    'historical_total' => 1,
                    'has_historical_tail' => true,
                    'active_current_contracts' => [],
                    'historical_contracts' => [[
                        'id' => 99,
                        'contract_number' => 'OLD-99',
                        'status' => 'terminated',
                        'is_active' => false,
                        'start_date' => '2025-01-01',
                        'end_date' => '2025-12-31',
                    ]],
                ],
            ],
            'accrual_context' => [
                'count' => 0,
                'latest_period' => null,
                'latest_total_with_vat' => null,
                'latest_source' => null,
            ],
            'debt_context' => [
                'debt_status' => 'green',
                'debt_label' => 'Нет задолженности',
                'debt_scope' => 'tenant_fallback',
                'total_debt' => null,
                'overdue_days' => null,
                'source_marker' => 'tenant-level debt_status',
                'severity' => 0,
                'mode' => 'auto',
            ],
            'relation_context' => [
                'current_space' => [
                    'id' => 230,
                    'number' => 'П/3',
                    'display_name' => 'Зоомир',
                    'status' => 'vacant',
                    'is_active' => true,
                    'canonical_score' => 0,
                    'relation_counts' => [
                        'map_shapes' => 0,
                        'contracts' => 0,
                        'historical_contracts' => 1,
                        'accruals' => 0,
                        'debt_total' => null,
                        'cabinet_links' => 0,
                        'tenant_bindings' => 0,
                        'products' => 0,
                    ],
                ],
                'same_tenant_candidates' => [],
                'likely_canonical_candidate_id' => null,
                'duplicate_review_hint' => 'Нужна ручная проверка связей.',
            ],
            'review_history' => [],
            'reviewer_note' => null,
            'decision_options' => [
                'relevant_decisions' => [
                    ['label' => 'Конфликт по месту', 'is_applied' => false, 'is_observed' => true],
                    ['label' => 'На месте другой арендатор', 'is_applied' => false, 'is_observed' => true],
                    ['label' => 'Отметить место как свободное', 'is_applied' => true, 'is_observed' => false],
                ],
            ],
        ];

        $messages = app(AiReviewService::class)->buildMessagesForPack($pack);

        $this->assertStringContainsString('[contract_contour]', $messages['user']);
        $this->assertStringContainsString('historical_total: 1', $messages['user']);
        $this->assertStringContainsString('[relation_context]', $messages['user']);
        $this->assertStringContainsString('точная связь с местом не подтверждена', $messages['system']);
        $this->assertStringContainsString('Do not treat other places of the same tenant as automatic proof of a duplicate.', $messages['system']);
    }

    public function test_validate_safety_blocks_current_place_mutations_for_tenant_fallback(): void
    {
        $service = app(AiReviewService::class);
        $method = new \ReflectionMethod($service, 'validateSafety');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'summary' => 'Кейс выглядит спорным.',
            'why_flagged' => 'Точная связь с местом не подтверждена.',
            'recommended_next_step' => 'Отметить место как свободное и применить уточнение.',
            'risk_score' => 9,
            'confidence' => 0.8,
        ], 'conflict', 'tenant_fallback');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tenant_fallback', (string) $result['error']);
    }

    public function test_validate_safety_allows_analysis_guidance_for_tenant_fallback(): void
    {
        $service = app(AiReviewService::class);
        $method = new \ReflectionMethod($service, 'validateSafety');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'summary' => 'Есть риск дубля места.',
            'why_flagged' => 'Статус показан по арендатору, а не по месту.',
            'recommended_next_step' => 'Не подтверждать текущее место. Сравнить другие места арендатора в этом рынке, выбрать каноническое место и только после этого передать кейс на ручную проверку.',
            'risk_score' => 8,
            'confidence' => 0.82,
        ], 'conflict', 'tenant_fallback');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }
}
