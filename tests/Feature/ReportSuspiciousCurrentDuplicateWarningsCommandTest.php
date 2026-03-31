<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationExchange;
use App\Models\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSuspiciousCurrentDuplicateWarningsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_duplicate_warnings_outputs_only_exchanges_with_warnings_by_default(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        IntegrationExchange::query()->create([
            'market_id' => $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_OK,
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute()->addSeconds(5),
            'payload' => [
                'received' => 2,
                'created' => 1,
                'updated' => 1,
                'warnings' => [
                    'suspected_current_duplicate_contract_groups' => 1,
                    'suspected_current_duplicate_contract_rows' => 2,
                    'suspected_current_duplicate_contracts' => [
                        'count' => 1,
                        'rows' => 2,
                        'samples' => [
                            [
                                'tenant_id' => 10,
                                'market_space_id' => 75,
                                'place_token' => 'P/75',
                                'document_date' => '2025-04-01',
                                'external_ids' => ['contract-dup-001', 'contract-dup-002'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        IntegrationExchange::query()->create([
            'market_id' => $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_OK,
            'started_at' => now(),
            'finished_at' => now()->addSeconds(5),
            'payload' => [
                'received' => 1,
                'created' => 1,
            ],
        ]);

        $this->artisan('contracts:report-duplicate-warnings', [
            '--market' => $market->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"reported_exchange_count": 1')
            ->expectsOutputToContain('"warning_group_count": 1')
            ->expectsOutputToContain('"warning_row_count": 2')
            ->expectsOutputToContain('"place_token": "P/75"')
            ->doesntExpectOutputToContain('"received": 1');
    }

    public function test_report_duplicate_warnings_can_include_all_or_only_sampled_exchanges(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        IntegrationExchange::query()->create([
            'market_id' => $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_OK,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinutes(2)->addSeconds(5),
            'payload' => [
                'warnings' => [
                    'suspected_current_duplicate_contract_groups' => 1,
                    'suspected_current_duplicate_contract_rows' => 3,
                    'suspected_current_duplicate_contracts' => [
                        'count' => 1,
                        'rows' => 3,
                        'samples' => [],
                    ],
                ],
            ],
        ]);

        IntegrationExchange::query()->create([
            'market_id' => $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_OK,
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute()->addSeconds(5),
            'payload' => [
                'warnings' => [
                    'suspected_current_duplicate_contract_groups' => 1,
                    'suspected_current_duplicate_contract_rows' => 2,
                    'suspected_current_duplicate_contracts' => [
                        'count' => 1,
                        'rows' => 2,
                        'samples' => [
                            ['place_token' => 'P/24'],
                        ],
                    ],
                ],
            ],
        ]);

        IntegrationExchange::query()->create([
            'market_id' => $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_OK,
            'started_at' => now(),
            'finished_at' => now()->addSeconds(5),
            'payload' => [],
        ]);

        $this->artisan('contracts:report-duplicate-warnings', [
            '--market' => $market->id,
            '--all' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"reported_exchange_count": 3')
            ->expectsOutputToContain('"warning_sample_count": 1');

        $this->artisan('contracts:report-duplicate-warnings', [
            '--market' => $market->id,
            '--with-samples-only' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"reported_exchange_count": 1')
            ->expectsOutputToContain('"place_token": "P/24"')
            ->doesntExpectOutputToContain('"warning_row_count": 3');
    }
}
