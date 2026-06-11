<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Debt\DebtDecisionPolicy;
use App\Services\Debt\DebtDecisionPreviewReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DraftDebtDecisionsCommand extends Command
{
    protected $signature = 'debt:draft-decisions
        {--market=1 : Market ID}
        {--account=62 : Settlement account to inspect}
        {--limit=50 : Maximum sample rows}
        {--status= : Filter by current map status}
        {--mismatches : Return only rows where current map status differs from the OSV candidate}
        {--aging-policy=invoice-day : OSV aging policy: invoice-day, period-start, or settlement-document}
        {--json : Output raw JSON only}';

    protected $description = 'Build a read-only draft comparison between current map debt colors and 1C settlement balances.';

    public function handle(DebtDecisionPreviewReport $report): int
    {
        $marketId = max(1, (int) $this->option('market'));
        $account = trim((string) $this->option('account'));
        $limit = max(1, (int) $this->option('limit'));
        $statusFilter = trim((string) ($this->option('status') ?? ''));
        $onlyMismatches = (bool) $this->option('mismatches');
        $agingPolicy = trim((string) $this->option('aging-policy'));

        if (! in_array($agingPolicy, [
            DebtDecisionPolicy::AGING_INVOICE_DAY,
            DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
            DebtDecisionPolicy::AGING_PERIOD_START,
        ], true)) {
            $this->line(json_encode([
                'error' => 'unsupported aging policy',
                'supported' => [
                    DebtDecisionPolicy::AGING_INVOICE_DAY,
                    DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
                    DebtDecisionPolicy::AGING_PERIOD_START,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        if (! Schema::hasTable('tenant_settlement_balances')) {
            $this->line(json_encode([
                'error' => 'tenant_settlement_balances table is missing',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $result = $report->build(
            marketId: $marketId,
            account: $account,
            agingPolicy: $agingPolicy,
            currentStatusFilter: $statusFilter !== '' ? $statusFilter : null,
            onlyMismatches: $onlyMismatches,
        );

        $payload = [
            'summary' => $result['summary'],
            'samples' => array_slice($result['rows'], 0, $limit),
        ];

        if (! (bool) $this->option('json')) {
            $this->info('Read-only draft. No database rows were changed.');
        }

        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

}
