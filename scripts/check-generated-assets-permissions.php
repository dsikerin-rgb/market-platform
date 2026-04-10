<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$normalizedProjectRoot = str_replace('\\', '/', $projectRoot);

if (PHP_OS_FAMILY !== 'Linux' || ! str_starts_with($normalizedProjectRoot, '/var/www/')) {
    echo "Skipping generated assets ownership check: local/non-server context.\n";
    exit(0);
}

$scopes = [
    'public/css/filament/filament',
    'public/fonts/filament/filament/inter',
    'public/js/filament',
    'public/js/saade/filament-fullcalendar',
    'public/marketplace/demo',
];

$issues = [];
$resolvePosixName = static function (string $function, int|false $id): string {
    if ($id === false) {
        return 'unknown';
    }

    if (! function_exists($function)) {
        return (string) $id;
    }

    $entry = $function($id);

    return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : (string) $id;
};

foreach ($scopes as $scope) {
    $directory = $projectRoot . DIRECTORY_SEPARATOR . $scope;

    if (! is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (! $item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        $ownerId = @fileowner($path);
        $groupId = @filegroup($path);
        $ownerName = $resolvePosixName('posix_getpwuid', $ownerId);
        $groupName = $resolvePosixName('posix_getgrgid', $groupId);
        $isRootOwned = $ownerId === 0;
        $isWritable = is_writable($path);

        if (! $isRootOwned && $isWritable) {
            continue;
        }

        $stat = @stat($path) ?: [];
        $mode = isset($stat['mode']) ? sprintf('%04o', $stat['mode'] & 0777) : '????';
        $reasons = [];

        if ($isRootOwned) {
            $reasons[] = 'root-owned';
        }

        if (! $isWritable) {
            $reasons[] = 'not-writable';
        }

        $issues[] = sprintf(
            '%s [%s] %s:%s %s',
            str_replace(['\\', '/'], '/', str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $path)),
            implode(', ', $reasons),
            $ownerName,
            $groupName,
            $mode
        );
    }
}

sort($issues, SORT_STRING);

if ($issues !== []) {
    fwrite(STDERR, "Generated assets ownership check failed:\n");

    foreach ($issues as $issue) {
        fwrite(STDERR, ' - ' . $issue . PHP_EOL);
    }

    exit(1);
}

echo "Generated assets ownership check passed.\n";
