<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Market;
use App\Models\User;
use App\Support\DemoPilotSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoAccessController extends Controller
{
    public function signIn(Request $request, DemoPilotSettings $settings): RedirectResponse
    {
        abort_unless($settings->publicLoginEnabled(), 404);

        $market = Market::query()
            ->where('slug', $settings->marketSlug())
            ->first();

        abort_unless($market, 404);
        abort_unless(
            data_get($market->settings, 'demo_pilot.synthetic_source') === $settings->syntheticSource(),
            404,
        );

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$settings->publicLoginEmail()])
            ->first();

        abort_unless($user, 404);
        abort_unless($this->isAllowedPublicDemoUser($user, $market, $settings), 404);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to($settings->publicLoginRedirectPath());
    }

    private function isAllowedPublicDemoUser(User $user, Market $market, DemoPilotSettings $settings): bool
    {
        if ((int) ($user->market_id ?? 0) !== (int) $market->id) {
            return false;
        }

        if (mb_strtolower(trim((string) $user->email), 'UTF-8') !== $settings->publicLoginEmail()) {
            return false;
        }

        if (
            ! method_exists($user, 'hasAnyRole')
            || ! $user->hasAnyRole(['market-owner-director', 'market-admin', 'demo-market-admin'])
        ) {
            return false;
        }

        $source = data_get($user->notification_preferences, 'demo_pilot.synthetic_source');

        return $source === null || $source === '' || $source === $settings->syntheticSource();
    }
}
