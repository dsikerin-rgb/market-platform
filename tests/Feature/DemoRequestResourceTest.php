<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DemoRequestResource;
use App\Models\DemoRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DemoRequestResourceTest extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');
        config()->set('saas_progress.access.allowed_user_ids', [999]);
        config()->set('saas_progress.access.allowed_user_emails', ['321_123@bk.ru']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->migrateTestSchema();
    }

    public function test_allowed_owner_can_apply_quick_lead_status(): void
    {
        $this->actingAs(User::factory()->create([
            'email' => '321_123@bk.ru',
        ]));

        $request = DemoRequest::query()->create([
            'name' => 'Иван Петров',
            'organization' => 'Рынок Центральный',
            'email' => 'ivan@example.test',
            'source' => 'demo_quick_start',
            'status' => DemoRequest::STATUS_NEW,
        ]);

        self::assertSame(DemoRequest::STATUS_NEW, $request->status);
        self::assertNull($request->processed_at);

        DemoRequestResource::applyLeadStatus($request, DemoRequest::STATUS_CONTACTED);

        $request->refresh();

        self::assertSame(DemoRequest::STATUS_CONTACTED, $request->status);
        self::assertNotNull($request->processed_at);
    }

    public function test_unlisted_user_cannot_apply_quick_lead_status(): void
    {
        $this->actingAs(User::factory()->create([
            'email' => 'staff@example.test',
        ]));

        $request = DemoRequest::query()->create([
            'name' => 'Ольга Миронова',
            'organization' => 'Рынок Южный',
            'email' => 'olga@example.test',
            'source' => 'demo_quick_start',
            'status' => DemoRequest::STATUS_NEW,
        ]);

        try {
            DemoRequestResource::applyLeadStatus($request, DemoRequest::STATUS_QUALIFIED);
            self::fail('Expected 403 when an unlisted user updates demo request status.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $request->refresh();

        self::assertSame(DemoRequest::STATUS_NEW, $request->status);
        self::assertNull($request->processed_at);
    }

    private function migrateTestSchema(): void
    {
        Schema::dropIfExists('demo_requests');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 64)->nullable();
            $table->foreignId('market_id')->nullable();
            $table->foreignId('tenant_id')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_06_30_100000_create_demo_requests_table.php');
        $migration->up();
    }
}
