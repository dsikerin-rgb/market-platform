<?php

declare(strict_types=1);

/**
 * Guardrail for accidental mojibake and broken UTF-8 in source files.
 *
 * Usage:
 *   php scripts/check-encoding.php
 *   php scripts/check-encoding.php path/to/dir
 */

$roots = $argv;
array_shift($roots);
if ($roots === []) {
    $roots = ['app', 'resources', 'routes', 'config', 'database', 'tests'];
}

$excludePrefixes = [
    'vendor/',
    'node_modules/',
    'storage/',
    'bootstrap/cache/',
    'public/build/',
    '.git/',
];

$extensions = [
    'php',
    'blade.php',
    'js',
    'css',
    'json',
    'yml',
    'yaml',
    'md',
];

$issues = [];

$isTextFile = static function (string $path) use ($extensions): bool {
    foreach ($extensions as $ext) {
        if (str_ends_with($path, '.' . $ext)) {
            return true;
        }
    }

    return false;
};

$isExcluded = static function (string $path) use ($excludePrefixes): bool {
    $normalized = str_replace('\\', '/', $path);
    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($normalized, $prefix)) {
            return true;
        }
    }

    return false;
};

// Typical mojibake fragments seen when UTF-8 text is read as CP1251/ANSI.
$mojibakePatterns = [
    '/Р[ЂЃ‚ѓ„…†‡€‰Љ‹ЊЌЋЏђѓєѕіїјљњћџ]/u',
    '/С[ЂЃ‚ѓ„…†‡€‰Љ‹ЊЌЋЏђѓєѕіїјљњћџ]/u',
    '/вЂ[^\s]/u',
];

foreach ($roots as $root) {
    if (!is_dir($root) && !is_file($root)) {
        $issues[] = sprintf('[MISSING] %s', $root);
        continue;
    }

    if (is_file($root)) {
        $paths = [$root];
    } else {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        $paths = [];
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $paths[] = $item->getPathname();
        }
    }

    foreach ($paths as $path) {
        $relative = str_replace('\\', '/', ltrim(str_replace(getcwd(), '', $path), '\\/'));
        if ($isExcluded($relative) || !$isTextFile($relative)) {
            continue;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            $issues[] = sprintf('[READ] %s', $relative);
            continue;
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $issues[] = sprintf('[UTF8] %s', $relative);
            continue;
        }

        $lines = preg_split('/\R/u', $contents) ?: [];
        foreach ($lines as $i => $line) {
            foreach ($mojibakePatterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $issues[] = sprintf('[MOJIBAKE] %s:%d', $relative, $i + 1);
                    continue 3;
                }
            }
        }
    }
}

if ($issues !== []) {
    fwrite(STDERR, "Encoding check failed:\n");
    foreach ($issues as $issue) {
        fwrite(STDERR, ' - ' . $issue . PHP_EOL);
    }
    exit(1);
}

echo "Encoding check passed.\n";

