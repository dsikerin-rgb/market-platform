<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\IntegrationExchangeResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\TenantContract;
use App\Models\Task;
use App\Models\TenantRequest;
use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketAttentionWidget extends Widget
{
    protected string $view = 'filament.widgets.market-attention-widget';

    protected static ?int $sort = -90;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return (bool) $user->market_id;
    }

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                'items' => [],
                'emptyHeading' => 'Нет данных для пользователя',
                'emptyDescription' => 'Войдите в систему заново.',
            ];
        }

        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name', 'timezone'])->find($marketId)
            : null;

        if ($marketId <= 0 || ! $market) {
            return [
                'items' => [],
                'emptyHeading' => 'Сначала выберите рынок',
                'emptyDescription' => 'После выбора рынка критичные сигналы появятся автоматически.',
            ];
        }

        $tz = $this->resolveTimezone($market->timezone);
        $items = $this->buildAttentionItems($marketId, $tz);

        return [
            'items' => $items,
            'marketName' => $market->name,
            'emptyHeading' => 'Критичных сигналов нет',
            'emptyDescription' => 'По текущему рынку сейчас нет задач, которые требуют немедленного внимания.',
        ];
    }

    /**
     * @return list<array{
     *   title:string,
     *   value:string,
     *   tone:string,
     *   description:string,
     *   action_label:string,
     *   action_url:string
     * }>
     */
    private function buildAttentionItems(int $marketId, string $tz): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [];
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $isMarketManager = method_exists($user, 'hasRole') && $user->hasRole('market-manager');
        $isMarketOperator = method_exists($user, 'hasRole') && $user->hasRole('market-operator');

        $items = [];

        if ($isSuperAdmin || $isMarketOperator) {
            $recentExchangeErrors = $this->countRecentIntegrationErrors($marketId, $tz);

            if ($recentExchangeErrors > 0) {
                $items[] = $this->makeItem(
                    title: 'Ошибки интеграций',
                    value: (string) $recentExchangeErrors,
                    tone: 'danger',
                    description: 'Есть обмены 1С со статусом ошибки за последние 7 дней.',
                    actionLabel: 'Открыть журнал интеграций',
                    actionUrl: IntegrationExchangeResource::getUrl('index'),
                );
            }
        }

        if ($isSuperAdmin || $isMarketAdmin || $isMarketManager) {
            $contractsWithoutSpace = $this->countOperationalContractsWithoutSpace($marketId);

            if ($contractsWithoutSpace > 0) {
                $items[] = $this->makeItem(
                    title: 'Договоры без места',
                    value: (string) $contractsWithoutSpace,
                    tone: 'warning',
                    description: 'Договоры в финансовом контуре ещё не привязаны к торговым местам.',
                    actionLabel: 'Открыть договоры',
                    actionUrl: $this->appendQueryString(
                        TenantContractResource::getUrl('index'),
                        ['activeTab' => 'operational_unmapped']
                    ),
                );
            }
        }

        if ($isSuperAdmin) {
            $accrualsWithoutContract = $this->countAccrualsWithoutContract($marketId);

            if ($accrualsWithoutContract > 0) {
                $items[] = $this->makeItem(
                    title: 'Начисления без договора',
                    value: (string) $accrualsWithoutContract,
                    tone: 'warning',
                    description: 'Строки начислений ещё не удалось безопасно привязать к договору.',
                    actionLabel: 'Открыть начисления',
                    actionUrl: TenantAccrualResource::getUrl('index'),
                );
            }
        }

        if ($isSuperAdmin || $isMarketAdmin || $isMarketManager || $isMarketOperator) {
            $criticalRequests = $this->countCriticalOpenRequests($marketId);

            if ($criticalRequests > 0) {
                $items[] = $this->makeItem(
                    title: 'Срочные обращения',
                    value: (string) $criticalRequests,
                    tone: 'danger',
                    description: 'Открытые обращения с высоким или критичным приоритетом.',
                    actionLabel: 'Открыть обращения',
                    actionUrl: Requests::getUrl(),
                );
            }
        }

        if ($isSuperAdmin || $isMarketAdmin || $isMarketManager) {
            $overdueTasks = $this->countOverdueTasks($marketId, $tz);

            if ($overdueTasks > 0) {
                $items[] = $this->makeItem(
                    title: 'Просроченные задачи',
                    value: (string) $overdueTasks,
                    tone: 'warning',
                    description: 'Открытые задачи, срок которых уже истёк.',
                    actionLabel: 'Открыть задачи',
                    actionUrl: TaskResource::getUrl('index'),
                );
            }
        }

        return $items;
    }

    /**
     * @return array{
     *   title:string,
     *   value:string,
     *   tone:string,
     *   description:string,
     *   action_label:string,
     *   action_url:string
     * }
     */
    private function makeItem(
        string $title,
        string $value,
        string $tone,
        string $description,
        string $actionLabel,
        string $actionUrl,
    ): array {
        return [
            'title' => $title,
            'value' => $value,
            'tone' => $tone,
            'description' => $description,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
        ];
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return (int) ($user->market_id ?: 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        if (filled($value)) {
            return (int) $value;
        }

        $fallback = Market::query()->orderBy('name')->value('id');

        return $fallback ? (int) $fallback : 0;
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private function countRecentIntegrationErrors(int $marketId, string $tz): int
    {
        $since = CarbonImmutable::now($tz)->subDays(7)->utc();

        return IntegrationExchange::query()
            ->where('market_id', $marketId)
            ->where('status', IntegrationExchange::STATUS_ERROR)
            ->where(function ($query) use ($since): void {
                $query->where('finished_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            })
            ->count();
    }

    private function countCriticalOpenRequests(int $marketId): int
    {
        return TenantRequest::query()
            ->where('market_id', $marketId)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereIn('priority', ['high', 'urgent'])
            ->count();
    }

    private function countOverdueTasks(int $marketId, string $tz): int
    {
        $nowUtc = CarbonImmutable::now($tz)->utc();

        return Task::query()
            ->where('market_id', $marketId)
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $nowUtc)
            ->count();
    }

    private function countOperationalContractsWithoutSpace(int $marketId): int
    {
        return TenantContractResource::applyOperationalContractsScope(
            TenantContract::query()->where('market_id', $marketId),
            true
        )
            ->whereNull('market_space_id')
            ->count();
    }

    private function countAccrualsWithoutContract(int $marketId): int
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
            return 0;
        }

        $lookbackMonths = TenantAccrualContractResolver::LOOKBACK_MONTHS;
        $sincePeriod = CarbonImmutable::now()->startOfMonth()->subMonths($lookbackMonths - 1)->toDateString();

        return (int) DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', '>=', $sincePeriod)
            ->whereNull('tenant_contract_id')
            ->count();
    }

    /**
     * @param array<string, mixed> $query
     */
    private function appendQueryString(string $baseUrl, array $query): string
    {
        $queryString = http_build_query(array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        if ($queryString === '') {
            return $baseUrl;
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . $queryString;
    }
}
