<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Market;
use App\Models\MarketSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MarketSpaceMapShape extends Model
{
    protected $table = 'market_space_map_shapes';

    protected $fillable = [
        'market_id',
        'market_space_id',
        'page',
        'version',

        'polygon',
        'bbox_x1',
        'bbox_y1',
        'bbox_x2',
        'bbox_y2',

        'stroke_color',
        'fill_color',
        'fill_opacity',
        'stroke_width',

        'meta',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'market_space_id' => 'integer',
        'page' => 'integer',
        'version' => 'integer',

        'polygon' => 'array',
        'meta' => 'array',

        'bbox_x1' => 'float',
        'bbox_y1' => 'float',
        'bbox_x2' => 'float',
        'bbox_y2' => 'float',

        'fill_opacity' => 'float',
        'stroke_width' => 'float',

        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function marketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class);
    }

    protected static function booted(): void
    {
        static::saving(static function (self $shape): void {
            $poly = self::normalizePolygon($shape->polygon);

            // bbox пересчитываем только если геометрия валидная
            if (\count($poly) >= 3) {
                [$x1, $y1, $x2, $y2] = self::computeBbox($poly);

                // чуть стабилизируем хранение (как в роуте)
                $shape->bbox_x1 = \round($x1, 2);
                $shape->bbox_y1 = \round($y1, 2);
                $shape->bbox_x2 = \round($x2, 2);
                $shape->bbox_y2 = \round($y2, 2);
            }

            // нормализуем всегда, даже если < 3 точек (валидация — на API)
            $shape->polygon = $poly;
        });
    }

    /**
     * Нормализуем точки к формату:
     * [ ['x'=>float,'y'=>float], ... ]
     *
     * Принимает:
     * - array (из кастов)
     * - string JSON (если кто-то прислал строкой)
     */
    public static function normalizePolygon(mixed $polygon): array
    {
        if (\is_string($polygon)) {
            $decoded = \json_decode($polygon, true);
            $polygon = \is_array($decoded) ? $decoded : [];
        }

        if (! \is_array($polygon)) {
            return [];
        }

        $out = [];

        foreach ($polygon as $p) {
            if (\is_object($p)) {
                $p = \get_object_vars($p);
            }

            if (! \is_array($p)) {
                continue;
            }

            $x = $p['x'] ?? $p[0] ?? null;
            $y = $p['y'] ?? $p[1] ?? null;

            if ($x === null || $y === null) {
                continue;
            }

            if (! \is_numeric($x) || ! \is_numeric($y)) {
                continue;
            }

            $xf = (float) $x;
            $yf = (float) $y;

            if (! \is_finite($xf) || ! \is_finite($yf)) {
                continue;
            }

            $out[] = ['x' => $xf, 'y' => $yf];
        }

        return $out;
    }

    /**
     * bbox: minX, minY, maxX, maxY
     *
     * @param  array<int, array{x: float, y: float}>  $polygon
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function computeBbox(array $polygon): array
    {
        $minX = \INF;
        $minY = \INF;
        $maxX = -\INF;
        $maxY = -\INF;

        foreach ($polygon as $p) {
            $x = (float) ($p['x'] ?? 0.0);
            $y = (float) ($p['y'] ?? 0.0);

            if ($x < $minX) $minX = $x;
            if ($y < $minY) $minY = $y;
            if ($x > $maxX) $maxX = $x;
            if ($y > $maxY) $maxY = $y;
        }

        // если вдруг пришёл пустой массив
        if (! \is_finite($minX) || ! \is_finite($minY) || ! \is_finite($maxX) || ! \is_finite($maxY)) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        return [$minX, $minY, $maxX, $maxY];
    }
}
