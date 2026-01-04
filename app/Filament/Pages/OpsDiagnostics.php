<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\EntriesRepository;
use Throwable;
use UnitEnum;

class OpsDiagnostics extends Page
{
    /**
     * В вашей версии Filament view у Page задан как НЕ static.
     */
    protected string $view = 'filament.pages.ops-diagnostics';

    /**
     * Типы должны совпадать с Filament\Pages\Page (важно для PHPStan/IDE и избежания конфликтов сигнатур).
     */
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static UnitEnum|string|null $navigationGroup = 'Ops';

    protected static ?string $navigationLabel = 'Диагностика';
    protected static ?string $title = 'Диагностика (Ops)';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) ($user?->isSuperAdmin() ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getViewData(): array
    {
        $installed = class_exists(\Laravel\Telescope\Telescope::class);

        return [
            'appEnv' => (string) config('app.env'),
            'telescopeInstalled' => $installed,
            // Если Telescope не установлен — считаем выключенным.
            // Если установлен — по умолчанию true (типичная конфигурация telescope.php использует env с default=true).
            'telescopeEnabled' => $installed ? (bool) config('telescope.enabled', true) : false,
        ];
    }

    public function clearCaches(): void
    {
        $this->ensureSuperAdmin();

        try {
            Artisan::call('optimize:clear');

            Notification::make()
                ->title('Кэши очищены')
                ->success()
                ->send();

            $this->dispatch('$refresh');
        } catch (Throwable $e) {
            Notification::make()
                ->title('Не удалось очистить кэши')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Совместимость: если где-то (или ранее) blade вызывал pruneTelescope48h — оставляем метод.
     */
    public function pruneTelescope48h(): void
    {
        $this->pruneTelescope(hoursToKeep: 48);
    }

    /**
     * Основной метод под текущий blade (wire:click="pruneTelescope").
     */
    public function pruneTelescope(int $hoursToKeep = 48): void
    {
        $this->ensureSuperAdmin();

        if (! class_exists(\Laravel\Telescope\Telescope::class)) {
            Notification::make()
                ->title('Telescope не установлен')
                ->warning()
                ->send();

            return;
        }

        try {
            $before = now()->subHours($hoursToKeep);

            // Предпочтительно: чистка через репозиторий Telescope (не через artisan-команду в HTTP).
            if (app()->bound(EntriesRepository::class)) {
                $repo = app(EntriesRepository::class);

                try {
                    // На большинстве версий это рабочая сигнатура.
                    $repo->prune($before);
                } catch (Throwable) {
                    // На некоторых версиях есть второй параметр (например keepExceptions).
                    $repo->prune($before, false);
                }
            } else {
                // Fallback: прямое удаление из таблиц (если биндинг репозитория недоступен).
                $this->pruneTelescopeByDb($before);
            }

            Notification::make()
                ->title("Telescope очищен (старше {$hoursToKeep}ч)")
                ->success()
                ->send();

            $this->dispatch('$refresh');
        } catch (Throwable $e) {
            Notification::make()
                ->title('Не удалось очистить Telescope')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function pruneTelescopeByDb(Carbon $before): void
    {
        $connection = config('telescope.storage.database.connection', config('database.default'));

        DB::connection($connection)
            ->table('telescope_entries')
            ->where('created_at', '<', $before)
            ->orderBy('uuid')
            ->chunk(500, function ($rows) use ($connection) {
                // $rows — это Collection, pluck доступен напрямую.
                $uuids = $rows->pluck('uuid')->filter()->values()->all();

                if ($uuids === []) {
                    return;
                }

                DB::connection($connection)
                    ->table('telescope_entries_tags')
                    ->whereIn('entry_uuid', $uuids)
                    ->delete();

                DB::connection($connection)
                    ->table('telescope_entries')
                    ->whereIn('uuid', $uuids)
                    ->delete();
            });
    }

    private function ensureSuperAdmin(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }
}
