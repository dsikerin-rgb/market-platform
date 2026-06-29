<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DemoPilotDataBuilder;
use Tests\TestCase;

class DemoPilotDataBuilderTest extends TestCase
{
    public function test_builds_stable_synthetic_dataset_without_database_writes(): void
    {
        $builder = app(DemoPilotDataBuilder::class);

        $dataSet = $builder->build('pilot-alpha', 'pilot.example.test');

        self::assertSame('pilot-alpha', $dataSet['metadata']['market_slug']);
        self::assertSame('pilot.example.test', $dataSet['metadata']['email_domain']);
        self::assertFalse($dataSet['metadata']['external_integrations_enabled']);
        self::assertSame('pilot-alpha', $dataSet['market']['slug']);
        self::assertSame('disabled', $dataSet['integrations']['one_c']);

        self::assertSame([
            'market' => 1,
            'users' => 4,
            'locations' => 2,
            'spaces' => 5,
            'map_shapes' => 5,
            'tenants' => 4,
            'contracts' => 4,
            'accruals' => 4,
            'payments' => 3,
            'marketplace_categories' => 3,
            'marketplace_products' => 4,
            'announcements' => 1,
        ], $builder->counts($dataSet));
    }

    public function test_all_reference_keys_point_to_declared_demo_records(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build();

        $tenantKeys = $this->keys($dataSet['tenants']);
        $spaceKeys = $this->keys($dataSet['spaces']);
        $contractKeys = $this->keys($dataSet['contracts']);
        $categoryKeys = $this->keys($dataSet['marketplace_categories']);

        foreach ($dataSet['users'] as $user) {
            if (($user['tenant_key'] ?? null) !== null) {
                self::assertContains($user['tenant_key'], $tenantKeys);
            }
        }

        foreach ($dataSet['spaces'] as $space) {
            if (($space['tenant_key'] ?? null) !== null) {
                self::assertContains($space['tenant_key'], $tenantKeys);
            }
        }

        foreach ($dataSet['map_shapes'] as $shape) {
            self::assertContains($shape['market_space_key'], $spaceKeys);
        }

        foreach ($dataSet['contracts'] as $contract) {
            self::assertContains($contract['tenant_key'], $tenantKeys);
            self::assertContains($contract['market_space_key'], $spaceKeys);
        }

        foreach ($dataSet['map_shapes'] as $shape) {
            self::assertContains($shape['market_space_key'], $spaceKeys);
            self::assertGreaterThanOrEqual(3, count($shape['polygon']));
        }

        foreach ($dataSet['accruals'] as $accrual) {
            self::assertContains($accrual['tenant_key'], $tenantKeys);
            self::assertContains($accrual['tenant_contract_key'], $contractKeys);
            self::assertContains($accrual['market_space_key'], $spaceKeys);
        }

        foreach ($dataSet['payments'] as $payment) {
            self::assertContains($payment['tenant_key'], $tenantKeys);
            self::assertContains($payment['tenant_contract_key'], $contractKeys);
        }

        foreach ($dataSet['marketplace_products'] as $product) {
            self::assertContains($product['tenant_key'], $tenantKeys);
            self::assertContains($product['market_space_key'], $spaceKeys);
            self::assertContains($product['category_key'], $categoryKeys);
        }
    }

    public function test_visible_demo_content_is_localized_for_russian_market(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build();

        self::assertSame('Демо-рынок Центральный', $dataSet['market']['name']);
        self::assertSame('г. Новосибирск, ул. Рыночная, 1', $dataSet['market']['address']);
        self::assertSame('Анна Волкова', $dataSet['users'][0]['name']);
        self::assertSame('Основной павильон', $dataSet['locations'][0]['name']);
        self::assertSame('Продукты у дома', $dataSet['spaces'][1]['display_name']);
        self::assertSame('space-a-02', $dataSet['map_shapes'][1]['market_space_key']);
        self::assertCount(4, $dataSet['map_shapes'][1]['polygon']);
        self::assertSame('ООО "Продукты у дома"', $dataSet['tenants'][1]['name']);
        self::assertSame('demo-grocery', $dataSet['tenants'][1]['slug']);
        self::assertSame('Бакалея', $dataSet['marketplace_categories'][1]['name']);
        self::assertSame('grocery', $dataSet['marketplace_categories'][1]['slug']);
        self::assertSame('Мед цветочный', $dataSet['marketplace_products'][1]['title']);
        self::assertSame('demo-honey-jar', $dataSet['marketplace_products'][1]['slug']);
        self::assertSame('Ярмарка выходного дня', $dataSet['announcements'][0]['title']);
        self::assertSame('demo-weekend-market', $dataSet['announcements'][0]['slug']);
    }

    public function test_normalizes_empty_overrides_to_safe_defaults(): void
    {
        $dataSet = app(DemoPilotDataBuilder::class)->build('  ', '@');

        self::assertSame('demo-market', $dataSet['metadata']['market_slug']);
        self::assertSame('demo.marketuchet.local', $dataSet['metadata']['email_domain']);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function keys(array $records): array
    {
        return array_values(array_map(
            static fn (array $record): string => (string) $record['key'],
            $records,
        ));
    }
}
