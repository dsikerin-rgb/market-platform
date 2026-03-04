<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditNotifications extends Command
{
    protected $signature = 'notifications:audit
        {--hours=24 : Window size in hours}
        {--market= : Filter by market_id}
        {--limit=10 : Top rows limit for grouped tables}';

    protected $description = 'Show notification delivery audit by channels, statuses and types.';

    public function handle(): int
    {
        if (! Schema::hasTable('notification_deliveries')) {
            $this->error('Table notification_deliveries is missing. Run migrations first.');

            return Command::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $marketId = $this->option('market');
        $marketId = is_numeric($marketId) ? (int) $marketId : null;

        $to = now();
        $from = (clone $to)->subHours($hours);

        $base = NotificationDelivery::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);
        if ($marketId !== null) {
            $base->where('market_id', $marketId);
        }

        $total = (clone $base)->count();
        $sent = (clone $base)->where('status', NotificationDelivery::STATUS_SENT)->count();
        $failed = (clone $base)->where('status', NotificationDelivery::STATUS_FAILED)->count();

        $this->line('--- Notifications Audit ---');
        $this->line('Window: last ' . $hours . 'h');
        $this->line('Range: ' . $from->toDateTimeString() . ' .. ' . $to->toDateTimeString());
        $this->line('Scope: ' . ($marketId !== null ? "market_id={$marketId}" : 'all markets'));
        $this->line("Total: {$total} | sent: {$sent} | failed: {$failed}");
        $this->newLine();

        $channelRows = (clone $base)
            ->selectRaw('channel, status, COUNT(*) as cnt')
            ->groupBy('channel', 'status')
            ->orderByDesc('cnt')
            ->get();

        $this->line('By channel/status:');
        $this->table(
            ['channel', 'status', 'count'],
            $channelRows->map(static fn ($row): array => [
                (string) $row->channel,
                (string) $row->status,
                (string) $row->cnt,
            ])->all()
        );

        $typeRows = (clone $base)
            ->selectRaw('notification_type, status, COUNT(*) as cnt')
            ->groupBy('notification_type', 'status')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        $this->line('Top notification types:');
        $this->table(
            ['notification_type', 'status', 'count'],
            $typeRows->map(static fn ($row): array => [
                (string) $row->notification_type,
                (string) $row->status,
                (string) $row->cnt,
            ])->all()
        );

        $errorRows = (clone $base)
            ->where('status', NotificationDelivery::STATUS_FAILED)
            ->selectRaw('COALESCE(error, \'unknown\') as error_text, COUNT(*) as cnt')
            ->groupBy('error_text')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        $this->line('Top failure reasons:');
        $this->table(
            ['count', 'error'],
            $errorRows->map(static fn ($row): array => [
                (string) $row->cnt,
                (string) $row->error_text,
            ])->all()
        );

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>=', $from)
                ->where('failed_at', '<=', $to)
                ->count();

            $this->line("Queue failed jobs in same window: {$failedJobs}");
        }

        return Command::SUCCESS;
    }
}
