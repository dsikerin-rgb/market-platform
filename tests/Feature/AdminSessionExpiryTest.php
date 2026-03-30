<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RedirectAdminTokenMismatch;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class AdminSessionExpiryTest extends TestCase
{
    public function test_admin_token_mismatch_redirects_to_login_with_status_message(): void
    {
        $middleware = new RedirectAdminTokenMismatch();
        $request = Request::create('/admin/_session-expiry-test', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $middleware->handle($request, function (): never {
            throw new TokenMismatchException();
        });

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        self::assertSame('Сессия истекла, войдите снова.', session('status'));
    }

    public function test_admin_login_page_shows_session_expired_message_from_query(): void
    {
        $this->get('/admin/login?session_expired=1')
            ->assertOk()
            ->assertSee('Сессия истекла, войдите снова.');
    }
}
