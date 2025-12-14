<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportRunResource;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Route;

class ReportsHub extends Page
{
    protected static ?string $title = 'Отчёты';
    protected static ?string $navigationLabel = 'Отчёты';

    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports-hub';

    public static function canAccess(): bool
    {
        $user = filament()->auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    protected static function getPageRouteName(): string
    {
        $slug = static::$slug ?: 'reports';

        return "filament.admin.pages.{$slug}";
    }

    public static function shouldRegisterNavigation(): bool
    {
        // если роута ещё нет — НЕ регистрируем пункт, иначе sidebar упадёт на getUrl()
        return static::canAccess() && Route::has(static::getPageRouteName());
    }

    public static function getNavigationUrl(): string
    {
        // не используем route(), пока роут не существует
        $slug = static::$slug ?: 'reports';

        return "/admin/{$slug}";
    }

    public function getTemplateUrl(): string
    {
        return ReportResource::getUrl('index');
    }

    public function getRunsUrl(): string
    {
        return ReportRunResource::getUrl('index');
    }
}
