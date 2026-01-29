<?php
# app/Filament/Widgets/MarketSwitcherWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use Carbon\CarbonImmutable;
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
        // Единый ключ выбора рынка для всего дашборда
        $value = session('dashboard_market_id');

        // fallback-ключи (на случай старых версий/кэшей)
        if (blank($value)) {
            $value = session($this->sessionKey());
        }

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament_{$panelId}_market_id");
        }

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        $this->selectedMarketId = filled($value) ? (int) $value : null;
        $this->returnUrl = request()->fullUrl();
    }

    public function updatedSelectedMarketId(): void
    {
        $value = $this->selectedMarketId ? (int) $this->selectedMarketId : null;

        // 1) Главный ключ (его читают Dashboard и виджеты)
        session(['dashboard_market_id' => $value]);

        // 2) Совместимость: старые/разные ключи (чтобы ничего не "отвалилось" внезапно)
        session([$this->sessionKey() => $value]);

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        session(["filament_{$panelId}_market_id" => $value]);
        session(['filament.admin.selected_market_id' => $value]);

        // 3) При смене рынка — дефолтим месяц на текущий месяц в TZ выбранного рынка
        if ($value) {
            $tz = (string) config('app.timezone', 'UTC');

            $market = Market::query()->select(['id', 'timezone'])->find($value);
            $candidate = trim((string) ($market?->timezone ?? ''));

            if ($candidate !== '') {
                $tz = $candidate;
            }

            try {
                $month = CarbonImmutable::now($tz)->format('Y-m');
            } catch (\Throwable) {
                $month = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->format('Y-m');
            }

            session(['dashboard_month' => $month]);
        }

        $target = request()->headers->get('referer')
            ?: $this->returnUrl
            ?: url('/admin');

        if (is_string($target) && str_contains($target, '/livewire/update')) {
            $target = url('/admin');
        }

        // ВАЖНО: полный reload страницы, чтобы все виджеты перечитали session и пересобрали запросы.
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

    /**
     * Старый ключ (оставляем для совместимости).
     */
    protected function sessionKey(): string
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        return "filament.{$panelId}.selected_market_id";
    }
}
