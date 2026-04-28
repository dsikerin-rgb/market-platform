<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class PostgresBackupService
{
    private const SETTINGS_KEY = 'ops_diagnostics.postgres_backup';

    public function createBackup(): array
    {
        $backupDir = storage_path('app/backups');
        File::ensureDirectoryExists($backupDir);

        $database = config('database.connections.pgsql');
        $dbHost = (string) ($database['host'] ?? 'localhost');
        $dbPort = (int) ($database['port'] ?? 5432);
        $dbName = (string) ($database['database'] ?? '');
        $dbUser = (string) ($database['username'] ?? '');
        $dbPassword = (string) ($database['password'] ?? '');

        if ($dbName === '') {
            return [
                'success' => false,
                'error' => 'Параметр database.connections.pgsql.database пуст.',
            ];
        }

        $env = (string) config('app.env');
        $timestamp = now()->format('Ymd_His');
        $fileName = "database_{$env}_{$timestamp}.sql";
        $targetPath = $backupDir . DIRECTORY_SEPARATOR . $fileName;
        $pgDumpBinary = $this->resolvePgDumpBinary();

        try {
            $result = Process::timeout(1800)
                ->env([
                    'PGPASSWORD' => $dbPassword,
                ])
                ->run([
                    $pgDumpBinary,
                    '-h',
                    $dbHost,
                    '-p',
                    (string) $dbPort,
                    '-U',
                    $dbUser,
                    '-F',
                    'p',
                    '-f',
                    $targetPath,
                    $dbName,
                ]);
        } catch (Throwable $e) {
            File::delete($targetPath);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (! $result->successful() || ! is_file($targetPath) || filesize($targetPath) <= 0) {
            File::delete($targetPath);

            return [
                'success' => false,
                'error' => trim((string) $result->errorOutput()) ?: 'pg_dump завершился с ошибкой.',
            ];
        }

        $size = File::size($targetPath);

        return [
            'success' => true,
            'fileName' => $fileName,
            'targetPath' => $targetPath,
            'size' => $size,
            'sizeHuman' => $this->formatBytes($size),
        ];
    }

    public function rotateBackups(int $compressAfterDays, int $deleteArchiveAfterDays): array
    {
        $backupDir = storage_path('app/backups');

        if (! is_dir($backupDir)) {
            return [
                'success' => false,
                'error' => "Отсутствует директория: {$backupDir}",
            ];
        }

        $compressAfterDays = max(0, $compressAfterDays);
        $deleteArchiveAfterDays = max(0, $deleteArchiveAfterDays);

        $candidates = $this->getRotationCandidates($compressAfterDays, $deleteArchiveAfterDays);

        $compressed = 0;
        $deletedDuplicates = 0;
        $deletedArchives = 0;

        try {
            foreach ($candidates['compress'] as $sqlPath) {
                $gzPath = $sqlPath . '.gz';

                if (is_file($gzPath)) {
                    continue;
                }

                if ($this->gzipFile($sqlPath, $gzPath)) {
                    $compressed++;
                }
            }

            foreach ($candidates['deleteDuplicates'] as $duplicatePath) {
                if (File::delete($duplicatePath)) {
                    $deletedDuplicates++;
                }
            }

            foreach ($candidates['deleteArchives'] as $archivePath) {
                if (File::delete($archivePath)) {
                    $deletedArchives++;
                }
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'compressed' => $compressed,
            'deletedDuplicates' => $deletedDuplicates,
            'deletedArchives' => $deletedArchives,
        ];
    }

    private function resolvePgDumpBinary(): string
    {
        $configuredPath = trim((string) $this->getSettings()['dump_binary']);
        if ($configuredPath !== '' && is_file($configuredPath)) {
            return $configuredPath;
        }

        $laragonPgDump = dirname(base_path(), 2) . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR
            . 'postgresql' . DIRECTORY_SEPARATOR
            . 'postgresql' . DIRECTORY_SEPARATOR
            . 'pgsql' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'pg_dump.exe' : 'pg_dump');

        if (is_file($laragonPgDump)) {
            return $laragonPgDump;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'pg_dump.exe' : 'pg_dump';
    }

    /**
     * @return array{compress:array<int,string>,deleteDuplicates:array<int,string>,deleteArchives:array<int,string>}
     */
    private function getRotationCandidates(int $compressAfterDays, int $deleteArchiveAfterDays): array
    {
        $backupDir = storage_path('app/backups');

        if (! is_dir($backupDir)) {
            return [
                'compress' => [],
                'deleteDuplicates' => [],
                'deleteArchives' => [],
            ];
        }

        $compressBefore = now()->subDays($compressAfterDays);
        $deleteArchiveBefore = now()->subDays($deleteArchiveAfterDays);

        $sqlFiles = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $gzFiles = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [];

        $gzBases = [];

        foreach ($gzFiles as $gzPath) {
            $base = basename($gzPath, '.sql.gz');
            $gzBases[$base] = $gzPath;
        }

        $compress = [];
        $deleteDuplicates = [];

        foreach ($sqlFiles as $sqlPath) {
            $base = basename($sqlPath, '.sql');

            if (isset($gzBases[$base])) {
                $deleteDuplicates[] = $sqlPath;
                continue;
            }

            $mtime = filemtime($sqlPath);
            if ($mtime !== false && Carbon::createFromTimestamp($mtime)->lessThan($compressBefore)) {
                $compress[] = $sqlPath;
            }
        }

        $deleteArchives = [];

        foreach ($gzFiles as $gzPath) {
            $mtime = filemtime($gzPath);
            if ($mtime !== false && Carbon::createFromTimestamp($mtime)->lessThan($deleteArchiveBefore)) {
                $deleteArchives[] = $gzPath;
            }
        }

        return [
            'compress' => $compress,
            'deleteDuplicates' => $deleteDuplicates,
            'deleteArchives' => $deleteArchives,
        ];
    }

    private function gzipFile(string $sourcePath, string $targetPath): bool
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            return false;
        }

        $target = gzopen($targetPath, 'wb9');
        if ($target === false) {
            fclose($source);

            return false;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        gzclose($target);

        if (! is_file($targetPath) || filesize($targetPath) <= 0) {
            File::delete($targetPath);

            return false;
        }

        return true;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%s %s', rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.'), $units[$unit]);
    }

    /**
     * @return array{
     *   dump_binary:string,
     *   compress_after_days:int,
     *   delete_archive_after_days:int
     * }
     */
    public function getSettings(): array
    {
        $defaults = [
            'dump_binary' => (string) config('database.connections.pgsql.dump_binary', ''),
            'compress_after_days' => 2,
            'delete_archive_after_days' => 60,
        ];

        $stored = (array) (SystemSetting::query()->where('key', self::SETTINGS_KEY)->first()?->value ?? []);

        return [
            'dump_binary' => trim((string) ($stored['dump_binary'] ?? '')) ?: $defaults['dump_binary'],
            'compress_after_days' => max(0, (int) ($stored['compress_after_days'] ?? $defaults['compress_after_days'])),
            'delete_archive_after_days' => max(0, (int) ($stored['delete_archive_after_days'] ?? $defaults['delete_archive_after_days'])),
        ];
    }

    /**
     * @param array{
     *   dump_binary?:string,
     *   compress_after_days?:int|string,
     *   delete_archive_after_days?:int|string
     * } $data
     */
    public function saveSettings(array $data): array
    {
        $settings = [
            'dump_binary' => trim((string) ($data['dump_binary'] ?? '')),
            'compress_after_days' => max(0, (int) ($data['compress_after_days'] ?? 2)),
            'delete_archive_after_days' => max(0, (int) ($data['delete_archive_after_days'] ?? 60)),
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $settings],
        );

        return $settings;
    }
}
