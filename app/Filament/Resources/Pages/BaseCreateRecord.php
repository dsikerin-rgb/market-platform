<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

abstract class BaseCreateRecord extends CreateRecord
{
    public static bool $formActionsAreSticky = true;

    public function getTitle(): string|Htmlable
    {
        return $this->resolveShortCreateLabel(parent::getTitle());
    }

    public function getHeading(): string|Htmlable
    {
        return $this->resolveShortCreateLabel(parent::getHeading());
    }

    public function getBreadcrumb(): string
    {
        return (string) $this->resolveShortCreateLabel(parent::getBreadcrumb());
    }

    private function resolveShortCreateLabel(string|Htmlable $value): string|Htmlable
    {
        if ($value instanceof Htmlable) {
            return $value;
        }

        $resource = static::getResource();
        $modelLabel = method_exists($resource, 'getModelLabel')
            ? trim((string) $resource::getModelLabel())
            : '';

        if ($modelLabel !== '') {
            return Str::ucfirst($modelLabel);
        }

        $raw = trim($value);
        $normalized = trim((string) preg_replace('/^(create|new|add)\s*/i', '', $raw));

        return Str::ucfirst($normalized !== '' ? $normalized : $raw);
    }
}
