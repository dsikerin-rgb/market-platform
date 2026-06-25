<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\WorkProgress;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkProgressPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_owner_can_see_work_progress_navigation(): void
    {
        config()->set('saas_progress.access.allowed_user_ids', []);
        config()->set('saas_progress.access.allowed_user_emails', ['owner@example.test']);

        $owner = User::factory()->create([
            'email' => 'OWNER@example.test',
        ]);

        $this->actingAsFilamentUser($owner);

        self::assertTrue(WorkProgress::canAccess());
        self::assertTrue(WorkProgress::shouldRegisterNavigation());
    }

    public function test_super_admin_not_in_allow_list_cannot_see_work_progress(): void
    {
        config()->set('saas_progress.access.allowed_user_ids', [999999]);
        config()->set('saas_progress.access.allowed_user_emails', ['321_123@bk.ru']);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'email' => 'other-super-admin@example.test',
        ]);
        $superAdmin->assignRole('super-admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsFilamentUser($superAdmin);

        self::assertFalse(WorkProgress::canAccess());
        self::assertFalse(WorkProgress::shouldRegisterNavigation());
    }

    private function actingAsFilamentUser(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user, Filament::getAuthGuard());
    }
}
