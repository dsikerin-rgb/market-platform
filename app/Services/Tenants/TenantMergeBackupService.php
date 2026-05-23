<?php
# app/Services/Tenants/TenantMergeBackupService.php

declare(strict_types=1);

namespace App\Services\Tenants;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class TenantMergeBackupService
{
    /**
     * @return array{relative_path:string,absolute_path:string,size_bytes:int,database:string,created_at:string}
     */
    public function createBeforeTenantMerge(int $sourceTenantId, int $canonicalTenantId): array
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}", []);
        $driver = (string) ($connection['driver'] ?? '');

        if ($driver !== 'pgsql') {
            throw new RuntimeException('Backup не создан: слияние из UI разрешено только для PostgreSQL.');
        }

        $database = trim((string) ($connection['database'] ?? ''));
        $username = trim((string) ($connection['username'] ?? ''));
        $host = trim((string) ($connection['host'] ?? ''));
        $port = trim((string) ($connection['port'] ?? ''));
        $password = (string) ($connection['password'] ?? '');

        if ($database === '' || $username === '') {
            throw new RuntimeException('Backup не создан: не удалось определить database или username без секретов.');
        }

        $backupDirectory = storage_path('backups');
        File::ensureDirectoryExists($backupDirectory, 0775, true);

        $safeDatabase = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $database) ?: 'database';
        $timestamp = now()->format('Ymd_His');
        $fileName = sprintf(
            'tenant_merge_%s_%d_into_%d_%s.dump',
            $safeDatabase,
            $sourceTenantId,
            $canonicalTenantId,
            $timestamp,
        );
        $absolutePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;
        $relativePath = 'storage/backups/' . $fileName;

        $command = [
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file=' . $absolutePath,
        ];

        if ($host !== '') {
            $command[] = '--host=' . $host;
        }

        if ($port !== '') {
            $command[] = '--port=' . $port;
        }

        $command[] = '--username=' . $username;
        $command[] = $database;

        $process = new Process($command);
        $process->setTimeout(300);

        if ($password !== '') {
            $process->setEnv(['PGPASSWORD' => $password]);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }

            $error = trim($process->getErrorOutput());
            $message = $error !== ''
                ? 'Backup не создан: pg_dump завершился с ошибкой. ' . $this->safeProcessMessage($error)
                : 'Backup не создан: pg_dump завершился с ошибкой.';

            throw new RuntimeException($message);
        }

        clearstatcache(true, $absolutePath);
        $size = File::exists($absolutePath) ? (int) File::size($absolutePath) : 0;

        if ($size <= 0) {
            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }

            throw new RuntimeException('Backup не создан: файл pg_dump пустой. Слияние не запускалось.');
        }

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'size_bytes' => $size,
            'database' => $database,
            'created_at' => now()->toDateTimeString(),
        ];
    }

    private function safeProcessMessage(string $message): string
    {
        $message = preg_replace('/PGPASSWORD=\S+/u', 'PGPASSWORD=[hidden]', $message) ?? $message;
        $message = preg_replace('/password\s*=\s*\S+/iu', 'password=[hidden]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
