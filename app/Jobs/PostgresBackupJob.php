<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PostgresBackupService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PostgresBackupJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public bool $rotate = false,
        public int $compressAfterDays = 2,
        public int $deleteArchiveAfterDays = 60,
    ) {
    }

    public static function queueBackup(
        bool $rotate = false,
        int $compressAfterDays = 2,
        int $deleteArchiveAfterDays = 60,
    ): void {
        $job = new self($rotate, $compressAfterDays, $deleteArchiveAfterDays);

        try {
            Queue::connection('redis')->pushOn('backups', $job);
        } catch (Throwable $e) {
            if (! app()->environment('local')) {
                throw $e;
            }

            $job->handle(app(PostgresBackupService::class));
        }
    }

    public function handle(PostgresBackupService $service): void
    {
        Log::channel('backups')->info('Postgres backup started', [
            'rotate' => $this->rotate,
            'compress_after_days' => $this->compressAfterDays,
            'delete_archive_after_days' => $this->deleteArchiveAfterDays,
        ]);

        $result = $service->createBackup();

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'Не удалось создать бэкап.'));
        }

        $rotation = null;

        if ($this->rotate) {
            $rotation = $service->rotateBackups($this->compressAfterDays, $this->deleteArchiveAfterDays);

            if (! ($rotation['success'] ?? false)) {
                throw new RuntimeException((string) ($rotation['error'] ?? 'Не удалось выполнить ротацию.'));
            }
        }

        Log::channel('backups')->info('Postgres backup finished', [
            'file_name' => $result['fileName'] ?? null,
            'size_human' => $result['sizeHuman'] ?? null,
            'rotate' => $this->rotate,
            'compress_after_days' => $this->compressAfterDays,
            'delete_archive_after_days' => $this->deleteArchiveAfterDays,
            'rotation' => is_array($rotation) ? [
                'compressed' => $rotation['compressed'] ?? 0,
                'deleted_duplicates' => $rotation['deletedDuplicates'] ?? 0,
                'deleted_archives' => $rotation['deletedArchives'] ?? 0,
            ] : null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('backups')->error('Postgres backup failed', [
            'message' => $e->getMessage(),
            'rotate' => $this->rotate,
            'compress_after_days' => $this->compressAfterDays,
            'delete_archive_after_days' => $this->deleteArchiveAfterDays,
        ]);
    }
}
