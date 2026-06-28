<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceSlide;
use App\Models\TenantShowcase;
use App\Models\TenantSpaceShowcase;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

class MarketplaceBackfillPreviewsCommand extends Command
{
    protected $signature = 'marketplace:backfill-previews
        {--chunk=200 : Number of records to process per chunk}
        {--force : Regenerate previews even if they already exist}
        {--model=* : Limit backfill to specific model keys: products, holidays, announcements, slides, showcases, space-showcases}
        {--dry-run : Run in dry-run mode}
        {--execute : Generate preview files (default: dry-run)}';

    protected $description = 'Generate missing preview images for existing marketplace media files';

    /**
     * @var list<string>
     */
    private array $allowedModels = [
        'products',
        'holidays',
        'announcements',
        'slides',
        'showcases',
        'space-showcases',
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');
        $requestedModels = collect((array) $this->option('model'))
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        if ($requestedModels !== []) {
            $unknown = array_values(array_diff($requestedModels, $this->allowedModels));
            if ($unknown !== []) {
                $this->error('Unknown model keys: '.implode(', ', $unknown));

                return self::FAILURE;
            }
        } else {
            $requestedModels = $this->allowedModels;
        }

        $seen = [];
        $checked = 0;
        $generated = 0;

        foreach ($requestedModels as $modelKey) {
            $this->line("Backfilling {$modelKey}...");

            match ($modelKey) {
                'products' => MarketplaceProduct::query()->select(['id', 'images'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths((array) ($record->images ?? []), $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                'holidays' => MarketHoliday::query()->select(['id', 'cover_image'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths([$record->cover_image], $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                'announcements' => MarketplaceAnnouncement::query()->select(['id', 'cover_image'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths([$record->cover_image], $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                'slides' => MarketplaceSlide::query()->select(['id', 'image_path'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths([$record->image_path], $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                'showcases' => TenantShowcase::query()->select(['id', 'photos'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths((array) ($record->photos ?? []), $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                'space-showcases' => TenantSpaceShowcase::query()->select(['id', 'photos'])->chunkById($chunkSize, function (EloquentCollection $records) use (&$seen, &$checked, &$generated, $force, $dryRun): void {
                    foreach ($records as $record) {
                        $this->backfillPaths((array) ($record->photos ?? []), $seen, $checked, $generated, $force, $dryRun);
                    }
                }),
                default => null,
            };
        }

        if ($dryRun) {
            $this->info("Checked {$checked} unique media paths. Would attempt to generate {$generated} previews.");
            $this->warn('DRY RUN: no preview files were generated. Use --execute to apply.');

            return self::SUCCESS;
        }

        $this->info("Checked {$checked} unique media paths. Generated {$generated} previews.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $paths
     * @param  array<string, true>  $seen
     */
    private function backfillPaths(array $paths, array &$seen, int &$checked, int &$generated, bool $force, bool $dryRun): void
    {
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $checked++;

            $hadPreview = MarketplaceMediaStorage::hasPreview($value);
            if ($dryRun) {
                if (($force || ! $hadPreview) && $this->canAttemptPreview($value)) {
                    $generated++;
                }

                continue;
            }

            if (MarketplaceMediaStorage::ensurePreview($value, $force) && ($force || ! $hadPreview)) {
                $generated++;
            }
        }
    }

    private function canAttemptPreview(string $path): bool
    {
        return ! Str::startsWith($path, ['http://', 'https://', 'data:', '/']);
    }
}
