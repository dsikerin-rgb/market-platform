<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinet_impersonation_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('impersonator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cabinet_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status', 32);
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['market_id', 'tenant_id']);
            $table->index(['impersonator_user_id', 'status']);
            $table->index(['cabinet_user_id', 'status']);
            $table->index('started_at');
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_impersonation_audits');
    }
};

