<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PostgresBackupService;
use Illuminate\Console\Command;

class PostgresBackupCommand extends Command
{
    protected $signature = 'ops:postgres-backup
        {--rotate : Run rotation after creating the dump}
        {--compress-after-days= : Override compress threshold in days}
        {--delete-archive-after-days= : Override delete threshold in days}';

    protected $description = 'Create a PostgreSQL backup and optionally rotate old backups';

    public function handle(PostgresBackupService $service): int
    {
        $settings = $service->getSettings();
        $result = $service->createBackup();

        if (! ($result['success'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'Не удалось создать бэкап.'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup created: %s (%s)',
            (string) $result['fileName'],
            (string) $result['sizeHuman']
        ));

        if (! (bool) $this->option('rotate')) {
            return self::SUCCESS;
        }

        $compressAfterDays = is_numeric($this->option('compress-after-days'))
            ? (int) $this->option('compress-after-days')
            : (int) $settings['compress_after_days'];

        $deleteArchiveAfterDays = is_numeric($this->option('delete-archive-after-days'))
            ? (int) $this->option('delete-archive-after-days')
            : (int) $settings['delete_archive_after_days'];

        $rotation = $service->rotateBackups($compressAfterDays, $deleteArchiveAfterDays);

        if (! ($rotation['success'] ?? false)) {
            $this->error((string) ($rotation['error'] ?? 'Не удалось выполнить ротацию.'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Rotation done: compressed=%d, deleted_duplicates=%d, deleted_archives=%d',
            (int) ($rotation['compressed'] ?? 0),
            (int) ($rotation['deletedDuplicates'] ?? 0),
            (int) ($rotation['deletedArchives'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
