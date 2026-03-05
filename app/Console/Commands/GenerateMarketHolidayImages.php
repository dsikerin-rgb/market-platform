<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateMarketHolidayImages extends Command
{
    protected $signature = 'market:holidays:generate-images
        {--market= : Market id or slug}
        {--from= : Start date (Y-m-d), defaults to today}
        {--to= : End date (Y-m-d), defaults to +1 year}
        {--overwrite : Regenerate even if cover_image is already set}';

    protected $description = 'Generate branded event cover images for market holidays and promotions';

    public function handle(): int
    {
        $markets = $this->resolveMarkets();

        if ($markets->isEmpty()) {
            $this->warn('No active markets found.');

            return self::SUCCESS;
        }

        $from = $this->resolveDate((string) $this->option('from')) ?? now()->startOfDay();
        $to = $this->resolveDate((string) $this->option('to')) ?? now()->addYear()->endOfDay();
        $overwrite = (bool) $this->option('overwrite');

        $generated = 0;
        $skipped = 0;

        foreach ($markets as $market) {
            $this->line(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            $holidays = MarketHoliday::query()
                ->where('market_id', (int) $market->id)
                ->whereDate('starts_at', '>=', $from->toDateString())
                ->whereDate('starts_at', '<=', $to->toDateString())
                ->orderBy('starts_at')
                ->get(['id', 'market_id', 'title', 'starts_at', 'ends_at', 'source', 'cover_image']);

            foreach ($holidays as $holiday) {
                if (! $overwrite && filled($holiday->cover_image)) {
                    $skipped++;
                    continue;
                }

                $fileName = sprintf(
                    'market-holidays/generated/m%d-h%d-%s.svg',
                    (int) $holiday->market_id,
                    (int) $holiday->id,
                    Str::slug((string) $holiday->title) ?: 'event'
                );

                $svg = $this->buildSvg(
                    title: (string) $holiday->title,
                    marketName: (string) $market->name,
                    startDate: optional($holiday->starts_at)->format('d.m.Y') ?: '',
                    endDate: optional($holiday->ends_at)->format('d.m.Y'),
                    source: (string) ($holiday->source ?? ''),
                );

                Storage::disk('public')->put($fileName, $svg);

                $holiday->forceFill(['cover_image' => $fileName])->save();
                $generated++;
            }
        }

        $this->info(sprintf('Done. generated=%d skipped=%d', $generated, $skipped));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Market>
     */
    private function resolveMarkets(): Collection
    {
        $raw = trim((string) $this->option('market'));

        if ($raw === '') {
            return Market::query()->where('is_active', true)->orderBy('id')->get();
        }

        $query = Market::query()->where('is_active', true);

        if (is_numeric($raw)) {
            $query->whereKey((int) $raw);
        } else {
            $query->where('slug', $raw);
        }

        return $query->get();
    }

    private function resolveDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildSvg(
        string $title,
        string $marketName,
        string $startDate,
        ?string $endDate,
        string $source
    ): string {
        [$fromColor, $toColor, $accentColor] = $this->paletteForSource($source);
        $title = $this->escapeXml($title);
        $marketName = $this->escapeXml($marketName);
        $dateLabel = $this->escapeXml($startDate . ($endDate ? (' - ' . $endDate) : ''));

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900" viewBox="0 0 1600 900">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$fromColor}" />
      <stop offset="100%" stop-color="{$toColor}" />
    </linearGradient>
  </defs>
  <rect width="1600" height="900" fill="url(#bg)" />
  <circle cx="1320" cy="210" r="220" fill="{$accentColor}" fill-opacity="0.22" />
  <circle cx="250" cy="780" r="260" fill="#ffffff" fill-opacity="0.10" />
  <rect x="90" y="90" width="1420" height="720" rx="28" fill="#0f172a" fill-opacity="0.18" />
  <text x="140" y="190" fill="#e2e8f0" font-size="36" font-family="Inter, Arial, sans-serif" letter-spacing="2">СОБЫТИЕ РЫНКА</text>
  <text x="140" y="285" fill="#ffffff" font-size="78" font-weight="700" font-family="Inter, Arial, sans-serif">{$title}</text>
  <text x="140" y="375" fill="#dbeafe" font-size="36" font-family="Inter, Arial, sans-serif">{$marketName}</text>
  <rect x="140" y="460" width="460" height="94" rx="18" fill="#ffffff" fill-opacity="0.14" stroke="#ffffff" stroke-opacity="0.32" />
  <text x="172" y="520" fill="#ffffff" font-size="38" font-family="Inter, Arial, sans-serif">{$dateLabel}</text>
</svg>
SVG;
    }

    /**
     * @return array{string,string,string}
     */
    private function paletteForSource(string $source): array
    {
        $source = Str::lower(trim($source));

        return match (true) {
            Str::contains($source, 'promo') || Str::contains($source, 'promotion') => ['#ec4899', '#f97316', '#fde047'],
            Str::contains($source, 'sanitary') => ['#0ea5e9', '#6366f1', '#22d3ee'],
            Str::contains($source, 'holiday') => ['#0f766e', '#06b6d4', '#34d399'],
            default => ['#0284c7', '#22c55e', '#67e8f9'],
        };
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

