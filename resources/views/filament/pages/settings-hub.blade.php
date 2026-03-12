<x-filament-panels::page>
    <style>
        .settings-hub {
            display: grid;
            gap: 16px;
            max-width: 1080px;
            margin: 0 auto;
        }
        .settings-hub-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        @media (min-width: 960px) {
            .settings-hub-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        .settings-card {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 180px;
            padding: 18px 20px;
            border-radius: 18px;
            border: 1px solid rgba(156, 163, 175, .22);
            background: rgba(255,255,255,.9);
            text-decoration: none;
            color: inherit;
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }
        .settings-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
            border-color: rgba(59, 130, 246, .24);
        }
        .dark .settings-card {
            background: rgba(17,24,39,.7);
            border-color: rgba(55,65,81,.75);
        }
        .settings-card-title {
            font-size: 1.1rem;
            font-weight: 700;
        }
        .settings-card-text {
            color: rgb(100 116 139);
            line-height: 1.55;
        }
        .dark .settings-card-text {
            color: rgb(148 163 184);
        }
        .settings-card-footer {
            margin-top: auto;
            font-weight: 600;
            color: rgb(30 64 175);
        }
        .dark .settings-card-footer {
            color: rgb(147 197 253);
        }
    </style>

    <div class="settings-hub">
        <div class="settings-hub-grid">
            @if (\App\Filament\Pages\MarketSettings::canAccess())
                <a href="{{ $this->getMarketSettingsUrl() }}" class="settings-card">
                    <div class="settings-card-title">Настройки рынка</div>
                    <div class="settings-card-text">
                        Основные параметры ярмарки, карта, уведомления, получатели обращений и каналы доставки.
                    </div>
                    <div class="settings-card-footer">Открыть</div>
                </a>
            @endif

            @if (\App\Filament\Pages\MarketplaceSettings::canAccess())
                <a href="{{ $this->getMarketplaceSettingsUrl() }}" class="settings-card">
                    <div class="settings-card-title">Настройки маркетплейса</div>
                    <div class="settings-card-text">
                        Бренд, логотип, hero-блок, поведение главной страницы и параметры инфо-слайдера.
                    </div>
                    <div class="settings-card-footer">Открыть</div>
                </a>
            @endif

            @if (\App\Filament\Resources\Roles\RoleResource::canViewAny())
                <a href="{{ $this->getRolesUrl() }}" class="settings-card">
                    <div class="settings-card-title">Роли и права</div>
                    <div class="settings-card-text">
                        Назначение ролей, сценарии уведомлений и права доступа сотрудников, включая права маркетплейса.
                    </div>
                    <div class="settings-card-footer">Открыть</div>
                </a>
            @endif
        </div>
    </div>
</x-filament-panels::page>
