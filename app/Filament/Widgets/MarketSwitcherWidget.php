<?php

namespace App\Filament\Widgets;

use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class MarketSwitcherWidget extends Widget
{
    protected string $view = 'filament.widgets.market-switcher-widget';

    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public ?int $selectedMarketId = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->selectedMarketId = session($this->sessionKey());
    }

    public function updatedSelectedMarketId(): void
    {
        $value = $this->selectedMarketId ? (int) $this->selectedMarketId : null;

        session([$this->sessionKey() => $value]);

        // проще и надежнее: перезагрузка страницы, чтобы все виджеты перечитали session
        $this->redirect(request()->fullUrl(), navigate: true);
    }

    protected function getViewData(): array
    {
        return [
            'markets' => Market::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
        ];
    }

    protected function sessionKey(): string
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        return "filament_{$panelId}_market_id";
    }
}
