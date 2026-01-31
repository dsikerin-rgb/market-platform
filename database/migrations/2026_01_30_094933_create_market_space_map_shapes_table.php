<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_space_map_shapes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->foreignId('market_space_id')
                ->nullable()
                ->constrained('market_spaces')
                ->nullOnDelete();

            $table->unsignedInteger('page')->default(1);
            $table->unsignedInteger('version')->default(1);

            // polygon points in PDF coords: [{x,y}, ...] OR [[x,y], ...]
            $table->json('polygon');

            // bbox in PDF coords
            $table->decimal('bbox_x1', 12, 3);
            $table->decimal('bbox_y1', 12, 3);
            $table->decimal('bbox_x2', 12, 3);
            $table->decimal('bbox_y2', 12, 3);

            // style
            $table->string('fill_color', 16)->default('#00A3FF');
            $table->string('stroke_color', 16)->default('#00A3FF');
            $table->decimal('fill_opacity', 5, 3)->default(0.120);
            $table->decimal('stroke_width', 6, 2)->default(1.50);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // audit (optional, but полезно для multi-admin)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['market_id', 'page', 'version']);
            $table->index(['market_id', 'market_space_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_space_map_shapes');
    }
};
