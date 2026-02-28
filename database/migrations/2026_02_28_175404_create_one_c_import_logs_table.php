<?php

declare(strict_types=1);

# database/migrations/xxxx_xx_xx_xxxxxx_create_one_c_import_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_c_import_logs', function (Blueprint $table) {
            $table->id();

            // Связи (nullable, чтобы лог не ломался при изменениях/удалениях интеграций)
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedBigInteger('market_integration_id')->nullable();

            // Контекст вызова
            $table->string('endpoint', 128);
            $table->string('status', 32);          // ok | auth_missing | auth_invalid | validation_error | exception
            $table->unsignedSmallInteger('http_status');

            // Данные запроса/результата
            $table->dateTime('calculated_at')->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            // Время выполнения
            $table->unsignedInteger('duration_ms')->nullable();

            // Ошибки / доп. данные (meta пишем JSON)
            $table->text('error_message')->nullable();
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            // Индексы для быстрых выборок "последний импорт" и диагностики
            $table->index(['market_id', 'created_at'], 'one_c_import_logs_market_created_idx');
            $table->index(['market_integration_id', 'created_at'], 'one_c_import_logs_integration_created_idx');
            $table->index(['status', 'created_at'], 'one_c_import_logs_status_created_idx');
            $table->index(['endpoint', 'created_at'], 'one_c_import_logs_endpoint_created_idx');
        });

        // FK делаем отдельно, чтобы не падать, если таблиц/ключей нет в конкретном окружении
        // и чтобы иметь контроль над on delete.
        if (Schema::hasTable('markets')) {
            DB::statement(<<<'SQL'
ALTER TABLE one_c_import_logs
ADD CONSTRAINT one_c_import_logs_market_id_fk
FOREIGN KEY (market_id) REFERENCES markets(id)
ON DELETE SET NULL
SQL);
        }

        if (Schema::hasTable('market_integrations')) {
            DB::statement(<<<'SQL'
ALTER TABLE one_c_import_logs
ADD CONSTRAINT one_c_import_logs_market_integration_id_fk
FOREIGN KEY (market_integration_id) REFERENCES market_integrations(id)
ON DELETE SET NULL
SQL);
        }
    }

    public function down(): void
    {
        // Сначала удаляем FK, потом таблицу
        try {
            DB::statement('ALTER TABLE one_c_import_logs DROP CONSTRAINT IF EXISTS one_c_import_logs_market_id_fk');
        } catch (\Throwable) {
        }

        try {
            DB::statement('ALTER TABLE one_c_import_logs DROP CONSTRAINT IF EXISTS one_c_import_logs_market_integration_id_fk');
        } catch (\Throwable) {
        }

        Schema::dropIfExists('one_c_import_logs');
    }
};