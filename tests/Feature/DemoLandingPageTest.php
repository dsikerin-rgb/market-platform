<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoLandingPageTest extends TestCase
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

        $this->migrateDemoRequestTestSchema();
    }

    public function test_demo_landing_page_is_public(): void
    {
        $this->get('/demo')
            ->assertOk()
            ->assertSee('Демо-доступ к системе управления рынком')
            ->assertSee('Подключиться к демо')
            ->assertSee('Live 1C, mail, Telegram и webhooks', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="organization"', false)
            ->assertSee('name="consent"', false)
            ->assertSee(route('demo.request'), false)
            ->assertSee('Отправить заявку');
    }

    public function test_demo_request_form_stores_lead_and_notifies_owner(): void
    {
        config()->set('demo_pilot.owner_emails', '321_123@bk.ru');

        $owner = User::factory()->create([
            'email' => '321_123@bk.ru',
        ]);

        $this->post(route('demo.request'), [
            'name' => 'Иван Петров',
            'organization' => 'Рынок Центральный',
            'email' => 'CLIENT@example.test',
            'phone' => '+7 913 000-00-00',
            'city' => 'Новосибирск',
            'market_format' => 'рынок',
            'spaces_count' => 120,
            'request_type' => DemoRequest::TYPE_PILOT,
            'message' => 'Хотим посмотреть пилот.',
            'consent' => '1',
        ])
            ->assertRedirect(route('demo.landing', ['request_sent' => 1]))
            ->assertSessionHas('demo_request_status', 'sent')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('demo_requests', [
            'status' => DemoRequest::STATUS_NEW,
            'request_type' => DemoRequest::TYPE_PILOT,
            'name' => 'Иван Петров',
            'organization' => 'Рынок Центральный',
            'email' => 'client@example.test',
            'city' => 'Новосибирск',
            'spaces_count' => 120,
            'source' => 'demo_landing',
        ]);

        $notification = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', (int) $owner->id)
            ->first();

        $this->assertNotNull($notification);
        $notificationData = json_decode((string) $notification->data, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Новая заявка на демо', $notificationData['title'] ?? null);
        $this->assertStringContainsString('Рынок Центральный', (string) ($notificationData['body'] ?? ''));
    }

    public function test_demo_request_honeypot_does_not_store_lead(): void
    {
        $this->post(route('demo.request'), [
            'name' => 'Бот',
            'organization' => 'Спам',
            'email' => 'bot@example.test',
            'request_type' => DemoRequest::TYPE_DEMO,
            'company_website' => 'https://spam.example',
            'consent' => '1',
        ])
            ->assertRedirect(route('demo.landing', ['request_sent' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('demo_requests', 0);
    }

    public function test_demo_request_requires_consent(): void
    {
        $this->from(route('demo.landing'))
            ->post(route('demo.request'), [
                'name' => 'Иван Петров',
                'organization' => 'Рынок Центральный',
                'email' => 'client@example.test',
                'request_type' => DemoRequest::TYPE_DEMO,
            ])
            ->assertRedirect(route('demo.landing'))
            ->assertSessionHasErrors('consent');

        $this->assertDatabaseCount('demo_requests', 0);
    }

    private function migrateDemoRequestTestSchema(): void
    {
        Schema::dropIfExists('demo_requests');
        Schema::dropIfExists('notifications');
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

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_06_30_100000_create_demo_requests_table.php');
        $migration->up();
    }
}
