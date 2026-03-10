<?php
# app/Services/TenantContracts/ContractDocumentClassifier.php

declare(strict_types=1);

namespace App\Services\TenantContracts;

class ContractDocumentClassifier
{
    /**
     * @return array{
     *   category: 'primary_contract'|'supplemental_document'|'service_document'|'penalty_document'|'non_rent_document'|'placeholder_document'|'unknown',
     *   label: string,
     *   actionable: bool,
     *   normalized: string,
     *   matched_rule: ?string,
     *   place_token: ?string,
     *   document_date: ?string
     * }
     */
    public function classify(string $contractNumber): array
    {
        $normalized = $this->normalizeText($contractNumber);
        $placeToken = $this->extractPlaceToken($normalized);
        $documentDate = $this->extractDocumentDate($normalized);

        if ($normalized === '') {
            return $this->result('unknown', 'Не классифицировано', false, $normalized, null, $placeToken, $documentDate);
        }

        if (str_contains($normalized, 'БЕЗ ДОГОВОРА')) {
            return $this->result('placeholder_document', 'Без договора', false, $normalized, 'placeholder', $placeToken, $documentDate);
        }

        if (preg_match('/\bПЕНИ?\b/u', $normalized) === 1) {
            return $this->result('penalty_document', 'Пени / штрафы', false, $normalized, 'penalty', $placeToken, $documentDate);
        }

        if (preg_match('/АГЕНТСК|РЕКЛАМ|КУПЛИ\s+ПРОДАЖИ/u', $normalized) === 1) {
            return $this->result('non_rent_document', 'Неарендный документ', false, $normalized, 'non_rent', $placeToken, $documentDate);
        }

        if (
            preg_match('/\bДС\b/u', $normalized) === 1
            || preg_match('/ДОП(\.|ОЛНИТЕЛЬНОЕ)?\s*СОГЛ/u', $normalized) === 1
        ) {
            return $this->result('supplemental_document', 'Доп. соглашение', true, $normalized, 'supplement', $placeToken, $documentDate);
        }

        if (preg_match('/^\s*(ОП|КП|УУ)\b/u', $normalized) === 1) {
            return $this->result('service_document', 'Служебный договорный документ', true, $normalized, 'service_prefix', $placeToken, $documentDate);
        }

        if (
            preg_match('/ДОГОВОР\s+(АРЕНДЫ|СУБАРЕНДЫ)/u', $normalized) === 1
            || preg_match('/^\s*А\b/u', $normalized) === 1
            || preg_match('/(?:^|\s)([А-ЯA-Z]{0,3}[\/-]?[А-ЯA-Z0-9]+(?:[\/-][А-ЯA-Z0-9]+)+)/u', $normalized) === 1
            || preg_match('/(?:^|\s)(КИОСК|СКЛАД|СТ|ОС|ХК)\s*[-\/]?\s*\d/u', $normalized) === 1
        ) {
            return $this->result('primary_contract', 'Основной договор аренды', true, $normalized, 'primary', $placeToken, $documentDate);
        }

        return $this->result('unknown', 'Не классифицировано', false, $normalized, null, $placeToken, $documentDate);
    }

    /**
     * @return array{
     *   category: 'primary_contract'|'supplemental_document'|'service_document'|'penalty_document'|'non_rent_document'|'placeholder_document'|'unknown',
     *   label: string,
     *   actionable: bool,
     *   normalized: string,
     *   matched_rule: ?string,
     *   place_token: ?string,
     *   document_date: ?string
     * }
     */
    private function result(
        string $category,
        string $label,
        bool $actionable,
        string $normalized,
        ?string $matchedRule,
        ?string $placeToken,
        ?string $documentDate
    ): array {
        return [
            'category' => $category,
            'label' => $label,
            'actionable' => $actionable,
            'normalized' => $normalized,
            'matched_rule' => $matchedRule,
            'place_token' => $placeToken,
            'document_date' => $documentDate,
        ];
    }

    private function extractPlaceToken(string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            '/(?:^|\s|№)([А-ЯA-Z]{1,3}\s*[-\/]?\s*\d+(?:[-\/]\d+){0,2})(?![\p{L}\p{N}\/-])/u',
            '/(?:^|\s|№)((?:КИОСК|СКЛАД|СТ|ОС|ХК)\s*[-\/]?\s*\d+(?:[-\/]\d+){0,2})(?![\p{L}\p{N}\/-])/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) !== 1) {
                continue;
            }

            $token = trim((string) ($matches[1] ?? ''));
            if ($token === '') {
                continue;
            }

            $token = preg_replace('/\s*([\/-])\s*/u', '$1', $token) ?? $token;
            $token = preg_replace('/\s+/u', ' ', $token) ?? $token;

            return trim($token);
        }

        return null;
    }

    private function extractDocumentDate(string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\bОТ\s+(\d{2})\.(\d{2})\.(\d{2}|\d{4})\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $yearRaw = $matches[3];
        $year = strlen($yearRaw) === 2 ? 2000 + (int) $yearRaw : (int) $yearRaw;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeText(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $value = mb_strtoupper($value, 'UTF-8');
        $value = str_replace(['\\', '–', '—'], ['/', '-', '-'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('#/+#', '/', $value) ?? $value;
        $value = preg_replace('/-+/u', '-', $value) ?? $value;

        return trim($value);
    }
}
