<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiReadOnlySqlTool
{
    /**
     * @param  array<string, mixed>  $settings
     * @return array{ok:bool,sql:string|null,error:string|null,rows:list<array<string,mixed>>,row_count:int,truncated:bool}
     */
    public function run(int $marketId, string $sql, array $settings): array
    {
        $sql = $this->normalizeSql($sql);
        $error = $this->validate($marketId, $sql, $settings);

        if ($error !== null) {
            return $this->failure($sql, $error);
        }

        $rowLimit = max(5, min((int) ($settings['sql_row_limit'] ?? 50), 200));
        $timeoutMs = max(250, min((int) ($settings['sql_timeout_ms'] ?? 2500), 10000));
        $wrappedSql = sprintf('select * from (%s) as ai_read_only_result limit %d', $sql, $rowLimit + 1);

        try {
            $alreadyInTransaction = DB::transactionLevel() > 0;
            $runner = function () use ($wrappedSql, $timeoutMs, $alreadyInTransaction): array {
                if (! $alreadyInTransaction) {
                    DB::statement('SET TRANSACTION READ ONLY');
                }

                DB::statement('SET LOCAL statement_timeout = '.$timeoutMs);

                return DB::select($wrappedSql);
            };

            $rows = $alreadyInTransaction ? $runner() : DB::transaction($runner);
        } catch (\Throwable $e) {
            logger()->warning('AI read-only SQL tool failed', [
                'sql' => $sql,
                'message' => $e->getMessage(),
            ]);

            return $this->failure($sql, 'Не удалось выполнить проверку данных: запрос отклонен базой или занял слишком много времени.');
        }

        $truncated = count($rows) > $rowLimit;
        $rows = array_slice($rows, 0, $rowLimit);

        return [
            'ok' => true,
            'sql' => $sql,
            'error' => null,
            'rows' => array_map(static fn (object $row): array => (array) $row, $rows),
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    public function schemaHint(int $marketId, array $settings): string
    {
        $allowedTables = implode(', ', (array) ($settings['allowed_tables'] ?? AiAgentSettings::defaultAllowedTables()));

        return <<<TEXT
Доступен инструмент чтения базы read_sql.
Формат внутреннего запроса к инструменту: {"tool":"read_sql","sql":"SELECT ..."}.
Пиши такой JSON только если нужно проверить данные; пользователю JSON не показывай.
Разрешены только SELECT/CTE-запросы, только таблицы: {$allowedTables}.
Каждый запрос обязан ограничивать данные текущим рынком: market_id = {$marketId}.
Запросы на изменение данных, служебные функции, несколько SQL-команд и таблицы вне списка будут отклонены.
Основные поля:
- tenants: id, market_id, name, short_name, inn, is_active, debt_status
- market_spaces: id, market_id, number, display_name, status, tenant_id, area_sqm, rent_rate_value, rent_rate_unit, map_review_status
- market_space_tenant_bindings: id, market_id, market_space_id, tenant_id, tenant_contract_id, ended_at, area_sqm, rent_rate, binding_type
- tenant_contracts: id, market_id, tenant_id, market_space_id, number, external_id, status, is_active, starts_at, ends_at
- contract_debts: id, market_id, tenant_id, tenant_external_id, contract_external_id, period, debt_amount, accrued_amount, paid_amount, account, calculated_at
- tenant_settlement_balances: id, market_id, tenant_id, tenant_contract_id, period_from, period_to, account, closing_debit, closing_credit, contract_name
- tenant_accruals: id, market_id, tenant_id, market_space_id, tenant_contract_id, period, area_sqm, rent_rate, rent_amount, total_no_vat, total_with_vat, source_place_code, source_place_name
- tickets: id, market_id, tenant_id, subject, status, priority, created_at, updated_at
Для вопросов о самой низкой или высокой арендной ставке сначала проверяй tenant_accruals.rent_rate за последний доступный period; если начислений нет, проверяй активные market_space_tenant_bindings.rent_rate и market_spaces.rent_rate_value. Для перехода к арендатору используй resource_link/make_link, а не текстовый ID или URL.
TEXT;
    }

    private function normalizeSql(string $sql): string
    {
        return rtrim(trim($sql), " \t\n\r\0\x0B;");
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validate(int $marketId, string $sql, array $settings): ?string
    {
        if ($marketId <= 0) {
            return 'Рынок не выбран, поэтому читать данные нельзя.';
        }

        if ($sql === '') {
            return 'Пустой запрос к базе.';
        }

        if (preg_match('/\A\s*(select|with)\b/i', $sql) !== 1) {
            return 'Разрешены только запросы чтения.';
        }

        if (str_contains($sql, ';') || str_contains($sql, '--') || str_contains($sql, '/*') || str_contains($sql, '*/')) {
            return 'Запрос содержит недопустимые разделители или комментарии.';
        }

        if (preg_match('/\b(insert|update|delete|drop|create|alter|truncate|grant|revoke|copy|call|do|execute|vacuum|analyze|refresh|lock|set|reset)\b/i', $sql) === 1) {
            return 'Запрос содержит операцию, которая не относится к чтению данных.';
        }

        if (preg_match('/\b(pg_sleep|pg_read_file|pg_ls_dir|dblink|lo_import|lo_export|current_setting)\b/i', $sql) === 1) {
            return 'Запрос содержит служебную функцию, которая запрещена для ИИ-агента.';
        }

        if (preg_match('/\bmarket_id\s*=\s*[\'"]?'.preg_quote((string) $marketId, '/').'[\'"]?\b/i', $sql) !== 1) {
            return 'Запрос должен быть явно ограничен текущим рынком.';
        }

        $tables = $this->extractTables($sql);
        if ($tables === []) {
            return 'В запросе не найдены таблицы для чтения.';
        }

        $allowedTables = array_map('strtolower', (array) ($settings['allowed_tables'] ?? AiAgentSettings::defaultAllowedTables()));
        foreach ($tables as $table) {
            if (! in_array($table, $allowedTables, true)) {
                return "Таблица {$table} не разрешена для ИИ-агента.";
            }

            if (! Schema::hasTable($table)) {
                return "Таблица {$table} не найдена.";
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+([a-z_][a-z0-9_\.]*)/i', $sql, $matches);

        return collect($matches[1] ?? [])
            ->map(static function (string $table): string {
                $table = strtolower($table);

                return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            })
            ->filter(static fn (string $table): bool => preg_match('/^[a-z_][a-z0-9_]*$/', $table) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{ok:bool,sql:string|null,error:string|null,rows:list<array<string,mixed>>,row_count:int,truncated:bool}
     */
    private function failure(?string $sql, string $error): array
    {
        return [
            'ok' => false,
            'sql' => $sql,
            'error' => $error,
            'rows' => [],
            'row_count' => 0,
            'truncated' => false,
        ];
    }
}
