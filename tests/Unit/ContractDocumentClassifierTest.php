<?php
# tests/Unit/ContractDocumentClassifierTest.php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantContracts\ContractDocumentClassifier;
use PHPUnit\Framework\TestCase;

class ContractDocumentClassifierTest extends TestCase
{
    public function test_classifies_primary_contract(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('П/46 от 01.05.2024');

        $this->assertSame('primary_contract', $result['category']);
        $this->assertTrue($result['actionable']);
        $this->assertSame('П/46', $result['place_token']);
        $this->assertSame('2024-05-01', $result['document_date']);
    }

    public function test_classifies_supplemental_document(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('ДС к договору П/53/1 от 12.02.2025');

        $this->assertSame('supplemental_document', $result['category']);
        $this->assertTrue($result['actionable']);
        $this->assertSame('П/53/1', $result['place_token']);
        $this->assertSame('2025-02-12', $result['document_date']);
    }

    public function test_classifies_service_document(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('ОП П/53/1');

        $this->assertSame('service_document', $result['category']);
        $this->assertTrue($result['actionable']);
    }

    public function test_classifies_penalty_document(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('Пени 0,2 % в день');

        $this->assertSame('penalty_document', $result['category']);
        $this->assertFalse($result['actionable']);
        $this->assertNull($result['place_token']);
        $this->assertNull($result['document_date']);
    }

    public function test_classifies_non_rent_document(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('Агентский договор № 01/12 от 01.12.2024');

        $this->assertSame('non_rent_document', $result['category']);
        $this->assertFalse($result['actionable']);
    }

    public function test_classifies_placeholder_document(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify('Без договора');

        $this->assertSame('placeholder_document', $result['category']);
        $this->assertFalse($result['actionable']);
        $this->assertNull($result['place_token']);
        $this->assertNull($result['document_date']);
    }
}
