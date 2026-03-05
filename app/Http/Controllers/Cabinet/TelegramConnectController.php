<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TelegramChatLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramConnectController extends Controller
{
    public function __invoke(Request $request, TelegramChatLinkService $chatLinkService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! (bool) config('services.telegram.enabled', false)) {
            return back()->with('error', 'Telegram временно отключен в настройках сервиса.');
        }

        $payload = $chatLinkService->issue($user, 20);
        $deepLink = trim((string) ($payload['deep_link'] ?? ''));
        $command = trim((string) ($payload['command'] ?? ''));

        if ($deepLink !== '') {
            return redirect()->away($deepLink);
        }

        return back()->with(
            'success',
            $command !== ''
                ? ('Ссылка сгенерирована. Откройте бота и отправьте: ' . $command)
                : 'Ссылка сгенерирована. Откройте Telegram-бота и отправьте /start.'
        );
    }
}

