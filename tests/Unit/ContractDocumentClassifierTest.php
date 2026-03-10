<?php
# tests/Unit/ContractDocumentClassifierTest.php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantContracts\ContractDocumentClassifier;
use JsonException;
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
    /**
     * @throws JsonException
     */
    public function test_extracts_primary_place_token_without_using_date_fragment(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify(
            json_decode('"\u041f\/59\u0443 \u043e\u0442 01.05.2024"', true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame('primary_contract', $result['category']);
        $this->assertSame('П/59У', $result['place_token']);
        $this->assertSame('2024-05-01', $result['document_date']);
    }

    /**
     * @throws JsonException
     */
    public function test_extracts_compound_primary_place_token_before_date(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify(
            json_decode('"\u0424\/\u041a 8\/1 \u043e\u0442 01.10.2025"', true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame('primary_contract', $result['category']);
        $this->assertSame('Ф/К 8/1', $result['place_token']);
        $this->assertSame('2025-10-01', $result['document_date']);
    }

    /**
     * @throws JsonException
     */
    public function test_extracts_compound_service_place_token_before_date(): void
    {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify(
            json_decode('"\u0423\u0423 \u041e-8\/\u041c 24-25 \u043e\u0442 01.07.2022"', true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame('service_document', $result['category']);
        $this->assertSame('О-8/М 24-25', $result['place_token']);
        $this->assertSame('2022-07-01', $result['document_date']);
    }
}
