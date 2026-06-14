<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'staff_avatar_path')) {
                $table->string('staff_avatar_path', 1024)->nullable();
            }

            if (! Schema::hasColumn('users', 'staff_avatar_color')) {
                $table->string('staff_avatar_color', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'staff_avatar_path')) {
                $table->dropColumn('staff_avatar_path');
            }

            if (Schema::hasColumn('users', 'staff_avatar_color')) {
                $table->dropColumn('staff_avatar_color');
            }
        });
    }
};
