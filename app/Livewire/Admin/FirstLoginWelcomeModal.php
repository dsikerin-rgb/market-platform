<?php
# app/Livewire/Admin/FirstLoginWelcomeModal.php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Support\FirstLoginWelcomeNotice;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class FirstLoginWelcomeModal extends Component
{
    public bool $shouldShow = false;

    public function mount(FirstLoginWelcomeNotice $notice): void
    {
        $this->shouldShow = $notice->shouldShow(
            Filament::auth()->user(),
            (bool) session()->get(FirstLoginWelcomeNotice::SESSION_KEY, false),
        );
    }

    public function acknowledge(): void
    {
        session()->put(FirstLoginWelcomeNotice::SESSION_KEY, true);

        $notice = app(FirstLoginWelcomeNotice::class);
        $user = Filament::auth()->user();

        if ($user && $user->exists) {
            $preferences = (array) ($user->notification_preferences ?? []);
            $preferences[FirstLoginWelcomeNotice::PREFERENCE_KEY] = $notice->acknowledgementPreference();
            $user->notification_preferences = $preferences;
            $user->save();
        }

        $this->shouldShow = false;
    }

    public function render(): View
    {
        return view('livewire.admin.first-login-welcome-modal');
    }
}
