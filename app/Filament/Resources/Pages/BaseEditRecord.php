<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

abstract class BaseEditRecord extends EditRecord
{
    public static bool $formActionsAreSticky = true;

    public function getTitle(): string|Htmlable
    {
        return $this->normalizeEditLabel(parent::getTitle());
    }

    public function getHeading(): string|Htmlable
    {
        return $this->normalizeEditLabel(parent::getHeading());
    }

    public function getBreadcrumb(): string
    {
        return (string) $this->normalizeEditLabel(parent::getBreadcrumb());
    }

    private function normalizeEditLabel(string|Htmlable $value): string|Htmlable
    {
        if ($value instanceof Htmlable) {
            return $value;
        }

        $raw = trim($value);
        $normalized = trim((string) preg_replace('/^(Редактирование|Edit)\s*/ui', '', $raw));

        if ($normalized !== '') {
            return $normalized;
        }

        $label = method_exists(static::getResource(), 'getModelLabel')
            ? (string) static::getResource()::getModelLabel()
            : $raw;

        return Str::ucfirst(trim($label));
    }
}
