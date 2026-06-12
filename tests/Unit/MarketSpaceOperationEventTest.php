<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\MarketSpaceResource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketSpaceOperationEventTest extends TestCase
{
    public function test_tenant_switch_from_vacant_space_is_rendered_as_occupy_event(): void
    {
        $event = $this->buildOperationEvent(OperationType::TENANT_SWITCH, [
            'from_tenant_id' => null,
            'to_tenant_id' => 25,
        ], [
            25 => 'ООО Тест',
        ]);

        $this->assertSame('Место занято', $event['title']);
        $this->assertSame('Было: не было арендатора. Стало: ООО Тест.', $event['details']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tenantNames
     * @return array{title:string,details:string}
     */
    private function buildOperationEvent(string $type, array $payload, array $tenantNames): array
    {
        $method = new ReflectionMethod(MarketSpaceResource::class, 'buildOperationEvent');
        $method->setAccessible(true);

        return $method->invoke(null, $type, $payload, $tenantNames);
    }
}
