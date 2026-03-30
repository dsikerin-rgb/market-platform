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

    /**
     * @dataProvider safeEquivalentPlaceTokenProvider
     * @throws JsonException
     */
    public function test_normalizes_confirmed_safe_equivalent_place_tokens(
        string $contractNumber,
        string $expectedToken,
    ): void {
        $classifier = new ContractDocumentClassifier();

        $result = $classifier->classify(
            json_decode($contractNumber, true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame('primary_contract', $result['category']);
        $this->assertSame($expectedToken, $result['place_token']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function safeEquivalentPlaceTokenProvider(): array
    {
        return [
            'П32/1 -> П/32/1' => ['"\u041f32\/1 \u043e\u0442 01.02.2025"', 'П/32/1'],
            'П/32-1 -> П/32/1' => ['"\u041f\/32-1 \u043e\u0442 01.02.2025"', 'П/32/1'],
            'П/32/1 stable' => ['"\u041f\/32\/1 \u043e\u0442 01.02.2025"', 'П/32/1'],
            'СТ 5-6 -> СТ-5-6' => ['"\u0421\u0422 5-6 \u043e\u0442 01.02.2025"', 'СТ-5-6'],
            'П/60/У -> П/60У' => ['"\u041f\/60\/\u0423 \u043e\u0442 01.02.2025"', 'П/60У'],
            'П-73 -> П/73' => ['"\u041f-73 \u043e\u0442 01.02.2025"', 'П/73'],
            'СКЛАД12 -> СКЛАД 12' => ['"\u0421\u041a\u041b\u0410\u041412 \u043e\u0442 01.02.2025"', 'СКЛАД 12'],
            'СК-13 -> СКЛАД 13' => ['"\u0421\u041a-13 \u043e\u0442 01.02.2025"', 'СКЛАД 13'],
            'СК-11-12 -> СКЛАД 11-12' => ['"\u0421\u041a-11-12 \u043e\u0442 01.02.2025"', 'СКЛАД 11-12'],
        ];
    }
}
