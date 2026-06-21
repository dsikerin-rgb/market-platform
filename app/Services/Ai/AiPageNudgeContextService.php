<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ContractDebt;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiPageNudgeContextService
{
    /**
     * @param array<string, mixed> $pageContext
     * @param array<string, mixed> $profile
     * @return array{topic:?string,message:?string,suggestions:list<string>,priority:string,profile_summary:?string}
     */
    public function build(User $user, int $marketId, array $pageContext, array $profile = []): array
    {
        $rejectedTopics = collect((array) ($profile['rejected_topics'] ?? []))
            ->pluck('key')
            ->filter()
            ->map(static fn (mixed $key): string => (string) $key)
            ->unique()
            ->all();

        $candidates = array_values(array_filter([
            $this->tasksCandidate($user, $marketId, $pageContext),
            $this->ticketsCandidate($user, $marketId, $pageContext),
            $this->debtsCandidate($marketId, $pageContext),
        ]));

        foreach ($this->sortCandidates($candidates, $pageContext) as $candidate) {
            if (! in_array((string) $candidate['topic'], $rejectedTopics, true)) {
                return [
                    'topic' => (string) $candidate['topic'],
                    'message' => (string) $candidate['message'],
                    'suggestions' => array_values((array) $candidate['suggestions']),
                    'priority' => (string) $candidate['priority'],
                    'profile_summary' => (string) ($profile['summary'] ?? '') ?: null,
                ];
            }
        }

        return [
            'topic' => null,
            'message' => null,
            'suggestions' => [],
            'priority' => 'neutral',
            'profile_summary' => (string) ($profile['summary'] ?? '') ?: null,
        ];
    }

    /**
     * @param array<string, mixed> $pageContext
     * @return array<string, mixed>|null
     */
    private function tasksCandidate(User $user, int $marketId, array $pageContext): ?array
    {
        if ($marketId <= 0 || ! Schema::hasTable('tasks')) {
            return null;
        }

        $isManager = $this->isSuperAdmin($user) || $this->hasRole($user, 'market-admin');
        $query = Task::query()->forMarket($marketId)->overdue();

        if (! $isManager) {
            $query->assignedTo($user);
        }

        $count = (clone $query)->count();
        if ($count <= 0) {
            return null;
        }

        return [
            'topic' => 'tasks',
            'priority' => 'warning',
            'weight' => $this->pathMatches($pageContext, '/admin/tasks') ? 95 : 70,
            'message' => $isManager
                ? "вижу {$count} просроченных задач по рынку. Могу помочь выбрать, что закрыть первым."
                : "вижу {$count} просроченных задач в вашей зоне ответственности. Могу помочь быстро выбрать, с чего начать.",
            'suggestions' => [
                'Покажи просроченные задачи',
                'Что самое срочное по задачам?',
                'Составь план действий на сегодня',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $pageContext
     * @return array<string, mixed>|null
     */
    private function ticketsCandidate(User $user, int $marketId, array $pageContext): ?array
    {
        if ($marketId <= 0 || ! Schema::hasTable('tickets')) {
            return null;
        }

        $query = Ticket::query()
            ->where('market_id', $marketId)
            ->whereNotIn('status', ['done', 'closed', 'resolved', 'cancelled', 'canceled']);

        app(TicketAccessService::class)->scopeVisibleTo($query, $user);

        $count = (clone $query)->count();
        if ($count <= 0) {
            return null;
        }

        return [
            'topic' => 'tickets',
            'priority' => 'info',
            'weight' => $this->pathMatches($pageContext, '/admin/requests') ? 90 : 60,
            'message' => "вижу {$count} открытых обращений. Могу помочь разобрать, где нужен ответ в первую очередь.",
            'suggestions' => [
                'Что требует ответа первым?',
                'Покажи новые обращения',
                'Создай задачи из обращений',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $pageContext
     * @return array<string, mixed>|null
     */
    private function debtsCandidate(int $marketId, array $pageContext): ?array
    {
        if (
            $marketId <= 0
            || ! Schema::hasTable('contract_debts')
            || ! Schema::hasColumn('contract_debts', 'market_id')
            || ! Schema::hasColumn('contract_debts', 'tenant_id')
            || ! Schema::hasColumn('contract_debts', 'debt_amount')
        ) {
            return null;
        }

        $row = DB::query()
            ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
            ->leftJoin('tenants as t', 't.id', '=', 'cd.tenant_id')
            ->where('cd.market_id', $marketId)
            ->where('cd.debt_amount', '>', 0)
            ->selectRaw("COALESCE(NULLIF(MAX(t.short_name), ''), NULLIF(MAX(t.name), ''), 'арендатор') as tenant_name")
            ->selectRaw('SUM(cd.debt_amount) as debt_amount')
            ->groupBy('cd.tenant_id')
            ->orderByDesc(DB::raw('SUM(cd.debt_amount)'))
            ->first();

        if (! $row || (float) ($row->debt_amount ?? 0) <= 0) {
            return null;
        }

        $tenantName = trim((string) ($row->tenant_name ?? 'арендатор')) ?: 'арендатор';
        $amount = number_format((float) $row->debt_amount, 0, ',', ' ');

        return [
            'topic' => 'debts',
            'priority' => 'warning',
            'weight' => $this->pathMatches($pageContext, '/admin/tenants') ? 85 : 65,
            'message' => "вижу крупную задолженность: {$tenantName}, {$amount} ₽. Могу показать детали или подготовить аккуратное сообщение.",
            'suggestions' => [
                'Покажи крупнейшие долги',
                'Кто требует внимания по оплате?',
                'Подготовь сообщение должнику',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $pageContext
     * @return list<array<string, mixed>>
     */
    private function sortCandidates(array $candidates, array $pageContext): array
    {
        usort($candidates, static function (array $left, array $right): int {
            return ((int) ($right['weight'] ?? 0)) <=> ((int) ($left['weight'] ?? 0));
        });

        return $candidates;
    }

    /**
     * @param array<string, mixed> $pageContext
     */
    private function pathMatches(array $pageContext, string $needle): bool
    {
        return str_contains((string) ($pageContext['path'] ?? ''), $needle);
    }

    private function isSuperAdmin(User $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    private function hasRole(User $user, string $role): bool
    {
        return method_exists($user, 'hasRole') && $user->hasRole($role);
    }
}
