<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketplaceSlideResource;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Pages\Page;

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
        return MarketSettings::canAccess()
            || MarketplaceSettings::canAccess()
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

    public function getMarketplaceSlidesUrl(): string
    {
        return MarketplaceSlideResource::getUrl('index');
    }

    public function getRolesUrl(): string
    {
        return RoleResource::getUrl('index');
    }
}
