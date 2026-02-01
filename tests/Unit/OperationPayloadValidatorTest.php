<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Services\Operations\OperationPayloadValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationPayloadValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validates_tenant_switch_payload(): void
    {
        $payload = OperationPayloadValidator::normalize(OperationType::TENANT_SWITCH, [
            'market_space_id' => 10,
            'from_tenant_id' => 1,
            'to_tenant_id' => 2,
            'reason' => 'Перевод',
        ]);

        $this->assertSame(10, $payload['market_space_id']);
        $this->assertSame(1, $payload['from_tenant_id']);
        $this->assertSame(2, $payload['to_tenant_id']);
        $this->assertSame('Перевод', $payload['reason']);
    }

    public function test_validates_accrual_adjustment_payload(): void
    {
        $payload = OperationPayloadValidator::normalize(OperationType::ACCRUAL_ADJUSTMENT, [
            'market_space_id' => 5,
            'amount_delta' => -1200.5,
            'reason' => 'Корректировка',
        ]);

        $this->assertSame(5, $payload['market_space_id']);
        $this->assertSame(-1200.5, $payload['amount_delta']);
        $this->assertSame('Корректировка', $payload['reason']);
    }
}
