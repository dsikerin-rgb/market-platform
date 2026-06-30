<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('new')->index();
            $table->string('request_type', 32)->default('demo')->index();
            $table->string('name', 120);
            $table->string('organization', 180);
            $table->string('email', 190)->index();
            $table->string('phone', 64)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('market_format', 120)->nullable();
            $table->unsignedInteger('spaces_count')->nullable();
            $table->text('message')->nullable();
            $table->string('source', 64)->default('demo_landing');
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'demo_requests_status_created_idx');
            $table->index(['request_type', 'created_at'], 'demo_requests_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
