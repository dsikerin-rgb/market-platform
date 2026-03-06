<x-filament-panels::page>
    <style>
        .mps-wrap { max-width: 980px; margin: 0 auto; display: grid; gap: 16px; }
        .mps-actions {
            position: sticky;
            bottom: 18px;
            z-index: 20;
            border-radius: 14px;
            border: 1px solid rgba(156, 163, 175, .24);
            background: rgba(255,255,255,.82);
            backdrop-filter: blur(8px);
            padding: 14px 16px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }
        .dark .mps-actions {
            background: rgba(15,23,42,.72);
            border-color: rgba(55, 65, 81, .75);
        }
        .mps-top-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .mps-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(156, 163, 175, .24);
            background: rgba(255,255,255,.72);
            text-decoration: none;
            color: inherit;
            font-weight: 600;
        }
        .dark .mps-link {
            background: rgba(15,23,42,.42);
            border-color: rgba(55,65,81,.75);
        }
        .mps-note {
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(186, 214, 241, .9);
            background: linear-gradient(180deg, #f6fbff, #ffffff);
            color: #29476a;
        }
    </style>

    <div class="mps-wrap">
        <div class="mps-top-links">
            <a class="mps-link" href="{{ \App\Filament\Pages\MarketplaceSettings::getUrl() }}">Общие настройки</a>
            <a class="mps-link" href="{{ \App\Filament\Resources\MarketplaceSlideResource::getUrl('index') }}">Слайды</a>
            <a class="mps-link" href="{{ route('marketplace.entry') }}" target="_blank" rel="noopener">Открыть маркетплейс</a>
        </div>

        <div class="mps-note">
            Акции, праздники и санитарные дни продолжают приходить из календаря в публичный блок анонсов.
            Слайды маркетплейса — это отдельный промо-слой для главной страницы, который не заменяет календарные события.
        </div>

        <form wire:submit.prevent="save" class="grid gap-4">
            {{ $this->form }}

            <div class="mps-actions">
                <x-filament::button type="submit">
                    Сохранить настройки
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
