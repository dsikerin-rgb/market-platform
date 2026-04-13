<x-filament-panels::page.simple :heading="null" :subheading="null" :logo="false">
    <div class="login-container">
        <div class="login-header">
            <p style="position:relative;z-index:1;font-size:0.875rem;opacity:0.9;margin-bottom:0.25rem;">Управление рынком</p>
            <h1>Войдите в свой аккаунт</h1>
        </div>

        <div class="login-body">
            {{ $this->form }}
            
            <div style="margin-top: 1.5rem;">
                <x-filament::button type="submit" wire:click="authenticate" style="width: 100%; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border: none; box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);">
                    Войти
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page.simple>
