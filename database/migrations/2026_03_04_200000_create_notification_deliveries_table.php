<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->nullable()->index();
            $table->string('notification_type');
            $table->string('channel', 40)->index();
            $table->string('status', 20)->index();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->unsignedBigInteger('market_id')->nullable()->index();
            $table->boolean('queued')->default(false);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id'], 'notification_deliveries_notifiable_idx');
            $table->index(['market_id', 'created_at'], 'notification_deliveries_market_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
