<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MarketSettings;
use App\Models\Market;
use App\Models\User;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSettingsDebtMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $this->superAdmin = User::factory()->create([
            'market_id' => $this->market->id,
        ]);
        $this->superAdmin->assignRole('super-admin');
    }

    /**
     * Тест: сохранение настроек мониторинга задолженности
     */
    public function test_can_save_debt_monitoring_settings(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Устанавливаем сессию для выбора рынка
        session(['dashboard_market_id' => $this->market->id]);

        $page = app(MarketSettings::class);
        $page->mount();

        // Устанавливаем значения — заполняем все обязательные поля
        $page->form->fill([
            'name' => $this->market->name,
            'address' => $this->market->address ?? 'Тестовый адрес',
            'timezone' => $this->market->timezone ?? 'Europe/Moscow',
            'debt_monitoring_grace_days' => 10,
            'debt_monitoring_yellow_after_days' => 5,
            'debt_monitoring_red_after_days' => 60,
            'debt_monitoring_tenant_aggregate_mode' => 'dominant',
            'holiday_default_notify_before_days' => 7,
            'holiday_notification_recipient_user_ids' => [],
            'request_notification_recipient_user_ids' => [],
            'request_repair_notification_recipient_user_ids' => [],
            'request_help_notification_recipient_user_ids' => [],
            'notification_channels_calendar' => ['database'],
            'notification_channels_requests' => ['database'],
            'notification_channels_messages' => ['database'],
            'notification_channels_tasks' => ['database'],
            'notification_channels_reminders' => ['database'],
        ]);

        // Сохраняем
        $page->save();

        // Проверяем, что настройки сохранились в БД
        $this->market->refresh();

        $settings = $this->market->settings ?? [];

        $this->assertEquals(10, $settings['debt_monitoring']['grace_days']);
        $this->assertEquals(5, $settings['debt_monitoring']['yellow_after_days']);
        $this->assertEquals(60, $settings['debt_monitoring']['red_after_days']);
        $this->assertEquals('dominant', $settings['debt_monitoring']['tenant_aggregate_mode']);
    }

    /**
     * Тест: значения по умолчанию при отсутствии настроек
     */
    public function test_debt_monitoring_default_values(): void
    {
        $this->actingAs($this->superAdmin, 'web');
        session(['dashboard_market_id' => $this->market->id]);

        $page = app(MarketSettings::class);
        $page->mount();

        // Проверяем значения по умолчанию
        $state = $page->data;

        $this->assertEquals(5, $state['debt_monitoring_grace_days']);
        $this->assertEquals(1, $state['debt_monitoring_yellow_after_days']);
        $this->assertEquals(30, $state['debt_monitoring_red_after_days']);
        $this->assertEquals('worst', $state['debt_monitoring_tenant_aggregate_mode']);
    }

    /**
     * Тест: загрузка существующих настроек
     */
    public function test_load_existing_debt_monitoring_settings(): void
    {
        // Сохраняем настройки
        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 7,
                'yellow_after_days' => 3,
                'red_after_days' => 45,
                'tenant_aggregate_mode' => 'worst',
            ],
        ];
        $this->market->save();

        $this->actingAs($this->superAdmin, 'web');
        session(['dashboard_market_id' => $this->market->id]);

        $page = app(MarketSettings::class);
        $page->mount();

        // Проверяем, что настройки загрузились
        $state = $page->data;

        $this->assertEquals(7, $state['debt_monitoring_grace_days']);
        $this->assertEquals(3, $state['debt_monitoring_yellow_after_days']);
        $this->assertEquals(45, $state['debt_monitoring_red_after_days']);
        $this->assertEquals('worst', $state['debt_monitoring_tenant_aggregate_mode']);
    }

    /**
     * Тест: обратная совместимость — загрузка orange_after_days как yellow_after_days
     */
    public function test_backward_compatibility_orange_to_yellow(): void
    {
        // Сохраняем настройки со старым полем orange_after_days
        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'orange_after_days' => 10,
                'red_after_days' => 60,
                'tenant_aggregate_mode' => 'worst',
            ],
        ];
        $this->market->save();

        $this->actingAs($this->superAdmin, 'web');
        session(['dashboard_market_id' => $this->market->id]);

        $page = app(MarketSettings::class);
        $page->mount();

        // Проверяем, что orange_after_days загрузился как yellow_after_days
        $state = $page->data;

        $this->assertEquals(5, $state['debt_monitoring_grace_days']);
        $this->assertEquals(10, $state['debt_monitoring_yellow_after_days']);
        $this->assertEquals(60, $state['debt_monitoring_red_after_days']);
    }

    public function test_can_save_personal_notification_preferences_from_market_settings(): void
    {
        $this->actingAs($this->superAdmin, 'web');
        session(['dashboard_market_id' => $this->market->id]);

        $page = app(MarketSettings::class);
        $page->mount();

        $page->form->fill([
            'name' => $this->market->name,
            'address' => $this->market->address ?? 'Тестовый адрес',
            'timezone' => $this->market->timezone ?? 'Europe/Moscow',
            'debt_monitoring_grace_days' => 5,
            'debt_monitoring_yellow_after_days' => 1,
            'debt_monitoring_red_after_days' => 30,
            'debt_monitoring_tenant_aggregate_mode' => 'worst',
            'holiday_default_notify_before_days' => 7,
            'holiday_notification_recipient_user_ids' => [],
            'request_notification_recipient_user_ids' => [],
            'request_repair_notification_recipient_user_ids' => [],
            'request_help_notification_recipient_user_ids' => [],
            'notification_channels_calendar' => ['database'],
            'notification_channels_requests' => ['database'],
            'notification_channels_messages' => ['database'],
            'notification_channels_tasks' => ['database'],
            'notification_channels_reminders' => ['database'],
            'personal_notification_channels' => ['database', 'mail'],
            'personal_notification_topics' => ['requests', UserNotificationPreferences::TOPIC_ONE_C_INTEGRATIONS],
        ]);

        $page->save();

        $this->superAdmin->refresh();

        $this->assertSame(
            ['database', 'mail'],
            $this->superAdmin->notification_preferences['channels'] ?? [],
        );
        $this->assertSame(
            ['requests', UserNotificationPreferences::TOPIC_ONE_C_INTEGRATIONS],
            $this->superAdmin->notification_preferences['topics'] ?? [],
        );
    }
}
