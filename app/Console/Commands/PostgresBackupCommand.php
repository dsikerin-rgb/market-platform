<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostgresBackupJob;
use App\Services\PostgresBackupService;
use Illuminate\Console\Command;
use Throwable;

class PostgresBackupCommand extends Command
{
    protected $signature = 'ops:postgres-backup
        {--rotate : Run rotation after creating the dump}
        {--compress-after-days= : Override compress threshold in days}
        {--delete-archive-after-days= : Override delete threshold in days}';

    protected $description = 'Queue a PostgreSQL backup and optionally rotate old backups';

    public function handle(PostgresBackupService $service): int
    {
        $settings = $service->getSettings();

        $compressAfterDays = is_numeric($this->option('compress-after-days'))
            ? (int) $this->option('compress-after-days')
            : (int) $settings['compress_after_days'];

        $deleteArchiveAfterDays = is_numeric($this->option('delete-archive-after-days'))
            ? (int) $this->option('delete-archive-after-days')
            : (int) $settings['delete_archive_after_days'];

        try {
            PostgresBackupJob::queueBackup(
                (bool) $this->option('rotate'),
                $compressAfterDays,
                $deleteArchiveAfterDays
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Бэкап поставлен в очередь: queue=backups, rotate=%s, compress_after_days=%d, delete_archive_after_days=%d',
            (bool) $this->option('rotate') ? 'yes' : 'no',
            $compressAfterDays,
            $deleteArchiveAfterDays
        ));

        return self::SUCCESS;
    }
}
