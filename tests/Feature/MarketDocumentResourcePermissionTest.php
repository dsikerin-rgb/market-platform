<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketDocumentResource;
use App\Models\MarketDocument;
use App\Models\User;
use Tests\TestCase;

class MarketDocumentResourcePermissionTest extends TestCase
{
    public function test_regular_staff_cannot_manage_shared_document_uploaded_by_another_user(): void
    {
        $staff = $this->actingAsTestUser(id: 2, marketId: 1);
        $document = $this->sharedDocument(marketId: 1, uploadedByUserId: 1);

        self::assertSame(2, (int) $staff->id);

        self::assertFalse(MarketDocumentResource::canManageDocument($document));
        self::assertFalse(MarketDocumentResource::canEdit($document));
        self::assertTrue(MarketDocumentResource::canShareDocument($document));
        self::assertFalse(MarketDocumentResource::canBulkManageDocuments());
    }

    public function test_uploader_can_manage_their_shared_document(): void
    {
        $this->actingAsTestUser(id: 1, marketId: 1);
        $document = $this->sharedDocument(marketId: 1, uploadedByUserId: 1);

        self::assertTrue(MarketDocumentResource::canManageDocument($document));
        self::assertTrue(MarketDocumentResource::canEdit($document));
    }

    public function test_market_admin_can_manage_shared_documents_and_bulk_actions(): void
    {
        $this->actingAsTestUser(id: 2, marketId: 1, marketAdmin: true);
        $document = $this->sharedDocument(marketId: 1, uploadedByUserId: 1);

        self::assertTrue(MarketDocumentResource::canManageDocument($document));
        self::assertTrue(MarketDocumentResource::canBulkManageDocuments());
    }

    public function test_owner_can_manage_personal_document(): void
    {
        $document = $this->personalDocument(marketId: 1, ownerUserId: 1);

        $this->actingAsTestUser(id: 1, marketId: 1);
        self::assertTrue(MarketDocumentResource::canManageDocument($document));

        $this->actingAsTestUser(id: 2, marketId: 1);
        self::assertFalse(MarketDocumentResource::canManageDocument($document));
    }

    public function test_uploader_cannot_share_another_users_personal_document(): void
    {
        $document = (new MarketDocument())->forceFill([
            'id' => 12,
            'market_id' => 1,
            'owner_user_id' => 1,
            'uploaded_by_user_id' => 2,
            'visibility' => MarketDocument::VISIBILITY_PERSONAL,
        ]);

        $this->actingAsTestUser(id: 2, marketId: 1);

        self::assertFalse(MarketDocumentResource::canShareDocument($document));
    }

    private function actingAsTestUser(
        int $id,
        int $marketId,
        bool $superAdmin = false,
        bool $marketAdmin = false,
    ): MarketDocumentPermissionUser {
        $user = (new MarketDocumentPermissionUser())
            ->forceFill([
                'id' => $id,
                'market_id' => $marketId,
                'name' => 'User ' . $id,
                'email' => 'user-' . $id . '@example.test',
            ]);

        $user->superAdmin = $superAdmin;
        $user->marketAdmin = $marketAdmin;

        $this->actingAs($user);

        return $user;
    }

    private function sharedDocument(int $marketId, int $uploadedByUserId): MarketDocument
    {
        return (new MarketDocument())->forceFill([
            'id' => 10,
            'market_id' => $marketId,
            'uploaded_by_user_id' => $uploadedByUserId,
            'visibility' => MarketDocument::VISIBILITY_SHARED,
        ]);
    }

    private function personalDocument(int $marketId, int $ownerUserId): MarketDocument
    {
        return (new MarketDocument())->forceFill([
            'id' => 11,
            'market_id' => $marketId,
            'owner_user_id' => $ownerUserId,
            'uploaded_by_user_id' => $ownerUserId,
            'visibility' => MarketDocument::VISIBILITY_PERSONAL,
        ]);
    }
}

class MarketDocumentPermissionUser extends User
{
    public bool $superAdmin = false;

    public bool $marketAdmin = false;

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function isMarketAdmin(): bool
    {
        return $this->marketAdmin;
    }
}
