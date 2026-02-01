<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('debt_status', 16)->nullable()->after('notes');
            $table->text('debt_status_note')->nullable()->after('debt_status');
            $table->timestamp('debt_status_updated_at')->nullable()->after('debt_status_note');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['debt_status', 'debt_status_note', 'debt_status_updated_at']);
        });
    }
};
