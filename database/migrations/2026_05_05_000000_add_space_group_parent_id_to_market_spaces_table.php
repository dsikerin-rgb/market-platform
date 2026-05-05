<?php
# database/migrations/2026_05_05_000000_add_space_group_parent_id_to_market_spaces_table.php
# Добавляет FK space_group_parent_id для связи child-мест с parent-группами.
# Позволяет child-местам выбирать родительскую группу из списка, а не вводить текстовый token.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('market_spaces')) {
            return;
        }

        if (Schema::hasColumn('market_spaces', 'space_group_parent_id')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            // FK на самого себя (self-referential) для связи child → parent
            $table->unsignedBigInteger('space_group_parent_id')
                ->nullable()
                ->after('space_group_role')
                ->comment('ID родительской группы (parent-места)');

            // Индекс для быстрого поиска child-мест по parent
            $table->index(
                ['market_id', 'space_group_parent_id'],
                'market_spaces_group_parent_idx'
            );

            // Foreign key (onDelete set null при удалении parent)
            $table->foreign('space_group_parent_id')
                ->references('id')
                ->on('market_spaces')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('market_spaces')) {
            return;
        }

        if (!Schema::hasColumn('market_spaces', 'space_group_parent_id')) {
            return;
        }

        Schema::table('market_spaces', function (Blueprint $table): void {
            $table->dropForeign(['space_group_parent_id']);
            $table->dropIndex('market_spaces_group_parent_idx');
            $table->dropColumn('space_group_parent_id');
        });
    }
};
