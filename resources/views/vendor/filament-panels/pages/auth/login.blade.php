<x-filament-panels::page.simple :heading="null" :subheading="null" :logo="false">
    <style>
        .fi-simple-layout {
            background-color: #f0f9ff;
            overflow: hidden;
            min-height: 100vh;
        }
        .fi-simple-layout::before {
            content: '';
            position: fixed;
            top: 50%;
            left: 50%;
            width: 200vw;
            height: 200vh;
            margin-left: -100vw;
            margin-top: -100vh;
            background-image: url('/images/login-pattern.png');
            background-size: 180px 180px;
            opacity: 0.15;
            transform: rotate(45deg);
            pointer-events: none;
            z-index: 0;
        }
        .fi-simple-main-ctn {
            position: relative;
            z-index: 1;
        }
        .login-container {
            max-width: 720px !important;
            width: 100%;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            box-shadow: 0 20px 60px rgba(14, 116, 144, 0.12), 0 8px 24px rgba(3, 105, 161, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .login-header {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%);
            padding: 1.25rem 1.5rem 1rem;
            text-align: center;
            color: white;
            position: relative;
            border-radius: 1.5rem 1.5rem 0 0;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.08'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.3;
        }

        .login-header h1 {
            position: relative;
            z-index: 1;
            font-size: 1.75rem;
            font-weight: 600;
            margin: 0;
        }

        .login-body {
            padding: 2rem 1.5rem 2.5rem;
        }

        .login-body .fi-fo-field-wrp {
            margin-bottom: 1.25rem;
        }

        .login-body .fi-btn {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%) !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3) !important;
            transition: all 0.2s ease;
        }

        .login-body .fi-btn:hover {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important;
            box-shadow: 0 6px 16px rgba(14, 165, 233, 0.4) !important;
            transform: translateY(-1px);
        }
    </style>
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
