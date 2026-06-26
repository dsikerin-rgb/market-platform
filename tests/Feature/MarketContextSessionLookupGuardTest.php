<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class MarketContextSessionLookupGuardTest extends TestCase
{
    public function test_market_session_keys_are_only_read_through_market_context(): void
    {
        $violations = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            if ($this->isAllowedMarketContextSource($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($this->forbiddenSessionKeySnippets() as $snippet) {
                if (str_contains($source, $snippet)) {
                    $violations[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path) . ' contains ' . $snippet;
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private function isAllowedMarketContextSource(string $path): bool
    {
        return str_replace('\\', '/', $path) === str_replace('\\', '/', app_path('Support/MarketContext.php'));
    }

    /**
     * @return list<string>
     */
    private function forbiddenSessionKeySnippets(): array
    {
        return [
            "session('dashboard_market_id')",
            "session('selected_market_id')",
            "session('filament.admin.selected_market_id')",
            "session('filament.admin.market_id')",
            'session("filament_{$panelId}_market_id")',
            'session("filament.{$panelId}.selected_market_id")',
            'session("filament.{$panelId}.market_id")',
            "session()->put('dashboard_market_id'",
            "session()->put('selected_market_id'",
            "session()->put('filament.admin.selected_market_id'",
            "session()->put('filament.admin.market_id'",
        ];
    }
}
