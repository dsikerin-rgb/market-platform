<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketplaceSlideResource;
use App\Filament\Resources\ReportResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Support\AdminCapabilities;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class SettingsHub extends Page
{
    protected static ?string $title = 'Настройки';

    protected static ?string $navigationLabel = 'Настройки';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 120;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.pages.settings-hub';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! AdminCapabilities::canViewFinance($user) && ! MarketSettings::canAccess()) {
            return false;
        }

        return MarketSettings::canAccess()
            || AiAgentSettingsPage::canAccess()
            || MailDiagnostics::canAccess()
            || MarketplaceSettings::canAccess()
            || ReportResource::canViewAny()
            || MarketplaceSlideResource::canViewAny()
            || RoleResource::canViewAny();
    }

    public function getMarketSettingsUrl(): string
    {
        return MarketSettings::getUrl();
    }

    public function getMarketplaceSettingsUrl(): string
    {
        return MarketplaceSettings::getUrl();
    }

    public function getAiAgentSettingsUrl(): string
    {
        return AiAgentSettingsPage::getUrl();
    }

    public function getMailDiagnosticsUrl(): string
    {
        return MailDiagnostics::getUrl();
    }

    public function getMarketplaceSlidesUrl(): string
    {
        return MarketplaceSlideResource::getUrl('index');
    }

    public function getRolesUrl(): string
    {
        return RoleResource::getUrl('index');
    }

    public function getReportsUrl(): string
    {
        return ReportResource::getUrl('index');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
