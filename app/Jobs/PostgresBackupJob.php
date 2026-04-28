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
        $result = $service->createBackup();

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'Не удалось создать бэкап.'));
        }

        if ($this->rotate) {
            $rotation = $service->rotateBackups($this->compressAfterDays, $this->deleteArchiveAfterDays);

            if (! ($rotation['success'] ?? false)) {
                throw new RuntimeException((string) ($rotation['error'] ?? 'Не удалось выполнить ротацию.'));
            }

        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Postgres backup job failed', [
            'message' => $e->getMessage(),
            'rotate' => $this->rotate,
            'compress_after_days' => $this->compressAfterDays,
            'delete_archive_after_days' => $this->deleteArchiveAfterDays,
        ]);
    }
}
