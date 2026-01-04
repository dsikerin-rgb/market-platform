<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Telescope\Contracts\EntriesRepository;
use Throwable;
use UnitEnum;

class OpsDiagnostics extends Page
{
    /**
     * Cache key that controls whether Telescope recording is enabled (non-local) with TTL.
     * Value: UNIX timestamp (int) until which recording is enabled.
     */
    private const TELESCOPE_ENABLED_UNTIL_CACHE_KEY = 'ops:telescope:enabled_until';

    private const TELESCOPE_DEFAULT_ENABLE_MINUTES = 30;

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

        // Важно: config('telescope.enabled') влияет на то, доступны ли маршруты/UI Telescope.
        // Наш "ops-toggle" управляет именно записью (recording) и делает это через cache TTL.
        $telescopeConfigEnabled = $telescopeInstalled
            ? (bool) config('telescope.enabled', true)
            : false;

        $telescopeEnabledUntil = $telescopeInstalled ? $this->getTelescopeEnabledUntil() : null;

        $telescopeRecordingEnabled = $telescopeInstalled
            && $telescopeEnabledUntil !== null
            && $telescopeEnabledUntil->isFuture();

        $git = $this->getGitInfo();

        return [
            'appEnv' => (string) config('app.env'),
            'appPath' => base_path(),

            'gitCommitShort' => $git['commitShort'],
            'gitBranch' => $git['branch'],
            'gitPrNumber' => $git['prNumber'],
            'gitVersionLabel' => $git['versionLabel'],

            'telescopeInstalled' => $telescopeInstalled,

            // Backward-compatible flag used by the Blade view in older iterations.
            // Now means "recording enabled by ops-toggle (TTL)".
            'telescopeEnabled' => $telescopeRecordingEnabled,

            // New, explicit flags for UI rendering.
            'telescopeConfigEnabled' => $telescopeConfigEnabled,
            'telescopeRecordingEnabled' => $telescopeRecordingEnabled,
            'telescopeEnabledUntil' => $telescopeEnabledUntil?->toDateTimeString(),
            'telescopeEnabledUntilHuman' => $telescopeEnabledUntil?->diffForHumans(),
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
     * Enable Telescope recording temporarily (with auto-disable via TTL).
     * Intended to be wired to a button on ops-diagnostics page.
     */
    public function enableTelescope30m(): void
    {
        $this->enableTelescope(self::TELESCOPE_DEFAULT_ENABLE_MINUTES);
    }

    public function enableTelescope(int $minutes = self::TELESCOPE_DEFAULT_ENABLE_MINUTES): void
    {
        $this->ensureSuperAdmin();

        if (! class_exists(\Laravel\Telescope\Telescope::class)) {
            Notification::make()
                ->title('Telescope не установлен')
                ->warning()
                ->send();

            return;
        }

        $minutes = max(1, min(24 * 60, $minutes));

        $until = now()->addMinutes($minutes);

        // Store "until" timestamp with TTL that expires at the same moment.
        Cache::put(self::TELESCOPE_ENABLED_UNTIL_CACHE_KEY, $until->getTimestamp(), $until);

        $body = "Автоматически выключится: {$until->toDateTimeString()} ({$until->diffForHumans()}).";

        if (! (bool) config('telescope.enabled', true)) {
            $body .= ' Внимание: telescope.enabled=false — UI/маршруты Telescope могут быть недоступны, даже если запись включена.';
        }

        Notification::make()
            ->title("Telescope включён на {$minutes} мин.")
            ->body($body)
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function disableTelescope(): void
    {
        $this->ensureSuperAdmin();

        Cache::forget(self::TELESCOPE_ENABLED_UNTIL_CACHE_KEY);

        Notification::make()
            ->title('Telescope выключен')
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function pruneTelescope48h(): void
    {
        $this->pruneTelescope(hoursToKeep: 48);
    }

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

            if (app()->bound(EntriesRepository::class)) {
                $repo = app(EntriesRepository::class);

                try {
                    $repo->prune($before);
                } catch (Throwable) {
                    $repo->prune($before, false);
                }
            } else {
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

    private function getTelescopeEnabledUntil(): ?Carbon
    {
        $ts = Cache::get(self::TELESCOPE_ENABLED_UNTIL_CACHE_KEY);

        return $ts ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    private function ensureSuperAdmin(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }

    /**
     * @return array{commitShort:?string, branch:?string, prNumber:?int, versionLabel:?string}
     */
    private function getGitInfo(): array
    {
        $gitDir = base_path('.git');

        if (! is_dir($gitDir)) {
            return ['commitShort' => null, 'branch' => null, 'prNumber' => null, 'versionLabel' => null];
        }

        $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
        $head = $this->safeReadFile($headFile);

        if ($head === null) {
            return ['commitShort' => null, 'branch' => null, 'prNumber' => null, 'versionLabel' => null];
        }

        $head = trim($head);
        if ($head === '') {
            return ['commitShort' => null, 'branch' => null, 'prNumber' => null, 'versionLabel' => null];
        }

        $commitFull = null;
        $branch = null;

        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));

            $branch = str_starts_with($ref, 'refs/heads/')
                ? substr($ref, strlen('refs/heads/'))
                : $ref;

            $refFile = $gitDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
            $commitFull = $this->safeReadFile($refFile);

            if ($commitFull === null) {
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
                            $commitFull = $hash;
                            break;
                        }
                    }
                }
            }
        } else {
            // detached HEAD
            $commitFull = $head;
        }

        $commitFull = $commitFull ? trim((string) $commitFull) : null;
        $commitShort = $commitFull ? substr($commitFull, 0, 7) : null;

        $prNumber = null;

        // 1) Быстро: пытаемся вытащить PR из сообщения последнего коммита.
        $prNumber = $this->tryExtractPrNumberFromLastCommitMessage();

        // 2) Fallback: если в сообщении нет #NN (fast-forward/rebase), пробуем GitHub API по SHA.
        if ($prNumber === null && $commitFull !== null) {
            $prNumber = $this->tryLookupPrNumberByCommitFromGitHub($commitFull);
        }

        $versionLabel = $prNumber ? ('#' . $prNumber) : null;

        return [
            'commitShort' => $commitShort,
            'branch' => $branch,
            'prNumber' => $prNumber,
            'versionLabel' => $versionLabel,
        ];
    }

    private function safeReadFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            return (string) File::get($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function tryExtractPrNumberFromLastCommitMessage(): ?int
    {
        try {
            $result = Process::timeout(2)
                ->path(base_path())
                ->run(['git', 'log', '-1', '--pretty=%B']);

            if (! $result->successful()) {
                return null;
            }

            $message = trim((string) $result->output());

            if (preg_match('/Merge pull request #(\d+)/', $message, $m)) {
                return (int) $m[1];
            }

            if (preg_match('/\(#(\d+)\)\s*$/m', $message, $m)) {
                return (int) $m[1];
            }
        } catch (Throwable) {
            // git/Process может быть недоступен — молча пропускаем.
        }

        return null;
    }

    /**
     * Ищет PR для конкретного commit SHA через GitHub API.
     * Работает даже если merge был fast-forward/rebase и в commit message нет " (#NN)".
     *
     * Важно: это best-effort. Если repo private — нужен токен в .env (GITHUB_TOKEN / GITHUB_API_TOKEN).
     */
    private function tryLookupPrNumberByCommitFromGitHub(string $commitSha): ?int
    {
        $cacheKey = 'ops:git:pr:' . $commitSha;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 0 ? null : (int) $cached;
        }

        $value = 0; // 0 = "не найдено/ошибка", чтобы тоже кэшировать

        try {
            $originUrl = $this->readOriginUrlFromGitConfig();
            if ($originUrl === null) {
                Cache::put($cacheKey, $value, now()->addHours(6));
                return null;
            }

            $repo = $this->parseGitHubOwnerRepo($originUrl);
            if ($repo === null) {
                Cache::put($cacheKey, $value, now()->addHours(6));
                return null;
            }

            [$owner, $name] = $repo;

            $token = (string) (config('services.github.token')
                ?? env('GITHUB_TOKEN')
                ?? env('GITHUB_API_TOKEN')
                ?? '');

            $url = "https://api.github.com/repos/{$owner}/{$name}/commits/{$commitSha}/pulls";

            $req = Http::timeout(2)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'market-platform-ops-diagnostics',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ]);

            if ($token !== '') {
                $req = $req->withToken($token);
            }

            $res = $req->get($url);

            if (! $res->successful()) {
                Cache::put($cacheKey, $value, now()->addHours(6));
                return null;
            }

            $data = $res->json();

            if (! is_array($data) || $data === []) {
                Cache::put($cacheKey, $value, now()->addHours(6));
                return null;
            }

            $numbers = [];
            foreach ($data as $pr) {
                if (is_array($pr) && isset($pr['number']) && is_numeric($pr['number'])) {
                    $numbers[] = (int) $pr['number'];
                }
            }

            if ($numbers !== []) {
                $value = max($numbers);
            }
        } catch (Throwable) {
            // ничего
        }

        Cache::put($cacheKey, $value, now()->addHours(6));

        return $value === 0 ? null : $value;
    }

    private function readOriginUrlFromGitConfig(): ?string
    {
        $cfg = $this->safeReadFile(base_path('.git/config'));
        if ($cfg === null || trim($cfg) === '') {
            return null;
        }

        // Вырезаем секцию [remote "origin"] ... до следующей секции
        $hay = $cfg . "\n[";
        if (! preg_match('/\[remote "origin"\](.*?)\n\[/s', $hay, $m)) {
            return null;
        }

        $section = "\n" . $m[1] . "\n";
        if (! preg_match('/\n\s*url\s*=\s*(.+)\s*\n/', $section, $u)) {
            return null;
        }

        return trim($u[1]);
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseGitHubOwnerRepo(string $originUrl): ?array
    {
        $originUrl = trim($originUrl);

        // git@github.com:owner/repo.git
        if (str_starts_with($originUrl, 'git@github.com:')) {
            $path = substr($originUrl, strlen('git@github.com:'));
            $path = preg_replace('/\.git$/', '', $path) ?? $path;

            $parts = explode('/', trim($path, '/'));
            if (count($parts) >= 2) {
                return [$parts[0], $parts[1]];
            }

            return null;
        }

        // https://github.com/owner/repo.git
        $parsed = @parse_url($originUrl);
        if (! is_array($parsed) || empty($parsed['host']) || empty($parsed['path'])) {
            return null;
        }

        if ($parsed['host'] !== 'github.com') {
            return null;
        }

        $path = trim((string) $parsed['path'], '/');
        $path = preg_replace('/\.git$/', '', $path) ?? $path;

        $parts = explode('/', $path);
        if (count($parts) >= 2) {
            return [$parts[0], $parts[1]];
        }

        return null;
    }
}
