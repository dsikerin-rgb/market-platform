<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'manager_id')) {
                $table->foreignId('manager_id')
                    ->nullable()
                    ->after('department')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'organization_level')) {
                $table->unsignedSmallInteger('organization_level')->nullable()->after('manager_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'manager_id')) {
                $table->dropConstrainedForeignId('manager_id');
            }

            if (Schema::hasColumn('users', 'organization_level')) {
                $table->dropColumn('organization_level');
            }
        });
    }
};
