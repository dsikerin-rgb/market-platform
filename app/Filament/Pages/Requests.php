<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class Requests extends Page
{
    protected static ?string $title = 'Обращения';
    protected static ?string $navigationLabel = 'Обращения';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox';

    // В одну группу с "Задачами"
    protected static \UnitEnum|string|null $navigationGroup = 'Оперативная работа';

    protected static ?int $navigationSort = 3;

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
}
