<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // На staging/prod таблица могла быть создана ранее (вручную/ранним деплоем),
        // но миграция не отмечена в migrations -> тогда повторное create падает.
        if (Schema::hasTable('market_space_map_shapes')) {
            return;
        }

        Schema::create('market_space_map_shapes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('market_space_id')->nullable()->constrained('market_spaces')->nullOnDelete();

            $table->unsignedInteger('page')->default(1);
            $table->unsignedInteger('version')->default(1);

            // На SQLite json хранится как TEXT, для нас это ок.
            $table->json('polygon');

            $table->decimal('bbox_x1', 10, 2);
            $table->decimal('bbox_y1', 10, 2);
            $table->decimal('bbox_x2', 10, 2);
            $table->decimal('bbox_y2', 10, 2);

            $table->string('fill_color')->default('#00A3FF');
            $table->string('stroke_color')->default('#00A3FF');

            // 0..1
            $table->decimal('fill_opacity', 4, 2)->default(0.12);
            $table->decimal('stroke_width', 5, 2)->default(1.5);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['market_id', 'page', 'version', 'is_active'], 'msms_market_page_ver_active_idx');
            $table->index(['market_space_id'], 'msms_market_space_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_map_shapes');
    }
};
