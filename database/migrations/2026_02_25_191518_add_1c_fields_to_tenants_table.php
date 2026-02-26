<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {

            if (!Schema::hasColumn('tenants', 'one_c_uid')) {
                $table->uuid('one_c_uid')
                    ->nullable()
                    ->unique()
                    ->comment('UUID контрагента из 1С');
            }

            if (!Schema::hasColumn('tenants', 'inn')) {
                $table->string('inn', 20)
                    ->nullable()
                    ->index();
            }

            if (!Schema::hasColumn('tenants', 'phone')) {
                $table->string('phone', 30)
                    ->nullable();
            }

            if (!Schema::hasColumn('tenants', 'email')) {
                $table->string('email', 255)
                    ->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {

            if (Schema::hasColumn('tenants', 'one_c_uid')) {
                $table->dropColumn('one_c_uid');
            }

            if (Schema::hasColumn('tenants', 'inn')) {
                $table->dropColumn('inn');
            }

            if (Schema::hasColumn('tenants', 'phone')) {
                $table->dropColumn('phone');
            }

            if (Schema::hasColumn('tenants', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};