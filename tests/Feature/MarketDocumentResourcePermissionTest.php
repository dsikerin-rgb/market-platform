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

    public function test_uploader_cannot_manage_their_shared_document_without_admin_role(): void
    {
        $this->actingAsTestUser(id: 1, marketId: 1);
        $document = $this->sharedDocument(marketId: 1, uploadedByUserId: 1);

        self::assertFalse(MarketDocumentResource::canManageDocument($document));
        self::assertFalse(MarketDocumentResource::canEdit($document));
        self::assertTrue(MarketDocumentResource::canShareDocument($document));
    }

    public function test_market_admin_can_manage_shared_documents_and_bulk_actions(): void
    {
        $this->actingAsTestUser(id: 2, marketId: 1, marketAdmin: true);
        $document = $this->sharedDocument(marketId: 1, uploadedByUserId: 1);

        self::assertTrue(MarketDocumentResource::canManageDocument($document));
        self::assertTrue(MarketDocumentResource::canBulkManageDocuments());
    }

    public function test_activity_log_is_visible_only_to_admins(): void
    {
        $this->actingAsTestUser(id: 1, marketId: 1);
        self::assertFalse(MarketDocumentResource::canViewActivityLog());

        $this->actingAsTestUser(id: 2, marketId: 1, marketAdmin: true);
        self::assertTrue(MarketDocumentResource::canViewActivityLog());

        $this->actingAsTestUser(id: 3, marketId: 1, superAdmin: true);
        self::assertTrue(MarketDocumentResource::canViewActivityLog());
    }

    public function test_disk_workspace_keeps_activity_log_out_of_folder_tree_and_exposes_file_properties(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/MarketDocumentResource.php'));
        $workspaceSource = file_get_contents(resource_path('views/filament/widgets/market-documents-workspace-widget.blade.php'));

        self::assertIsString($resourceSource);
        self::assertIsString($workspaceSource);
        self::assertStringContainsString("Action::make('properties')", $resourceSource);
        self::assertStringContainsString('Журнал действий', $workspaceSource);
        self::assertStringNotContainsString('Журнал диска', $workspaceSource);
        self::assertFileExists(resource_path('views/filament/resources/market-documents/actions/properties.blade.php'));
    }

    public function test_regular_staff_can_bulk_manage_only_personal_disk_context(): void
    {
        $this->actingAsTestUser(id: 1, marketId: 1);

        self::assertTrue(MarketDocumentResource::canBulkManageDocuments(
            new MarketDocumentBulkContext(MarketDocument::VISIBILITY_PERSONAL),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageDocuments(
            new MarketDocumentBulkContext(MarketDocument::VISIBILITY_SHARED),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageDocuments(
            new MarketDocumentBulkContext('shared-with-me'),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageDocuments());
    }

    public function test_regular_staff_can_bulk_manage_trash_only_in_trash_context(): void
    {
        $this->actingAsTestUser(id: 1, marketId: 1);

        self::assertTrue(MarketDocumentResource::canBulkManageTrash(
            new MarketDocumentBulkContext(MarketDocumentResource::TAB_TRASH),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageTrash(
            new MarketDocumentBulkContext(MarketDocument::VISIBILITY_PERSONAL),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageTrash(
            new MarketDocumentBulkContext(MarketDocument::VISIBILITY_SHARED),
        ));
        self::assertFalse(MarketDocumentResource::canBulkManageTrash());
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

class MarketDocumentBulkContext
{
    public function __construct(public ?string $activeTab)
    {
    }
}
