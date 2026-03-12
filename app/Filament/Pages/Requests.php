<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class Requests extends Page
{
    protected static ?string $title = 'Обращения';

    protected static ?string $navigationLabel = 'Обращения';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.requests';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
