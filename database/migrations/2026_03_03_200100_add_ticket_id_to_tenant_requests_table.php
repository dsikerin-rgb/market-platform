<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_requests', 'ticket_id')) {
                $table->foreignId('ticket_id')
                    ->nullable()
                    ->after('tenant_contract_id')
                    ->constrained('tickets')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_requests', 'ticket_id')) {
                $table->dropConstrainedForeignId('ticket_id');
            }
        });
    }
};
