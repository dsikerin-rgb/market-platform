<?php
# tests/Feature/DuplicateSpaceResolutionServiceTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Services\MarketMap\DuplicateSpaceResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DuplicateSpaceResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_rejects_accrual_only_canonical_when_duplicate_has_shape_and_contracts(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Барковская Л.С.',
            'is_active' => true,
        ]));

        $duplicate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'ОС11/3',
            'code' => 'os-11-3',
            'display_name' => 'ОС11/3',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        $candidate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'ОС11/3__t114',
            'code' => 'os-11-3-t114',
            'display_name' => 'ОС11/3 / Барковская Л.С.',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
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
            'market_space_id' => $duplicate->id,
            'external_id' => 'contract-os-11-3',
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_AUTO,
            'number' => 'А ОС 11/3 от 01.06.2023',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]));

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $candidate->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 1000,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Основное место не может быть выбрано только по финансовым связям');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $candidate->id,
        );
    }
}
