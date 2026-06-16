<?php

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
            session()->boolean(FirstLoginWelcomeNotice::SESSION_KEY),
        );
    }

    public function acknowledge(): void
    {
        session()->put(FirstLoginWelcomeNotice::SESSION_KEY, true);

        $this->shouldShow = false;
    }

    public function render(): View
    {
        return view('livewire.admin.first-login-welcome-modal');
    }
}
