<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_categories')) {
            Schema::create('marketplace_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('marketplace_categories')->nullOnDelete();
                $table->string('name', 140);
                $table->string('slug', 170);
                $table->text('description')->nullable();
                $table->string('icon', 120)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['market_id', 'slug'], 'marketplace_categories_market_slug_unique');
                $table->index(['market_id', 'is_active', 'sort_order'], 'marketplace_categories_market_active_sort_idx');
                $table->index(['parent_id', 'sort_order'], 'marketplace_categories_parent_sort_idx');
            });
        }

        if (! Schema::hasTable('marketplace_products')) {
            Schema::create('marketplace_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('market_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('market_space_id')->nullable()->constrained('market_spaces')->nullOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('marketplace_categories')->nullOnDelete();
                $table->string('title', 190);
                $table->string('slug', 220);
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->string('currency', 8)->default('RUB');
                $table->unsignedInteger('stock_qty')->default(0);
                $table->string('sku', 120)->nullable();
                $table->string('unit', 40)->nullable();
                $table->json('images')->nullable();
                $table->json('attributes')->nullable();
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('favorites_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_featured')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['market_id', 'slug'], 'marketplace_products_market_slug_unique');
                $table->index(['market_id', 'is_active', 'is_featured', 'published_at'], 'marketplace_products_market_active_featured_pub_idx');
                $table->index(['tenant_id', 'is_active', 'published_at'], 'marketplace_products_tenant_active_pub_idx');
                $table->index(['market_space_id', 'is_active'], 'marketplace_products_space_active_idx');
                $table->index(['category_id', 'is_active'], 'marketplace_products_category_active_idx');
                $table->index(['market_id', 'price'], 'marketplace_products_market_price_idx');
            });
        }

        if (! Schema::hasTable('marketplace_announcements')) {
            Schema::create('marketplace_announcements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('market_id')->constrained()->cascadeOnDelete();
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('kind', 40)->default('event');
                $table->string('title', 190);
                $table->string('slug', 220);
                $table->text('excerpt')->nullable();
                $table->longText('content')->nullable();
                $table->string('cover_image', 255)->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['market_id', 'slug'], 'marketplace_announcements_market_slug_unique');
                $table->index(['market_id', 'is_active', 'published_at'], 'marketplace_announcements_market_active_pub_idx');
                $table->index(['market_id', 'kind', 'starts_at'], 'marketplace_announcements_market_kind_start_idx');
            });
        }

        if (! Schema::hasTable('marketplace_favorites')) {
            Schema::create('marketplace_favorites', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('buyer_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('marketplace_products')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['buyer_user_id', 'product_id'], 'marketplace_favorites_buyer_product_unique');
                $table->index(['product_id', 'created_at'], 'marketplace_favorites_product_created_idx');
            });
        }

        if (! Schema::hasTable('marketplace_chats')) {
            Schema::create('marketplace_chats', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('market_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('market_space_id')->nullable()->constrained('market_spaces')->nullOnDelete();
                $table->foreignId('buyer_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('marketplace_products')->nullOnDelete();
                $table->string('subject', 190)->nullable();
                $table->string('status', 30)->default('open');
                $table->timestamp('last_message_at')->nullable();
                $table->unsignedInteger('buyer_unread_count')->default(0);
                $table->unsignedInteger('tenant_unread_count')->default(0);
                $table->timestamps();

                $table->index(['market_id', 'tenant_id', 'status'], 'marketplace_chats_market_tenant_status_idx');
                $table->index(['buyer_user_id', 'status', 'last_message_at'], 'marketplace_chats_buyer_status_last_msg_idx');
                $table->index(['tenant_id', 'status', 'last_message_at'], 'marketplace_chats_tenant_status_last_msg_idx');
                $table->index(['market_space_id', 'status'], 'marketplace_chats_space_status_idx');
            });
        }

        if (! Schema::hasTable('marketplace_chat_messages')) {
            Schema::create('marketplace_chat_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_id')->constrained('marketplace_chats')->cascadeOnDelete();
                $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sender_type', 20); // buyer | tenant | system
                $table->text('body');
                $table->json('attachments')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['chat_id', 'created_at'], 'marketplace_chat_messages_chat_created_idx');
                $table->index(['sender_type', 'created_at'], 'marketplace_chat_messages_sender_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_chat_messages');
        Schema::dropIfExists('marketplace_chats');
        Schema::dropIfExists('marketplace_favorites');
        Schema::dropIfExists('marketplace_announcements');
        Schema::dropIfExists('marketplace_products');
        Schema::dropIfExists('marketplace_categories');
    }
};

