<?php
# app/Filament/Widgets/MarketSwitcherWidget.php

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

    /**
     * URL страницы, на которой был отрендерен виджет (не /livewire/update).
     */
    public ?string $returnUrl = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->selectedMarketId = session($this->sessionKey());
        $this->returnUrl = request()->fullUrl();
    }

    public function updatedSelectedMarketId(): void
    {
        $value = $this->selectedMarketId ? (int) $this->selectedMarketId : null;

        session([$this->sessionKey() => $value]);

        $target = request()->headers->get('referer')
            ?: $this->returnUrl
            ?: url('/admin');

        if (is_string($target) && str_contains($target, '/livewire/update')) {
            $target = url('/admin');
        }

        // ВАЖНО: без navigate=true — принудительно полный reload страницы,
        // чтобы все виджеты/ресурсы перечитали session и пересобрали запросы.
        $this->redirect($target);
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

        return "filament.{$panelId}.selected_market_id";
    }
}
