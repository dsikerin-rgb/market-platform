<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        $telescopeInstalled = class_exists(\Laravel\Telescope\Telescope::class);
        $telescopeEnabled = $telescopeInstalled
            ? (bool) config('telescope.enabled', true) // типичный default в telescope.php
            : false;

        $git = $this->getGitInfo();

        return [
            'appEnv' => (string) config('app.env'),
            'appPath' => base_path(),

            // Версия/обновление (для вывода на странице)
            'gitCommitShort' => $git['commitShort'],
            'gitBranch' => $git['branch'],

            'telescopeInstalled' => $telescopeInstalled,
            'telescopeEnabled' => $telescopeEnabled,
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

        if (! (bool) config('telescope.enabled', true)) {
            Notification::make()
                ->title('Telescope выключен')
                ->body('Включите telescope.enabled, затем повторите.')
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
                // $rows обычно Collection из stdClass.
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

    /**
     * Информация о версии из .git без запуска внешних процессов.
     * Если деплой не через git или .git отсутствует — вернёт null.
     *
     * @return array{commitShort:?string, branch:?string}
     */
    private function getGitInfo(): array
    {
        $gitDir = base_path('.git');

        if (! is_dir($gitDir)) {
            return ['commitShort' => null, 'branch' => null];
        }

        $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';

        if (! is_file($headFile)) {
            return ['commitShort' => null, 'branch' => null];
        }

        $head = trim((string) File::get($headFile));

        if ($head === '') {
            return ['commitShort' => null, 'branch' => null];
        }

        $commit = null;
        $branch = null;

        // HEAD может быть либо хешом, либо ссылкой ref: refs/heads/main
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $branch = str_starts_with($ref, 'refs/heads/')
                ? substr($ref, strlen('refs/heads/'))
                : $ref;

            $refFile = $gitDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);

            if (is_file($refFile)) {
                $commit = trim((string) File::get($refFile));
            } else {
                // refs могут быть упакованы в packed-refs
                $packedRefs = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';

                if (is_file($packedRefs)) {
                    $lines = file($packedRefs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                    foreach ($lines as $line) {
                        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                            continue;
                        }

                        $parts = preg_split('/\s+/', trim($line), 2);
                        if (! is_array($parts) || count($parts) !== 2) {
                            continue;
                        }

                        [$hash, $refName] = $parts;

                        if ($refName === $ref) {
                            $commit = $hash;
                            break;
                        }
                    }
                }
            }
        } else {
            // detached HEAD
            $commit = $head;
        }

        $commit = $commit ? trim($commit) : null;

        return [
            'commitShort' => $commit ? substr($commit, 0, 7) : null,
            'branch' => $branch,
        ];
    }
}
