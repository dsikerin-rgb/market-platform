<?php

# database/migrations/2026_01_29_000000_create_market_space_map_shapes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_space_map_shapes', function (Blueprint $table): void {
            $table->id();

            // Привязка к рынку обязательна (одна страница сейчас, но page оставляем на будущее).
            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            // Полигон может быть "непривязан" во время разметки/редактора.
            $table->foreignId('market_space_id')
                ->nullable()
                ->constrained('market_spaces')
                ->nullOnDelete();

            // Один PDF-лист сейчас = 1, но поле пригодится, если появятся этажи/листы.
            $table->unsignedSmallInteger('page')->default(1);

            // Версия раскладки (когда места “переезжают” — поднимаем version).
            $table->unsignedInteger('version')->default(1);

            // Быстрый отбор кандидатов по клику (bbox в PDF-координатах).
            $table->decimal('bbox_x1', 10, 2);
            $table->decimal('bbox_y1', 10, 2);
            $table->decimal('bbox_x2', 10, 2);
            $table->decimal('bbox_y2', 10, 2);

            // Геометрия (PDF-координаты): массив точек [{x,y}, ...]
            $table->json('polygon');

            // Визуальные параметры слоя (можно переопределять для режима “долги”).
            $table->string('stroke_color', 32)->nullable();   // например "#ff0000"
            $table->string('fill_color', 32)->nullable();     // например "#ff0000"
            $table->decimal('fill_opacity', 4, 2)->nullable(); // 0.00..1.00
            $table->decimal('stroke_width', 6, 2)->nullable(); // в px слоя

            // На будущее: произвольные метаданные (например, подписи/якорь/заметки разметчика).
            $table->json('meta')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Один shape на одно место в рамках (market, version, page).
            $table->unique(['market_id', 'version', 'page', 'market_space_id'], 'mss_shape_unique_space_per_version');

            // Поиск кандидатов: рынок+версия+страница и bbox.
            $table->index(['market_id', 'version', 'page'], 'mss_shape_scope');
            $table->index(['market_id', 'version', 'page', 'bbox_x1', 'bbox_x2'], 'mss_shape_bbox_x');
            $table->index(['market_id', 'version', 'page', 'bbox_y1', 'bbox_y2'], 'mss_shape_bbox_y');

            $table->index(['market_space_id'], 'mss_shape_space_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_map_shapes');
    }
};
