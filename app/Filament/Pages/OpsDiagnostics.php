<?php
# app/Filament/Pages/OpsDiagnostics.php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
     * Импорт "подложки" карты из JSON (market_map_extract_*.json).
     * Эти записи создаются с market_space_id = NULL.
     */
    private const MAP_EXTRACT_IMPORT_SOURCE_KEY = 'import_source';

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

    /**
     * Header actions (кнопки справа вверху страницы).
     * Не требует правок Blade-шаблона.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('importMapExtractJson')
                ->label('Импорт подложки карты (JSON)')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Импорт подложки карты из JSON')
                ->modalDescription('Загрузите market_map_extract_*.json. Импорт идемпотентный: при включённой замене будут удалены ранее импортированные записи из этого же файла.')
                ->form([
                    FileUpload::make('json_file')
                        ->label('JSON файл')
                        ->required()
                        ->disk('local')
                        ->directory('imports')
                        ->preserveFilenames()
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->maxSize(10_240) // 10 MB
                        ->helperText('Файл будет сохранён в storage/app/imports'),

                    Select::make('market_id')
                        ->label('Рынок')
                        ->required()
                        ->options(fn (): array => $this->getMarketOptions())
                        ->default(fn (): int => (int) (DB::table('markets')->min('id') ?? 1))
                        ->searchable(),

                    TextInput::make('page')
                        ->label('Page')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),

                    TextInput::make('version')
                        ->label('Version')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),

                    Toggle::make('replace_existing')
                        ->label('Заменить ранее импортированное из этого файла')
                        ->default(true)
                        ->helperText('Удалит записи market_space_id=NULL и meta содержит import_source=<имя файла>.'),
                ])
                ->action(function (array $data): void {
                    $this->ensureSuperAdmin();

                    $storedPath = (string) ($data['json_file'] ?? '');
                    $marketId = (int) ($data['market_id'] ?? 0);
                    $page = max(1, (int) ($data['page'] ?? 1));
                    $version = max(1, (int) ($data['version'] ?? 1));
                    $replace = (bool) ($data['replace_existing'] ?? true);

                    if ($storedPath === '') {
                        Notification::make()
                            ->title('Файл не загружен')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $result = $this->importMapExtractJson($storedPath, $marketId, $page, $version, $replace);

                        Notification::make()
                            ->title('Импорт выполнен')
                            ->body(
                                "Рынок: {$result['market_id']}\n"
                                . "Удалено: {$result['deleted']}\n"
                                . "Импортировано: {$result['imported']}\n"
                                . "Всего shapes по рынку: {$result['shapes_total']}\n"
                                . "Источник: {$result['source']}"
                            )
                            ->success()
                            ->send();

                        $this->dispatch('$refresh');
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Ошибка импорта')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
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

        // Небольшая диагностика для карты (не ломает страницу, даже если таблицы нет)
        $mapStats = $this->getMapShapesStatsBestEffort();

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

            // Map stats (optional rendering in Blade)
            'mapShapesTotal' => $mapStats['total'],
            'mapShapesUnlinked' => $mapStats['unlinked'],
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
     * Импорт подложки карты из файла вида market_map_extract_*.json, уже загруженного FileUpload.
     *
     * @param  string  $storedPath  Путь относительно storage/app (например imports/xxx.json)
     * @return array{market_id:int,deleted:int,imported:int,shapes_total:int,source:string}
     */
    private function importMapExtractJson(string $storedPath, int $marketId, int $page, int $version, bool $replaceExisting): array
    {
        if ($marketId <= 0) {
            throw new \RuntimeException('Некорректный market_id');
        }

        $absPath = storage_path('app/' . ltrim($storedPath, '/'));
        if (! is_file($absPath)) {
            throw new \RuntimeException("Файл не найден: {$absPath}");
        }

        $json = File::get($absPath);
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
            throw new \RuntimeException('Некорректный JSON: ожидается ключ items (массив).');
        }

        $items = $data['items'];
        $count = count($items);

        if ($count === 0) {
            throw new \RuntimeException('В JSON нет элементов для импорта (items пуст).');
        }

        $tag = basename($absPath);
        $pattern = '%' . $tag . '%';

        DB::beginTransaction();

        try {
            $deleted = 0;

            if ($replaceExisting) {
                // Удаляем только ранее импортированную "подложку" из этого же файла
                $deleted = DB::table('market_space_map_shapes')
                    ->where('market_id', $marketId)
                    ->whereNull('market_space_id')
                    ->where('meta', 'like', $pattern)
                    ->delete();
            }

            $now = now()->toDateTimeString();
            $imported = 0;

            $buffer = [];
            $sort = 0;

            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }

                foreach (['bbox_x1', 'bbox_y1', 'bbox_x2', 'bbox_y2', 'polygon'] as $req) {
                    if (! array_key_exists($req, $it)) {
                        throw new \RuntimeException("Некорректный items[]: нет ключа {$req}");
                    }
                }

                $polygonJson = json_encode($it['polygon'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($polygonJson === false) {
                    throw new \RuntimeException('Ошибка кодирования polygon: ' . json_last_error_msg());
                }

                $metaJson = json_encode([
                    self::MAP_EXTRACT_IMPORT_SOURCE_KEY => $tag,
                    'source' => $it['source'] ?? null,
                    'meta' => $it['meta'] ?? null,
                    'pdf' => $data['pdf'] ?? null,
                    'page_size' => $data['page_size'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($metaJson === false) {
                    throw new \RuntimeException('Ошибка кодирования meta: ' . json_last_error_msg());
                }

                $buffer[] = [
                    'market_id' => $marketId,
                    'market_space_id' => null,
                    'page' => $page,
                    'version' => $version,
                    'bbox_x1' => (float) $it['bbox_x1'],
                    'bbox_y1' => (float) $it['bbox_y1'],
                    'bbox_x2' => (float) $it['bbox_x2'],
                    'bbox_y2' => (float) $it['bbox_y2'],
                    'polygon' => $polygonJson,
                    'stroke_color' => $it['stroke_color'] ?? null,
                    'fill_color' => $it['fill_color'] ?? null,
                    'fill_opacity' => array_key_exists('fill_opacity', $it) ? $it['fill_opacity'] : null,
                    'stroke_width' => array_key_exists('stroke_width', $it) ? $it['stroke_width'] : null,
                    'meta' => $metaJson,
                    'is_active' => 1,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $sort++;
                $imported++;

                // На всякий случай батчим
                if (count($buffer) >= 500) {
                    DB::table('market_space_map_shapes')->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DB::table('market_space_map_shapes')->insert($buffer);
            }

            DB::commit();

            $shapesTotal = (int) DB::table('market_space_map_shapes')
                ->where('market_id', $marketId)
                ->count();

            return [
                'market_id' => $marketId,
                'deleted' => (int) $deleted,
                'imported' => (int) $imported,
                'shapes_total' => $shapesTotal,
                'source' => $tag,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @return array<int,string>
     */
    private function getMarketOptions(): array
    {
        try {
            $rows = DB::table('markets')->orderBy('id')->get();

            $out = [];
            foreach ($rows as $r) {
                $id = (int) ($r->id ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $label = null;

                // чаще всего есть name, но не предполагаем жёстко
                if (isset($r->name) && is_string($r->name) && trim($r->name) !== '') {
                    $label = trim($r->name);
                }

                $out[$id] = $label ? "{$label} (#{$id})" : "#{$id}";
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{total:int,unlinked:int}
     */
    private function getMapShapesStatsBestEffort(): array
    {
        try {
            $total = (int) DB::table('market_space_map_shapes')->count();
            $unlinked = (int) DB::table('market_space_map_shapes')->whereNull('market_space_id')->count();

            return ['total' => $total, 'unlinked' => $unlinked];
        } catch (Throwable) {
            return ['total' => 0, 'unlinked' => 0];
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
