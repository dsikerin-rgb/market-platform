<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketDocument;
use App\Models\MarketDocumentFolder;
use App\Models\MarketHoliday;
use App\Models\MarketSpace;
use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceChat;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketWriteGuardModelPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_create_rejects_cross_market_owner(): void
    {
        [$marketA, $marketB] = $this->markets();
        $owner = $this->user($marketB);

        $this->assertValidationFailure(
            'owner_user_id',
            'Document owner belongs to another market.',
            static function () use ($marketA, $owner): void {
                MarketDocument::query()->create([
                    'market_id' => (int) $marketA->id,
                    'owner_user_id' => (int) $owner->id,
                    'visibility' => MarketDocument::VISIBILITY_PERSONAL,
                    'category' => MarketDocument::CATEGORY_GENERAL,
                    'title' => 'Cross owner document',
                ]);
            },
        );
    }

    public function test_document_update_rejects_cross_market_folder_move(): void
    {
        [$marketA, $marketB] = $this->markets();
        $document = $this->document($marketA);
        $foreignFolder = $this->documentFolder($marketB);

        $this->assertValidationFailure(
            'folder_id',
            'Selected folder belongs to another market.',
            static function () use ($document, $foreignFolder): void {
                $document->folder_id = (int) $foreignFolder->id;
                $document->save();
            },
        );
    }

    public function test_marketplace_product_create_rejects_cross_market_tenant(): void
    {
        [$marketA, $marketB] = $this->markets();
        $tenant = $this->tenant($marketB);

        $this->assertValidationFailure(
            'tenant_id',
            'Marketplace product tenant belongs to another market.',
            static function () use ($marketA, $tenant): void {
                MarketplaceProduct::query()->create([
                    'market_id' => (int) $marketA->id,
                    'tenant_id' => (int) $tenant->id,
                    'title' => 'Cross tenant product',
                    'slug' => 'cross-tenant-product',
                    'currency' => 'RUB',
                    'stock_qty' => 1,
                    'is_active' => true,
                    'is_featured' => false,
                ]);
            },
        );
    }

    public function test_marketplace_product_update_rejects_cross_market_space_and_category(): void
    {
        [$marketA, $marketB] = $this->markets();
        $tenant = $this->tenant($marketA);
        $product = $this->product($marketA, $tenant);
        $foreignSpace = $this->space($marketB);
        $foreignCategory = $this->category($marketB);

        $this->assertValidationFailure(
            'market_space_id',
            'Marketplace product space belongs to another market.',
            static function () use ($product, $foreignSpace): void {
                $product->market_space_id = (int) $foreignSpace->id;
                $product->save();
            },
        );

        $product->refresh();

        $this->assertValidationFailure(
            'category_id',
            'Marketplace product category belongs to another market.',
            static function () use ($product, $foreignCategory): void {
                $product->category_id = (int) $foreignCategory->id;
                $product->save();
            },
        );
    }

    public function test_marketplace_category_update_rejects_cross_market_parent(): void
    {
        [$marketA, $marketB] = $this->markets();
        $category = $this->category($marketA);
        $foreignParent = $this->category($marketB);

        $this->assertValidationFailure(
            'parent_id',
            'Marketplace category parent belongs to another market.',
            static function () use ($category, $foreignParent): void {
                $category->parent_id = (int) $foreignParent->id;
                $category->save();
            },
        );
    }

    public function test_marketplace_announcement_create_rejects_cross_market_holiday(): void
    {
        [$marketA, $marketB] = $this->markets();
        $holiday = $this->holiday($marketB);

        $this->assertValidationFailure(
            'market_holiday_id',
            'Marketplace announcement holiday belongs to another market.',
            static function () use ($marketA, $holiday): void {
                MarketplaceAnnouncement::query()->create([
                    'market_id' => (int) $marketA->id,
                    'market_holiday_id' => (int) $holiday->id,
                    'kind' => 'event',
                    'title' => 'Cross holiday announcement',
                    'slug' => 'cross-holiday-announcement',
                    'is_active' => true,
                    'published_at' => now(),
                ]);
            },
        );
    }

    public function test_marketplace_chat_update_rejects_cross_market_product(): void
    {
        [$marketA, $marketB] = $this->markets();
        $tenant = $this->tenant($marketA);
        $buyer = $this->user(null);
        $chat = $this->chat($marketA, $tenant, $buyer);
        $foreignTenant = $this->tenant($marketB);
        $foreignProduct = $this->product($marketB, $foreignTenant);

        $this->assertValidationFailure(
            'product_id',
            'Marketplace chat product belongs to another market.',
            static function () use ($chat, $foreignProduct): void {
                $chat->product_id = (int) $foreignProduct->id;
                $chat->save();
            },
        );
    }

    /**
     * @return array{0:Market,1:Market}
     */
    private function markets(): array
    {
        return [
            Market::query()->create([
                'name' => 'Market A',
                'slug' => 'market-a-' . str()->random(8),
                'timezone' => 'Europe/Moscow',
                'is_active' => true,
            ]),
            Market::query()->create([
                'name' => 'Market B',
                'slug' => 'market-b-' . str()->random(8),
                'timezone' => 'Europe/Moscow',
                'is_active' => true,
            ]),
        ];
    }

    private function tenant(Market $market): Tenant
    {
        return Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant ' . str()->random(8),
            'status' => 'active',
            'is_active' => true,
        ]));
    }

    private function user(?Market $market): User
    {
        return User::factory()->create([
            'market_id' => $market ? (int) $market->id : null,
        ]);
    }

    private function space(Market $market): MarketSpace
    {
        return MarketSpace::withoutEvents(fn (): MarketSpace => MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'S-' . str()->random(8),
            'status' => 'free',
            'is_active' => true,
        ]));
    }

    private function document(Market $market): MarketDocument
    {
        return MarketDocument::query()->create([
            'market_id' => (int) $market->id,
            'visibility' => MarketDocument::VISIBILITY_SHARED,
            'category' => MarketDocument::CATEGORY_GENERAL,
            'title' => 'Market document ' . str()->random(8),
        ]);
    }

    private function documentFolder(Market $market): MarketDocumentFolder
    {
        return MarketDocumentFolder::query()->create([
            'market_id' => (int) $market->id,
            'visibility' => MarketDocument::VISIBILITY_SHARED,
            'name' => 'Folder ' . str()->random(8),
        ]);
    }

    private function category(Market $market): MarketplaceCategory
    {
        return MarketplaceCategory::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Category ' . str()->random(8),
            'slug' => 'category-' . str()->random(8),
            'is_active' => true,
        ]);
    }

    private function product(Market $market, Tenant $tenant): MarketplaceProduct
    {
        return MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'title' => 'Product ' . str()->random(8),
            'slug' => 'product-' . str()->random(8),
            'currency' => 'RUB',
            'stock_qty' => 1,
            'is_active' => true,
            'is_featured' => false,
        ]);
    }

    private function holiday(Market $market): MarketHoliday
    {
        return MarketHoliday::withoutEvents(fn (): MarketHoliday => MarketHoliday::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Holiday ' . str()->random(8),
            'starts_at' => now()->toDateString(),
            'all_day' => true,
        ]));
    }

    private function chat(Market $market, Tenant $tenant, User $buyer): MarketplaceChat
    {
        return MarketplaceChat::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'buyer_user_id' => (int) $buyer->id,
            'subject' => 'Question',
            'status' => 'open',
            'last_message_at' => now(),
            'buyer_unread_count' => 0,
            'tenant_unread_count' => 0,
        ]);
    }

    private function assertValidationFailure(string $field, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            self::assertSame([$message], $exception->errors()[$field] ?? []);

            return;
        }

        self::fail("Expected validation failure for [{$field}].");
    }
}
