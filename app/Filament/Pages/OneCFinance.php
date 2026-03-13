<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\OneCContractDebtsTableWidget;
use App\Filament\Widgets\TenantAccrualsWorkspaceWidget;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class OneCFinance extends Page
{
    protected static ?string $title = '1С начисления и оплаты';

    protected static ?string $navigationLabel = '1С начисления и оплаты';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static ?int $navigationSort = 48;

    protected string $view = 'filament.pages.one-c-finance';

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

    protected function getHeaderWidgets(): array
    {
        return [
            TenantAccrualsWorkspaceWidget::class,
            OneCContractDebtsTableWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
