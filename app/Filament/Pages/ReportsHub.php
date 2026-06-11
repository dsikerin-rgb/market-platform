<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportRunResource;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Report;
use App\Models\ReportRun;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ReportsHub extends Page
{
    private const SECTIONS = [
        'templates',
        'runs',
        'accruals',
        'documents',
        'settlements',
    ];

    protected static ?string $title = 'Отчёты';

    protected static ?string $navigationLabel = 'Отчёты';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports-hub';

    public string $section = 'templates';

    protected array $queryString = [
        'section' => ['except' => 'templates'],
    ];

    public function mount(): void
    {
        $this->section = $this->normalizeSection($this->section);
    }

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
        return static::canAccess();
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl();
    }

    public function setSection(string $section): void
    {
        $this->section = $this->normalizeSection($section);
    }

    public function getTemplateUrl(): string
    {
        return ReportResource::getUrl('index');
    }

    public function getRunsUrl(): string
    {
        return ReportRunResource::getUrl('index');
    }

    public function getOneCAccrualsUrl(): string
    {
        return TenantAccrualResource::getUrl('index');
    }

    public function getOneCDocumentsUrl(): string
    {
        return OneCReconciliation::getUrl();
    }

    public function getOneCSettlementsUrl(): string
    {
        return OneCSettlements::getUrl();
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

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";
        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    protected function marketId(): ?int
    {
        return static::selectedMarketIdFromSession()
            ?? filament()->auth()->user()?->market_id;
    }

    private function normalizeSection(?string $section): string
    {
        return in_array($section, self::SECTIONS, true)
            ? $section
            : 'templates';
    }
}
