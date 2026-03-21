<?php

declare(strict_types=1);

$path = __DIR__ . '/resources/views/filament/pages/requests.blade.php';
$content = file_get_contents($path);

if ($content === false) {
    fwrite(STDERR, "Failed to read {$path}\n");
    exit(1);
}

$replacements = [
    '~\s*<section class="requests-hero">.*?</section>\s*<section class="requests-header">~s' => "\r\n\r\n        <section class=\"requests-header\">",
    '~\R\s*// Legacy hero/section fragments still reference these counters\..*?\R\s*\$commentedCount = .*?;~s' => '',
    '~\R\s*--requests-hero-text:.*?;~' => '',
    '~\R\s*--requests-hero-muted:.*?;~' => '',
    '~\R\s*\.requests-hero\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.dark \.requests-hero\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-row\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-main\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-title\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-icon\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.dark \.requests-hero-icon\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-copy h2\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*\.requests-hero-copy p\s*\{.*?\R\s*\}~s' => '',
    '~\R\s*<x-slot name="description">.*?</x-slot>~s' => '',
];

foreach ($replacements as $pattern => $replacement) {
    $updated = preg_replace($pattern, $replacement, $content, -1, $count);

    if ($updated === null) {
        fwrite(STDERR, "Regex failed: {$pattern}\n");
        exit(1);
    }

    $content = $updated;
}

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Failed to write {$path}\n");
    exit(1);
}

fwrite(STDOUT, "Updated requests.blade.php\n");
