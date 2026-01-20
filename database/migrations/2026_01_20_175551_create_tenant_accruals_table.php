<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_accruals', function (Blueprint $table) {
            $table->id();

            // Контекст рынка (мульти-рынок внутри одной БД)
            $table->foreignId('market_id')
                ->constrained('markets');

            // Арендатор (обязателен)
            $table->foreignId('tenant_id')
                ->constrained('tenants');

            // Привязки (могут появляться позже, когда будет реестр договоров/мест)
            $table->foreignId('tenant_contract_id')
                ->nullable()
                ->constrained('tenant_contracts');

            $table->foreignId('market_space_id')
                ->nullable()
                ->constrained('market_spaces');

            // Период начисления: храним как дату первого дня месяца (например, 2026-01-01)
            $table->date('period');

            // То, как место/отдел обозначено в исходных таблицах (и для сопоставления с картой)
            $table->string('source_place_code', 64)->nullable();   // "№ отдела" из Excel
            $table->string('source_place_name', 255)->nullable(); // "название отдела" из Excel
            $table->string('activity_type', 255)->nullable();     // "вид деятельности" из Excel

            // Количественные параметры
            $table->decimal('area_sqm', 10, 2)->nullable();        // сданная площадь
            $table->decimal('rent_rate', 14, 2)->nullable();       // ставка аренды (факт)
            $table->integer('days')->nullable();                   // кол-во дней (если есть)

            // Состав начислений (все суммы в RUB по умолчанию)
            $table->string('currency', 3)->default('RUB');

            $table->decimal('rent_amount', 14, 2)->nullable();        // сумма аренды
            $table->decimal('management_fee', 14, 2)->nullable();     // услуги управления (включая эл.энергию, если так в файле)
            $table->decimal('utilities_amount', 14, 2)->nullable();   // коммунальные услуги
            $table->decimal('electricity_amount', 14, 2)->nullable(); // электроэнергия

            // Итоги/НДС
            $table->decimal('total_no_vat', 14, 2)->nullable();       // итого к оплате без НДС
            $table->decimal('vat_rate', 6, 4)->nullable();            // например 0.0500
            $table->decimal('total_with_vat', 14, 2)->nullable();     // итого к оплате с НДС

            // Примечания/скидки/наличные (как в файле)
            $table->text('discount_note')->nullable();                // доп. соглашения/скидки
            $table->decimal('cash_amount', 14, 2)->nullable();         // в т.ч. наличные
            $table->text('notes')->nullable();

            // Метаданные импорта (чтобы можно было безопасно перегружать те же месяцы)
            $table->string('status', 32)->default('imported');         // imported/draft/approved etc.
            $table->string('source', 32)->default('excel');            // excel/1c/manual
            $table->string('source_file', 255)->nullable();            // имя файла
            $table->unsignedInteger('source_row_number')->nullable();  // номер строки в файле

            // Хэш строки (для идемпотентного импорта)
            $table->char('source_row_hash', 64)->nullable();

            // Сырой payload (на будущее: хранить исходные данные/ключи)
            $table->json('payload')->nullable();

            $table->timestamp('imported_at')->nullable();

            $table->timestamps();

            // Индексы под типовые выборки
            $table->index(['market_id', 'period']);
            $table->index(['market_id', 'tenant_id', 'period']);
            $table->index(['market_id', 'market_space_id', 'period']);

            // Защита от дублей при повторных импортов (если hash заполняем)
            $table->unique(['market_id', 'period', 'source_row_hash'], 'uniq_accruals_market_period_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_accruals');
    }
};
