<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DemoLandingPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');
    }

    public function test_demo_landing_page_is_public(): void
    {
        $this->get('/demo')
            ->assertOk()
            ->assertSee('Демо-доступ к системе управления рынком')
            ->assertSee('Подключиться к демо')
            ->assertSee('Live 1C, mail, Telegram и webhooks', false)
            ->assertSee('Отправить заявку');
    }
}
