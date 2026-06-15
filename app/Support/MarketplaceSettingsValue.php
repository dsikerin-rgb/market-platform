<?php

declare(strict_types=1);

namespace App\Support;

use Stringable;

final class MarketplaceSettingsValue
{
    public static function string(mixed $value, mixed $fallback = ''): string
    {
        $normalized = self::firstStringableValue($value)
            ?? self::firstStringableValue($fallback)
            ?? '';

        return trim((string) $normalized);
    }

    public static function nullablePath(mixed $value): ?string
    {
        $path = self::string($value);

        return $path !== '' ? $path : null;
    }

    private static function firstStringableValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value) || $value instanceof Stringable) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $normalized = self::firstStringableValue($item);

            if ($normalized !== null && trim((string) $normalized) !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
