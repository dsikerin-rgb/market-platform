<?php
# app/Filament/Widgets/MarketSwitcherWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\ContractDebt;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;

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

        if (filled($value)) {
            $this->selectedMarketId = (int) $value;
        } else {
            $this->selectedMarketId = $this->resolveDefaultMarketId();

            if ($this->selectedMarketId) {
                session(['dashboard_market_id' => $this->selectedMarketId]);
                session([$this->sessionKey() => $this->selectedMarketId]);

                $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
                session(["filament_{$panelId}_market_id" => $this->selectedMarketId]);
                session(['filament.admin.selected_market_id' => $this->selectedMarketId]);
            }
        }

        $this->returnUrl = request()->fullUrl();

        // Если рынок уже выбран, но месяц не задан/битый — выставим последний месяц с данными.
        if ($this->selectedMarketId) {
            $tz = $this->resolveMarketTimezone($this->selectedMarketId);
            $fallbackMonth = $this->resolveLastMonthWithData($this->selectedMarketId, $tz);

            $raw = session('dashboard_month');
            $month = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
                ? $raw
                : $fallbackMonth;

            session(['dashboard_month' => $month]);
            session(['dashboard_period' => $month . '-01']);
        }
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

        // 3) При смене рынка — выставляем “разумный” дефолт месяца:
        // последний месяц, где реально есть данные (иначе пользователь видит нули и думает, что фильтр сломан).
        if ($value) {
            $tz = $this->resolveMarketTimezone($value);
            $month = $this->resolveLastMonthWithData($value, $tz);

            session(['dashboard_month' => $month]);
            session(['dashboard_period' => $month . '-01']);
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

            // Текст лучше показывать отдельным абзацем/подсказкой с отступом (чинится в blade).
            'appliesNote' => 'Применяется к данным панели (виджеты и списки ресурсов).',
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

    private function resolveDefaultMarketId(): ?int
    {
        $marketId = Market::query()
            ->orderBy('name')
            ->value('id');

        return $marketId ? (int) $marketId : null;
    }

    private function resolveMarketTimezone(int $marketId): string
    {
        $tz = (string) config('app.timezone', 'UTC');

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $candidate = trim((string) ($market?->timezone ?? ''));

        if ($candidate !== '') {
            $tz = $candidate;
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    /**
     * Дефолт месяца для рынка:
     * 1) последний месяц с данными в tenant_accruals
     * 2) иначе — последний месяц в contract_debts.period (YYYY-MM)
     * 3) иначе — operations.effective_month
     * 4) иначе — текущий месяц
     */
    private function resolveLastMonthWithData(int $marketId, string $tz): string
    {
        $nowYm = CarbonImmutable::now($tz)->format('Y-m');

        if ($marketId <= 0) {
            return $nowYm;
        }

        if (DbSchema::hasTable('tenant_accruals') && DbSchema::hasColumn('tenant_accruals', 'market_id')) {
            $periodCol = $this->pickFirstExistingAccrualPeriodColumn();

            if ($periodCol) {
                try {
                    $v = DB::table('tenant_accruals')
                        ->where('market_id', $marketId)
                        ->orderByDesc($periodCol)
                        ->value($periodCol);

                    $ym = $this->normalizeYm($v, $tz);
                    if ($ym) {
                        return $ym;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        if (DbSchema::hasTable('contract_debts') && DbSchema::hasColumn('contract_debts', 'market_id') && DbSchema::hasColumn('contract_debts', 'period')) {
            try {
                $v = ContractDebt::query()
                    ->where('market_id', $marketId)
                    ->orderByDesc('period')
                    ->value('period');

                if (is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)) {
                    return $v;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (DbSchema::hasTable('operations') && DbSchema::hasColumn('operations', 'effective_month') && DbSchema::hasColumn('operations', 'market_id')) {
            try {
                $v = DB::table('operations')
                    ->where('market_id', $marketId)
                    ->orderByDesc('effective_month')
                    ->value('effective_month');

                $ym = $this->normalizeYm($v, $tz);
                if ($ym) {
                    return $ym;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $nowYm;
    }

    private function pickFirstExistingAccrualPeriodColumn(): ?string
    {
        foreach ([
            'period',
            'period_ym',
            'period_start',
            'period_date',
            'accrual_period',
            'month',
        ] as $col) {
            if (DbSchema::hasColumn('tenant_accruals', $col)) {
                return $col;
            }
        }

        return null;
    }

    private function normalizeYm(mixed $value, string $tz): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            try {
                return CarbonImmutable::instance($value)->setTimezone($tz)->format('Y-m');
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d{6}$/', $value))) {
            $s = (string) $value;

            return substr($s, 0, 4) . '-' . substr($s, 4, 2);
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^\d{4}-\d{2}$/', $value)) {
                return $value;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                try {
                    return CarbonImmutable::parse($value)->setTimezone($tz)->format('Y-m');
                } catch (\Throwable) {
                    return substr($value, 0, 7);
                }
            }
        }

        return null;
    }
}
