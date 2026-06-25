<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'manager_user_id')) {
                $table->foreignId('manager_user_id')
                    ->nullable()
                    ->after('department')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'organization_level')) {
                $table->unsignedTinyInteger('organization_level')
                    ->nullable()
                    ->after('manager_user_id');
            }

            if (! Schema::hasColumn('users', 'organization_role')) {
                $table->string('organization_role')
                    ->nullable()
                    ->after('organization_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'organization_role')) {
                $table->dropColumn('organization_role');
            }

            if (Schema::hasColumn('users', 'organization_level')) {
                $table->dropColumn('organization_level');
            }

            if (Schema::hasColumn('users', 'manager_user_id')) {
                $table->dropConstrainedForeignId('manager_user_id');
            }
        });
    }
};
