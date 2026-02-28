<?php

declare(strict_types=1);

# database/migrations/xxxx_xx_xx_xxxxxx_add_contract_debts_natural_unique_index.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляет уникальный индекс, предотвращающий дубли строк долга.
     * Натуральный ключ соответствует логике дедупликации/идемпотентности.
     */
    public function up(): void
    {
        // Индекс уже мог быть создан руками на staging/prod — делаем idempotent.
        if (! Schema::hasTable('contract_debts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS contract_debts_natural_unique
ON public.contract_debts (
  market_id,
  tenant_external_id,
  contract_external_id,
  period,
  currency,
  accrued_amount,
  paid_amount,
  debt_amount
)
SQL);
    }

    /**
     * Откат: удаляет индекс.
     */
    public function down(): void
    {
        if (! Schema::hasTable('contract_debts')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS contract_debts_natural_unique');
    }
};