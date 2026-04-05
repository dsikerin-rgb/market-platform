<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiContextPackBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiContextPackShow extends Command
{
    protected $signature = 'ai:context-pack:show {market_space_id} {--market_id= : Market ID (auto-detected if omitted)}';

    protected $description = 'Show AI context pack for one market_space_id (read-only, no AI call)';

    public function handle(AiContextPackBuilder $builder): int
    {
        $spaceId = (int) $this->argument('market_space_id');
        $marketId = $this->option('market_id')
            ? (int) $this->option('market_id')
            : $this->autoDetectMarketId($spaceId);

        if ($marketId === null) {
            $this->error("Market ID не найден для market_space_id={$spaceId}");
            return Command::FAILURE;
        }

        $this->info("Собираю AI context pack: market_space_id={$spaceId}, market_id={$marketId}");
        $this->newLine();

        $pack = $builder->build($spaceId, $marketId);

        if (isset($pack['error'])) {
            $this->error("Ошибка: {$pack['reason']}");
            if (isset($pack['detail'])) {
                $this->error("Детали: " . json_encode($pack['detail']));
            }
            return Command::FAILURE;
        }

        // Summary header
        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║        AI Context Pack — Market Space #{$spaceId}        ║");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();

        $this->line("  <fg=cyan>map_review_status:</> {$pack['map_review_status']}");
        $this->line("  <fg=cyan>space number:</>     {$pack['space_snapshot']['number']}");
        $this->line("  <fg=cyan>display_name:</>     {$pack['space_snapshot']['display_name']}");
        $this->line("  <fg=cyan>status:</>           {$pack['space_snapshot']['status']}");

        if ($pack['tenant_context']['has_tenant']) {
            $t = $pack['tenant_context']['tenant'];
            $this->line("  <fg=cyan>tenant:</>           {$t['display_name']}");
            $this->line("  <fg=cyan>contracts:</>        " . count($pack['tenant_context']['contracts']));
        } else {
            $this->line("  <fg=cyan>tenant:</>           нет");
        }

        $d = $pack['debt_context'];
        $this->line("  <fg=cyan>debt_status:</>      {$d['debt_status']} ({$d['debt_label']})");
        $this->line("  <fg=cyan>debt_scope:</>       {$d['debt_scope']}");
        $this->line("  <fg=cyan>total_debt:</>       {$d['total_debt']}");
        $this->line("  <fg=cyan>overdue_days:</>     {$d['overdue_days']}");
        $this->line("  <fg=cyan>source_marker:</>    {$d['source_marker']}");

        $this->line("  <fg=cyan>review_history:</>   " . count($pack['review_history']) . " записей");
        $this->line("  <fg=cyan>decision_options:</> " . count($pack['decision_options']['relevant_decisions']) . " релевантных");

        $this->newLine();
        $this->info("────────────────── JSON ──────────────────");
        $this->newLine();

        $this->output->writeln(
            json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $this->newLine();
        $this->info("Context pack ready.");

        return Command::SUCCESS;
    }

    private function autoDetectMarketId(int $spaceId): ?int
    {
        return DB::table('market_spaces')
            ->where('id', $spaceId)
            ->value('market_id');
    }
}
