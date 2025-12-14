<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')->constrained()->cascadeOnDelete();

            // Заявка может быть от арендатора (Tenant)
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('subject');       // Заголовок заявки
            $table->text('description');     // Описание заявки

            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['new', 'in_progress', 'on_hold', 'resolved', 'closed', 'cancelled'])->default('new');

            // Кто назначен (сотрудник / пользователь)
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
