<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;

abstract class BaseEditRecord extends EditRecord
{
    public static bool $formActionsAreSticky = true;
}
