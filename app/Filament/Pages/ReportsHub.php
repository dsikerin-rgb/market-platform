<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportRunResource;
use App\Models\Report;
use App\Models\ReportRun;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ReportsHub extends Page
{
    protected static ?string $title = 'Отчёты';
    protected static ?string $navigationLabel = 'Отчёты';

    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports-hub';

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

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
        return false;
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

    public function getMarketName(): string
    {
        return filament()->getTenant()?->name
            ?? filament()->auth()->user()?->market?->name
            ?? 'Выберите рынок';
    }

    public function getReportCount(): int
    {
        return Report::query()
            ->when($this->marketId(), fn ($query, $marketId) => $query->where('market_id', $marketId))
            ->count();
    }

    public function getActiveReportCount(): int
    {
        return Report::query()
            ->when($this->marketId(), fn ($query, $marketId) => $query->where('market_id', $marketId))
            ->where('is_active', true)
            ->count();
    }

    public function getRunCount(): int
    {
        return ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->count();
    }

    public function getFailedRunCount(): int
    {
        return ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->whereIn('status', ['failed', 'error'])
            ->count();
    }

    public function getLastRunLabel(): ?string
    {
        $lastRun = ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->latest('started_at')
            ->first(['started_at']);

        return $lastRun?->started_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
    }

    public function getLatestRunStatusLabel(): ?string
    {
        $lastRun = ReportRun::query()
            ->whereHas('report', fn ($query) => $query->when($this->marketId(), fn ($subQuery, $marketId) => $subQuery->where('market_id', $marketId)))
            ->latest('started_at')
            ->first(['status']);

        if (! filled($lastRun?->status)) {
            return null;
        }

        return Str::of((string) $lastRun->status)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    protected function marketId(): ?int
    {
        return static::selectedMarketIdFromSession()
            ?? filament()->auth()->user()?->market_id;
    }
}
