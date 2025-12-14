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

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->selectedMarketId = session($this->sessionKey());
    }

    public function updatedSelectedMarketId(): void
    {
        $value = $this->selectedMarketId ? (int) $this->selectedMarketId : null;

        session([$this->sessionKey() => $value]);

        // Перезагрузка, чтобы все ресурсы/виджеты перечитали session и перестроили запросы
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

        // ВАЖНО: совпадает с тем, что используется в ресурсах:
        // session('filament.admin.selected_market_id')
        return "filament.{$panelId}.selected_market_id";
    }
}
