<?php
# app/Console/Commands/ImportTenantAccrualsFromCsv.php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

class ImportTenantAccrualsFromCsv extends Command
{
    protected $signature = 'market:import-tenant-accruals
        {file : Path to CSV (absolute or relative to storage/app)}
        {--market-id= : Market ID. If omitted, will use the only active market, otherwise asks to specify}
        {--period= : Period in YYYY-MM (e.g. 2026-01). If omitted, will try to infer from filename}
        {--dry-run : Parse only (transaction rollback), do not write into DB}
        {--delimiter= : Force delimiter (; , or \t). If omitted, auto-detect}
        {--encoding=utf-8 : Source encoding: utf-8 or win-1251}
        {--limit=0 : Limit rows for testing (0 = no limit)}
        {--set-space-tenant : Update market_spaces.tenant_id to current tenant_id (optional)}
    ';

    protected $description = 'Import monthly tenant accruals from CSV into tenant_accruals (upsert tenants/spaces). Occupied/free is determined by rent_amount (non-zero => occupied). Free/leased area columns are used only for area. Skips section/summary rows.';

    /** @var array<string, array<int, string>> */
    private array $tableColumnsCache = [];

    public function handle(): int
    {
        $startedAt = microtime(true);

        $fileArg = (string) $this->argument('file');
        $filePath = $this->resolveFilePath($fileArg);

        if (! is_file($filePath)) {
            $this->error("File not found: {$fileArg}");
            $this->line("Tried: {$filePath}");
            $this->line('Tip: put files under storage/app/imports/tenant_accruals and pass relative path, e.g.: imports/tenant_accruals/accruals_2026-01.csv');
            return self::FAILURE;
        }

        $encoding = strtolower((string) $this->option('encoding'));
        if (! in_array($encoding, ['utf-8', 'win-1251'], true)) {
            $this->error("Unsupported encoding: {$encoding}. Use utf-8 or win-1251.");
            return self::FAILURE;
        }

        $delimiter = $this->detectDelimiter($filePath);
        $forcedDelimiter = (string) ($this->option('delimiter') ?? '');
        if ($forcedDelimiter !== '') {
            $delimiter = $this->normalizeDelimiter($forcedDelimiter);
        }

        $marketId = $this->resolveMarketId();
        if (! $marketId) {
            return self::FAILURE;
        }

        $period = $this->resolvePeriod($fileArg);
        if (! $period) {
            $this->error('Period is required. Pass --period=YYYY-MM or include YYYY-MM in filename (e.g. accruals_2026-01.csv).');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?? 0);
        $setSpaceTenant = (bool) $this->option('set-space-tenant');

        $this->info('Import settings:');
        $this->line("  file: {$filePath}");
        $this->line('  delimiter: ' . ($delimiter === "\t" ? '\t' : $delimiter));
        $this->line("  encoding: {$encoding}");
        $this->line("  market_id: {$marketId}");
        $this->line("  period: {$period->format('Y-m-d')}");
        $this->line('  dry_run: ' . ($dryRun ? 'yes' : 'no'));
        $this->line("  limit: {$limit}");
        $this->line('  set_space_tenant: ' . ($setSpaceTenant ? 'yes' : 'no'));
        $this->newLine();

        $stats = [
            'rows_total' => 0,
            'rows_skipped' => 0,
            'rows_errors' => 0,
            'tenants_created' => 0,
            'spaces_created' => 0,
            'spaces_marked_vacant' => 0,   // по смыслу: free
            'spaces_marked_occupied' => 0,
            'location_types_created' => 0,
            'locations_created' => 0,
            'spaces_location_set' => 0,
            'accruals_inserted' => 0,
            'accruals_updated' => 0,
        ];

        try {
            $csv = new SplFileObject($filePath);
            $csv->setFlags(
                SplFileObject::READ_CSV |
                SplFileObject::SKIP_EMPTY |
                SplFileObject::DROP_NEW_LINE
            );
            $csv->setCsvControl($delimiter);

            // 1) Read header row (skip optional "sep=;" line and empty lines)
            $headers = null;

            while (! $csv->eof()) {
                $row = $csv->fgetcsv();
                if (! is_array($row)) {
                    continue;
                }

                $row = $this->convertRowEncoding($row, $encoding);
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                if (isset($row[0]) && is_string($row[0]) && Str::startsWith(Str::lower(trim($row[0])), 'sep=')) {
                    continue;
                }

                $row = $this->stripUtf8BomFromFirstCell($row);
                $headers = $row;
                break;
            }

            if (! $headers) {
                $this->error('Cannot read header row from CSV.');
                return self::FAILURE;
            }

            $col = $this->buildColumnIndex($headers);

            if (! isset($col['tenant_name'])) {
                $this->error('Cannot find required column "ФИО" (tenant name).');
                $this->line('Found headers: ' . implode(' | ', array_map(fn ($h) => (string) $h, $headers)));
                return self::FAILURE;
            }

            if (! isset($col['place_code'])) {
                $this->warn('Column "№ отдела" not found. Spaces will not be created/updated; accruals will be imported with market_space_id = NULL.');
            }

            // Защита: иногда "Вид деятельности" ошибочно распознаётся как та же колонка, что и "Название отдела"
            if (
                array_key_exists('activity_type', $col)
                && array_key_exists('place_name', $col)
                && $col['activity_type'] !== null
                && $col['place_name'] !== null
                && (int) $col['activity_type'] === (int) $col['place_name']
            ) {
                $this->warn('Column "Вид деятельности" overlaps with "Название отдела" in header detection. activity_type import will be disabled for this file to prevent wrong values.');
                $col['activity_type'] = null;
            }

            $now = now();

            if ($dryRun) {
                $this->warn('DRY RUN: transaction will be rolled back (no data will be written).');
            }

            $marketSpacesHasStatus = $this->hasColumn('market_spaces', 'status');
            $marketSpacesHasTenantId = $this->hasColumn('market_spaces', 'tenant_id');
            $marketSpacesHasLocationId = $this->hasColumn('market_spaces', 'location_id');

            // NEW: отображаемые поля для места
            $marketSpacesHasDisplayName = $this->hasColumn('market_spaces', 'display_name');
            $marketSpacesHasActivityType = $this->hasColumn('market_spaces', 'activity_type');

            // Площадь в market_spaces может называться по-разному (area или area_sqm)
            $marketSpacesAreaColumns = [];
            if ($this->hasColumn('market_spaces', 'area')) {
                $marketSpacesAreaColumns[] = 'area';
            }
            if ($this->hasColumn('market_spaces', 'area_sqm')) {
                $marketSpacesAreaColumns[] = 'area_sqm';
            }

            $hasLocationTypesTable = $this->hasTable('market_location_types')
                && $this->hasColumn('market_location_types', 'code')
                && $this->hasColumn('market_location_types', 'name_ru');

            $hasLocationsTable = $this->hasTable('market_locations')
                && $this->hasColumn('market_locations', 'code')
                && $this->hasColumn('market_locations', 'name')
                && $this->hasColumn('market_locations', 'type');

            if ($marketSpacesHasLocationId && (! $hasLocationTypesTable || ! $hasLocationsTable)) {
                $this->warn('market_spaces.location_id exists, but market_location_types/market_locations tables are missing or incompatible. Location assignment will be skipped.');
            }

            // ВАЖНО: для SQLite возможны "грязные" значения в числовых полях (например, строка "area")
            $dbDriver = DB::getDriverName();

            $runner = function () use (
                $csv,
                $headers,
                $col,
                $marketId,
                $period,
                $filePath,
                $limit,
                $setSpaceTenant,
                $now,
                $encoding,
                $marketSpacesHasStatus,
                $marketSpacesHasTenantId,
                $marketSpacesHasLocationId,
                $marketSpacesHasDisplayName,
                $marketSpacesHasActivityType,
                $marketSpacesAreaColumns,
                $hasLocationTypesTable,
                $hasLocationsTable,
                $dbDriver,
                &$stats
            ) {
                $tenantCache = []; // name => id
                $spaceCache = [];  // place_code => id
                $spaceOccupiedSeen = []; // place_code => true (prevent downgrade to free within this run)

                $locationCache = []; // location_type_code => location_id

                $ctxLocationType = '';
                $ctxTenantName = '';

                $rentEps = 0.00001;

                while (! $csv->eof()) {
                    $row = $csv->fgetcsv();
                    if (! is_array($row)) {
                        continue;
                    }

                    $row = $this->convertRowEncoding($row, $encoding);

                    if ($this->isEmptyRow($row)) {
                        continue;
                    }

                    if ($this->looksLikeHeaderRepeat($headers, $row)) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    $stats['rows_total']++;

                    if ($limit > 0 && $stats['rows_total'] > $limit) {
                        break;
                    }

                    $sourceRowNumber = $csv->key() + 1;

                    $tenantNameRaw = $this->cell($row, $col, 'tenant_name');
                    $placeCodeRaw = $this->cell($row, $col, 'place_code');
                    $placeName = $this->cell($row, $col, 'place_name');       // "Название отдела"
                    $activityType = $this->cell($row, $col, 'activity_type'); // "Вид деятельности"

                    $locationType = $this->cell($row, $col, 'location_type');
                    if (trim($locationType) !== '') {
                        $ctxLocationType = $this->normalizeLocationName(trim($locationType));
                    }

                    $tenantName = trim((string) $tenantNameRaw);
                    $placeCode = trim((string) $placeCodeRaw);

                    // Площадь: одна и та же площадь может быть в "свободная" или "сданная"
                    $freeArea = $this->parseNumber($this->cell($row, $col, 'free_area_sqm'));
                    $leasedArea = $this->parseNumber($this->cell($row, $col, 'leased_area_sqm'));
                    $areaFallback = $this->parseNumber($this->cell($row, $col, 'area_sqm'));
                    $area = max($freeArea, $leasedArea, $areaFallback);

                    $rentRate = $this->parseNumber($this->cell($row, $col, 'rent_rate'));
                    $rentAmount = $this->parseMoney($this->cell($row, $col, 'rent_amount'));

                    $managementFee =
                        $this->parseMoney($this->cell($row, $col, 'management_fee_1')) +
                        $this->parseMoney($this->cell($row, $col, 'management_fee_2'));

                    $electricity = $this->parseMoney($this->cell($row, $col, 'electricity_amount'));
                    $utilities = $this->parseMoney($this->cell($row, $col, 'utilities_amount'));

                    $totalNoVat = $this->parseMoney($this->cell($row, $col, 'total_no_vat'));
                    $totalWithVat = $this->parseMoney($this->cell($row, $col, 'total_with_vat'));

                    $days = $this->parseIntNullable($this->cell($row, $col, 'days'));
                    $discountNote = $this->cell($row, $col, 'discount_note');
                    $cashAmount = $this->parseMoney($this->cell($row, $col, 'cash_amount'));

                    $hasAnyAmounts = (abs($rentAmount) + abs($managementFee) + abs($electricity) + abs($utilities) + abs($totalNoVat) + abs($totalWithVat) + abs($cashAmount)) > 0.00001;

                    // Если ФИО пустое, но есть суммы — это может быть продолжение строки (после объединённых ячеек в Excel)
                    if ($tenantName !== '') {
                        $ctxTenantName = $tenantName;
                    } elseif ($tenantName === '' && $hasAnyAmounts && $ctxTenantName !== '') {
                        $tenantName = $ctxTenantName;
                    }

                    // Секции/заголовки: есть текст в ФИО, но нет места, нет площади, нет сумм
                    if ($this->isSectionLikeRow($tenantName, $placeCode, $area, $rentRate, $days, $hasAnyAmounts)) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    // Итоги/своды
                    if ($this->isSummaryRow($tenantName, $placeCode, $area, $hasAnyAmounts)) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    // Если строка совсем "пустая по смыслу" — пропускаем
                    $looksMeaningful = ($tenantName !== '') || ($placeCode !== '') || ($area > 0) || $hasAnyAmounts;
                    if (! $looksMeaningful) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    /**
                     * ВАЖНО:
                     * Главный признак "сдано/не сдано" = "Сумма аренды" (rent_amount).
                     * - abs(rent_amount) > 0 => occupied (leased)
                     * - abs(rent_amount) == 0 => free
                     *
                     * Колонки "Свободная площадь"/"Сданная площадь" используются только для площади и в статус НЕ вмешиваются.
                     */
                    $isLeased = abs($rentAmount) > $rentEps; // occupied
                    $isFree = ! $isLeased;                  // free
                    $statusKnown = true;

                    // Мягкие предупреждения о качестве исходника (НЕ блокируют, НЕ меняют статус)
                    if ($isFree && ($managementFee != 0.0 || $electricity != 0.0 || $utilities != 0.0 || $totalNoVat != 0.0 || $totalWithVat != 0.0 || $cashAmount != 0.0)) {
                        $this->warn("Row {$sourceRowNumber}: rent_amount is 0 but other amounts present (place \"{$placeCode}\"). Treating as FREE and skipping accrual.");
                    }
                    if ($isFree && $leasedArea > 0 && $freeArea <= 0) {
                        $this->warn("Row {$sourceRowNumber}: leased_area_sqm > 0 but rent_amount is 0 (place \"{$placeCode}\"). Treating as FREE.");
                    }
                    if ($isLeased && $leasedArea <= 0 && $freeArea > 0) {
                        $this->warn("Row {$sourceRowNumber}: rent_amount is non-zero but free_area_sqm > 0 and leased_area_sqm is empty/0 (place \"{$placeCode}\"). Treating as OCCUPIED.");
                    }

                    // Resolve location_id by ctxLocationType (MVP: 1:1 type -> root location)
                    $locationId = null;
                    if (
                        $marketSpacesHasLocationId
                        && $hasLocationTypesTable
                        && $hasLocationsTable
                        && $ctxLocationType !== ''
                    ) {
                        $locationId = $this->resolveOrCreateLocationId(
                            $marketId,
                            $ctxLocationType,
                            $now,
                            $locationCache,
                            $stats
                        );
                    }

                    $displayNameValue = trim($placeName) !== '' ? trim($placeName) : ($placeCode !== '' ? ('Место ' . $placeCode) : '');

                    // Upsert space (и для free, и для occupied)
                    $marketSpaceId = null;
                    if ($placeCode !== '') {
                        $marketSpaceId = $spaceCache[$placeCode] ?? null;

                        if (! $marketSpaceId) {
                            $marketSpaceId = DB::table('market_spaces')
                                ->where('market_id', $marketId)
                                ->where(function ($q) use ($placeCode) {
                                    $q->where('number', $placeCode)->orWhere('code', $placeCode);
                                })
                                ->value('id');

                            if (! $marketSpaceId) {
                                $insert = [
                                    'market_id' => $marketId,
                                    'number' => $placeCode,
                                    'code' => $placeCode,
                                    'is_active' => 1,
                                    // ВАЖНО: notes — ручные заметки, импортом не заполняем.
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];

                                // площадь (если в схеме есть area/area_sqm)
                                if ($area > 0) {
                                    foreach ($marketSpacesAreaColumns as $areaColumn) {
                                        $insert[$areaColumn] = $area;
                                    }
                                }

                                if ($marketSpacesHasStatus) {
                                    $insert['status'] = $isLeased ? 'occupied' : 'free';
                                }

                                if ($marketSpacesHasLocationId && $locationId) {
                                    $insert['location_id'] = $locationId;
                                    $stats['spaces_location_set']++;
                                }

                                // NEW: заполняем отображаемые поля (мягко)
                                if ($marketSpacesHasDisplayName) {
                                    $insert['display_name'] = $displayNameValue !== '' ? $displayNameValue : null;
                                }
                                if ($marketSpacesHasActivityType) {
                                    $insert['activity_type'] = trim($activityType) !== '' ? trim($activityType) : null;
                                }

                                $marketSpaceId = DB::table('market_spaces')->insertGetId($insert);
                                $stats['spaces_created']++;
                            }

                            $spaceCache[$placeCode] = $marketSpaceId;
                        }

                        $update = [
                            // ВАЖНО: notes — ручные заметки, импортом не перезаписываем.
                            'updated_at' => $now,
                        ];

                        // площадь: заполняем только если сейчас NULL/0/плейсхолдер (идемпотентно)
                        if ($area > 0) {
                            $sqlArea = $this->formatSqlNumber($area);

                            foreach ($marketSpacesAreaColumns as $areaColumn) {
                                if ($dbDriver === 'sqlite') {
                                    $update[$areaColumn] = DB::raw(
                                        "CASE
                                            WHEN {$areaColumn} IS NULL
                                                OR {$areaColumn} = ''
                                                OR {$areaColumn} = 0
                                                OR {$areaColumn} = '0'
                                                OR {$areaColumn} IN ('area', 'area_sqm')
                                            THEN {$sqlArea}
                                            ELSE {$areaColumn}
                                        END"
                                    );
                                } else {
                                    $update[$areaColumn] = DB::raw('COALESCE(NULLIF(' . $areaColumn . ', 0), ' . $sqlArea . ')');
                                }
                            }
                        }

                        if ($marketSpacesHasLocationId && $locationId) {
                            // Не перетираем ручные правки: ставим location_id только если он ещё NULL
                            $update['location_id'] = DB::raw('COALESCE(location_id, ' . (int) $locationId . ')');
                            $stats['spaces_location_set']++;
                        }

                        // NEW: обновляем отображаемые поля, но НЕ перетираем уже заполненные вручную/ранее
                        if ($marketSpacesHasDisplayName && $displayNameValue !== '') {
                            $q = $this->quoteSqlString($displayNameValue);
                            $update['display_name'] = DB::raw("CASE WHEN display_name IS NULL OR display_name = '' OR display_name = 'display_name' THEN {$q} ELSE display_name END");
                        }

                        if ($marketSpacesHasActivityType) {
                            if (trim($activityType) !== '') {
                                $q = $this->quoteSqlString(trim($activityType));
                                $update['activity_type'] = DB::raw("CASE WHEN activity_type IS NULL OR activity_type = '' OR activity_type = 'activity_type' OR activity_type = 'не указано' THEN {$q} ELSE activity_type END");
                            } else {
                                // Если в исходнике пусто — не подставляем чужие значения.
                                // 1) Пустое/плейсхолдер -> "не указано"
                                // 2) Если ранее был баг и activity_type совпал с display_name и notes (notes не пустые) -> "не указано"
                                if ($marketSpacesHasDisplayName) {
                                    $update['activity_type'] = DB::raw(
                                        "CASE
                                            WHEN activity_type IS NULL OR activity_type = '' OR activity_type = 'activity_type' THEN 'не указано'
                                            WHEN notes IS NOT NULL AND notes <> '' AND display_name IS NOT NULL AND display_name <> '' AND activity_type = display_name AND activity_type = notes THEN 'не указано'
                                            ELSE activity_type
                                        END"
                                    );
                                } else {
                                    $update['activity_type'] = DB::raw(
                                        "CASE
                                            WHEN activity_type IS NULL OR activity_type = '' OR activity_type = 'activity_type' THEN 'не указано'
                                            ELSE activity_type
                                        END"
                                    );
                                }
                            }
                        }

                        if ($marketSpacesHasStatus && $statusKnown) {
                            if ($isLeased) {
                                $spaceOccupiedSeen[$placeCode] = true;
                                $update['status'] = 'occupied';
                            } elseif ($isFree) {
                                // не понижаем до free, если в рамках этого импорта место уже было occupied
                                $update['status'] = isset($spaceOccupiedSeen[$placeCode]) ? 'occupied' : 'free';
                            }
                        }

                        DB::table('market_spaces')->where('id', $marketSpaceId)->update($update);

                        if ($marketSpacesHasStatus) {
                            if (($update['status'] ?? null) === 'free') {
                                $stats['spaces_marked_vacant']++;
                            } elseif (($update['status'] ?? null) === 'occupied') {
                                $stats['spaces_marked_occupied']++;
                            }
                        }
                    }

                    // Upsert tenant: даже если место free (ФИО != закрепление места)
                    $tenantId = null;
                    if ($tenantName !== '') {
                        $tenantId = $tenantCache[$tenantName] ?? null;
                        if (! $tenantId) {
                            $tenantId = DB::table('tenants')
                                ->where('market_id', $marketId)
                                ->where('name', $tenantName)
                                ->value('id');

                            if (! $tenantId) {
                                $tenantId = DB::table('tenants')->insertGetId([
                                    'market_id' => $marketId,
                                    'name' => $tenantName,
                                    'type' => $this->inferTenantType($tenantName),
                                    'is_active' => 1,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                                $stats['tenants_created']++;
                            }

                            $tenantCache[$tenantName] = $tenantId;
                        }
                    }

                    // Начисление создаём только для leased (occupied) по rent_amount
                    if (! $isLeased) {
                        continue;
                    }

                    // leased без арендатора — ошибка исходника (начислять некому)
                    if ($tenantName === '' || ! $tenantId) {
                        $stats['rows_errors']++;
                        $this->warn("Row {$sourceRowNumber}: rent_amount is non-zero but tenant name is empty. Accrual skipped for place \"{$placeCode}\".");
                        continue;
                    }

                    $vatRate = $this->inferVatRate($headers, $totalNoVat, $totalWithVat);

                    // Optionally update market_spaces.tenant_id ONLY for leased rows
                    if ($setSpaceTenant && $marketSpaceId && $marketSpacesHasTenantId) {
                        DB::table('market_spaces')->where('id', $marketSpaceId)->update([
                            'tenant_id' => $tenantId,
                            'updated_at' => $now,
                        ]);
                    }

                    // Idempotent hash per row (НЕ зависит от номера строки)
                    $hashData = [
                        'market_id' => $marketId,
                        'period' => $period->format('Y-m-d'),
                        'tenant' => $tenantName,
                        'place' => $placeCode,
                        'free_area' => $freeArea,
                        'leased_area' => $leasedArea,
                        'area' => $area,
                        'rent_rate' => $rentRate,
                        'rent_amount' => $rentAmount,
                        'management_fee' => $managementFee,
                        'utilities' => $utilities,
                        'electricity' => $electricity,
                        'total_no_vat' => $totalNoVat,
                        'total_with_vat' => $totalWithVat,
                        'days' => $days,
                        'cash' => $cashAmount,
                        'discount_note' => $discountNote,
                        'activity' => $activityType,
                        'location_type' => $ctxLocationType,
                    ];
                    $sourceRowHash = hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE));

                    $where = [
                        'market_id' => $marketId,
                        'period' => $period->format('Y-m-d'),
                        'source_row_hash' => $sourceRowHash,
                    ];

                    $exists = DB::table('tenant_accruals')->where($where)->exists();

                    $payload = $this->buildPayload($headers, $row);

                    $data = [
                        'market_id' => $marketId,
                        'tenant_id' => $tenantId,
                        'tenant_contract_id' => null,
                        'market_space_id' => $marketSpaceId,
                        'period' => $period->format('Y-m-d'),

                        'source_place_code' => $placeCode !== '' ? $placeCode : null,
                        'source_place_name' => $placeName !== '' ? $placeName : null,
                        'activity_type' => $activityType !== '' ? $activityType : null,

                        'area_sqm' => $area > 0 ? $area : null,
                        'rent_rate' => $rentRate > 0 ? $rentRate : null,
                        'days' => $days,

                        'currency' => 'RUB',
                        'rent_amount' => $rentAmount != 0.0 ? $rentAmount : null,
                        'management_fee' => $managementFee != 0.0 ? $managementFee : null,
                        'utilities_amount' => $utilities != 0.0 ? $utilities : null,
                        'electricity_amount' => $electricity != 0.0 ? $electricity : null,

                        'total_no_vat' => $totalNoVat != 0.0 ? $totalNoVat : null,
                        'vat_rate' => $vatRate,
                        'total_with_vat' => ($totalWithVat != 0.0 ? $totalWithVat : ($totalNoVat != 0.0 ? $totalNoVat : null)),

                        'discount_note' => $discountNote !== '' ? $discountNote : null,
                        'cash_amount' => $cashAmount != 0.0 ? $cashAmount : null,
                        'notes' => null,

                        'status' => 'imported',
                        'source' => 'excel',
                        'source_file' => basename($filePath),
                        'source_row_number' => $sourceRowNumber,
                        'source_row_hash' => $sourceRowHash,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'imported_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (! $exists) {
                        $data['created_at'] = $now;
                    }

                    DB::table('tenant_accruals')->updateOrInsert($where, $data);

                    if ($exists) {
                        $stats['accruals_updated']++;
                    } else {
                        $stats['accruals_inserted']++;
                    }
                }
            };

            if ($dryRun) {
                DB::beginTransaction();
                try {
                    $runner();
                    DB::rollBack();
                } catch (Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            } else {
                DB::transaction(function () use ($runner) {
                    $runner();
                });
            }
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->newLine();
        $this->info('Done.');
        $this->line("Elapsed: {$elapsed}s");
        $this->line("Rows total: {$stats['rows_total']}");
        $this->line("Rows skipped: {$stats['rows_skipped']}");
        $this->line("Errors: {$stats['rows_errors']}");
        $this->line("Tenants created: {$stats['tenants_created']}");
        $this->line("Spaces created: {$stats['spaces_created']}");
        $this->line("Spaces marked free: {$stats['spaces_marked_vacant']}");
        $this->line("Spaces marked occupied: {$stats['spaces_marked_occupied']}");
        $this->line("Location types created: {$stats['location_types_created']}");
        $this->line("Locations created: {$stats['locations_created']}");
        $this->line("Spaces location set (attempts): {$stats['spaces_location_set']}");
        $this->line("Accruals inserted: {$stats['accruals_inserted']}");
        $this->line("Accruals updated: {$stats['accruals_updated']}");

        return self::SUCCESS;
    }

    private function resolveOrCreateLocationId(
        int $marketId,
        string $locationTypeName,
        Carbon $now,
        array &$locationCache,
        array &$stats
    ): ?int {
        $name = $this->normalizeLocationName($locationTypeName);
        if ($name === '') {
            return null;
        }

        $typeCode = $this->makeLocationTypeCode($name);

        if (isset($locationCache[$typeCode])) {
            return (int) $locationCache[$typeCode];
        }

        // 1) market_location_types: upsert by (market_id, code)
        $typeId = DB::table('market_location_types')
            ->where('market_id', $marketId)
            ->where('code', $typeCode)
            ->value('id');

        if (! $typeId) {
            $typeId = DB::table('market_location_types')->insertGetId([
                'market_id' => $marketId,
                'name_ru' => $name,
                'code' => $typeCode,
                'sort_order' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stats['location_types_created']++;
        } else {
            // Мягкое обновление названия
            DB::table('market_location_types')->where('id', $typeId)->update([
                'name_ru' => $name !== '' ? $name : DB::raw('name_ru'),
                'updated_at' => $now,
            ]);
        }

        // 2) market_locations: root location for this type (1:1 in MVP)
        $locationId = DB::table('market_locations')
            ->where('market_id', $marketId)
            ->where('code', $typeCode)
            ->value('id');

        if (! $locationId) {
            $locationId = DB::table('market_locations')->insertGetId([
                'market_id' => $marketId,
                'name' => $name,
                'code' => $typeCode,
                'type' => $typeCode,
                'parent_id' => null,
                'sort_order' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stats['locations_created']++;
        } else {
            DB::table('market_locations')->where('id', $locationId)->update([
                'name' => $name !== '' ? $name : DB::raw('name'),
                'type' => $typeCode,
                'updated_at' => $now,
            ]);
        }

        $locationCache[$typeCode] = (int) $locationId;

        return (int) $locationId;
    }

    private function normalizeLocationName(string $name): string
    {
        $s = trim($name);
        $s = str_replace(["\xC2\xA0", "\t"], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }

    private function makeLocationTypeCode(string $name): string
    {
        $code = Str::slug($name, '-');

        if ($code === '') {
            $code = 'loc-' . substr(hash('sha1', $name), 0, 10);
        }

        return $code;
    }

    private function resolveFilePath(string $fileArg): string
    {
        $fileArg = trim($fileArg);

        // Absolute path (Linux or Windows)
        if (Str::startsWith($fileArg, ['/', '\\']) || preg_match('/^[A-Za-z]:\\\\/', $fileArg)) {
            return $fileArg;
        }

        // Relative to storage/app
        return storage_path('app/' . ltrim($fileArg, '/'));
    }

    private function normalizeDelimiter(string $delimiter): string
    {
        $d = trim($delimiter);
        if ($d === '\t' || $d === 'tab') {
            return "\t";
        }
        return $d !== '' ? $d[0] : ';';
    }

    private function detectDelimiter(string $filePath): string
    {
        $sample = file_get_contents($filePath, false, null, 0, 8192) ?: '';
        $counts = [
            ';' => substr_count($sample, ';'),
            ',' => substr_count($sample, ','),
            "\t" => substr_count($sample, "\t"),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $best ?: ';';
    }

    private function resolveMarketId(): ?int
    {
        $marketIdOpt = $this->option('market-id');
        if (is_numeric($marketIdOpt)) {
            return (int) $marketIdOpt;
        }

        $markets = DB::table('markets')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get(['id', 'name', 'slug']);

        if ($markets->count() === 1) {
            return (int) $markets->first()->id;
        }

        if ($markets->isEmpty()) {
            $this->error('No active markets found. Please create a market first.');
            return null;
        }

        $this->error('Multiple active markets found. Please pass --market-id=');
        foreach ($markets as $m) {
            $this->line("  id={$m->id} slug={$m->slug} name={$m->name}");
        }

        return null;
    }

    private function resolvePeriod(string $fileArg): ?Carbon
    {
        $periodOpt = (string) ($this->option('period') ?? '');
        if ($periodOpt !== '') {
            if (! preg_match('/^\d{4}-\d{2}$/', $periodOpt)) {
                $this->error("Invalid --period format: {$periodOpt}. Use YYYY-MM (e.g. 2026-01).");
                return null;
            }
            return Carbon::createFromFormat('Y-m-d', $periodOpt . '-01')->startOfDay();
        }

        if (preg_match('/(\d{4})[-_.](\d{2})/', $fileArg, $m)) {
            return Carbon::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-01")->startOfDay();
        }

        return null;
    }

    private function stripUtf8BomFromFirstCell(array $row): array
    {
        if (isset($row[0]) && is_string($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
        }
        return $row;
    }

    private function convertRowEncoding(array $row, string $encoding): array
    {
        $row = array_map(function ($v) {
            if (is_string($v)) {
                return preg_replace('/^\xEF\xBB\xBF/', '', $v) ?? $v;
            }
            return $v;
        }, $row);

        if ($encoding === 'utf-8') {
            return $row;
        }

        if (! function_exists('mb_convert_encoding')) {
            return $row;
        }

        return array_map(function ($v) {
            if (! is_string($v)) {
                return $v;
            }
            return mb_convert_encoding($v, 'UTF-8', 'Windows-1251');
        }, $row);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if (is_string($v) && trim($v) !== '') {
                return false;
            }
            if (is_numeric($v)) {
                return false;
            }
        }
        return true;
    }

    private function normalizeHeader(string $h): string
    {
        $s = mb_strtolower(trim($h));
        $s = str_replace(["\xC2\xA0", "\t"], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    private function buildColumnIndex(array $headers): array
    {
        $idx = [];

        foreach ($headers as $i => $h) {
            $hs = is_string($h) ? $h : (string) $h;
            $n = $this->normalizeHeader($hs);

            if ($n === '') {
                continue;
            }

            if (! isset($idx['location_type']) && Str::contains($n, ['тип локации', 'тип места'])) {
                $idx['location_type'] = $i;
                continue;
            }

            if (! isset($idx['tenant_name']) && Str::contains($n, ['фио'])) {
                $idx['tenant_name'] = $i;
                continue;
            }

            if (! isset($idx['place_code']) && Str::contains($n, ['№ отдела', 'номер отдела', 'отдел №', 'место'])) {
                $idx['place_code'] = $i;
                continue;
            }

            if (! isset($idx['place_name']) && Str::contains($n, ['название отдела', 'наименование отдела'])) {
                $idx['place_name'] = $i;
                continue;
            }

            if (! isset($idx['activity_type']) && Str::contains($n, ['вид деятельности'])) {
                $idx['activity_type'] = $i;
                continue;
            }

            if (! isset($idx['free_area_sqm']) && Str::contains($n, ['свободная площадь'])) {
                $idx['free_area_sqm'] = $i;
                continue;
            }

            if (! isset($idx['leased_area_sqm']) && Str::contains($n, ['сданная площадь'])) {
                $idx['leased_area_sqm'] = $i;
                continue;
            }

            if (! isset($idx['area_sqm']) && Str::contains($n, ['площадь'])) {
                $idx['area_sqm'] = $i;
                continue;
            }

            if (! isset($idx['rent_rate']) && Str::contains($n, ['ставка аренды'])) {
                $idx['rent_rate'] = $i;
                continue;
            }

            if (! isset($idx['rent_amount']) && Str::contains($n, ['сумма аренды'])) {
                $idx['rent_amount'] = $i;
                continue;
            }

            if (Str::contains($n, ['услуги управления'])) {
                if (Str::contains($n, ['эл', 'эл.энерг'])) {
                    $idx['management_fee_2'] = $i;
                } else {
                    $idx['management_fee_1'] = $i;
                }
                continue;
            }

            if (! isset($idx['electricity_amount']) && Str::contains($n, ['электроэнергия'])) {
                $idx['electricity_amount'] = $i;
                continue;
            }

            if (! isset($idx['utilities_amount']) && Str::contains($n, ['коммунальные услуги'])) {
                $idx['utilities_amount'] = $i;
                continue;
            }

            if (! isset($idx['total_no_vat']) && Str::contains($n, ['итого к оплате, без ндс', 'итого без ндс'])) {
                $idx['total_no_vat'] = $i;
                continue;
            }

            if (! isset($idx['total_with_vat']) && Str::contains($n, ['итого к оплате, с ндс', 'итого с ндс'])) {
                $idx['total_with_vat'] = $i;
                continue;
            }

            if (! isset($idx['days']) && Str::contains($n, ['кол-во дней', 'количество дней'])) {
                $idx['days'] = $i;
                continue;
            }

            if (! isset($idx['discount_note']) && Str::contains($n, ['дополнит', 'скидк'])) {
                $idx['discount_note'] = $i;
                continue;
            }

            if (! isset($idx['cash_amount']) && Str::contains($n, ['наличные', 'в том числе наличные'])) {
                $idx['cash_amount'] = $i;
                continue;
            }
        }

        $idx['location_type'] = $idx['location_type'] ?? null;
        $idx['place_code'] = $idx['place_code'] ?? null;
        $idx['place_name'] = $idx['place_name'] ?? null;
        $idx['activity_type'] = $idx['activity_type'] ?? null;

        $idx['free_area_sqm'] = $idx['free_area_sqm'] ?? null;
        $idx['leased_area_sqm'] = $idx['leased_area_sqm'] ?? null;
        $idx['area_sqm'] = $idx['area_sqm'] ?? null;

        $idx['management_fee_1'] = $idx['management_fee_1'] ?? null;
        $idx['management_fee_2'] = $idx['management_fee_2'] ?? null;

        return $idx;
    }

    private function cell(array $row, array $col, string $key): string
    {
        $i = $col[$key] ?? null;
        if ($i === null) {
            return '';
        }
        $v = $row[$i] ?? '';
        if (is_numeric($v)) {
            return (string) $v;
        }
        return is_string($v) ? trim($v) : (string) $v;
    }

    private function isSummaryRow(string $tenantName, string $placeCode, float $area, bool $hasAnyAmounts): bool
    {
        $name = trim($tenantName);
        if ($name === '') {
            return false;
        }

        $ln = mb_strtolower($name);

        if (Str::contains($ln, ['итого', 'всего', 'свод', 'результат'])) {
            return trim($placeCode) === '' && $area <= 0 && ! $hasAnyAmounts;
        }

        return false;
    }

    private function isSectionLikeRow(string $tenantName, string $placeCode, float $area, float $rentRate, ?int $days, bool $hasAnyAmounts): bool
    {
        if (trim($tenantName) === '') {
            return false;
        }

        $hasSomeData = (trim($placeCode) !== '') || ($area > 0) || ($rentRate > 0) || (($days ?? 0) > 0) || $hasAnyAmounts;

        return ! $hasSomeData;
    }

    private function inferTenantType(string $tenantName): ?string
    {
        $n = mb_strtoupper($tenantName);
        if (Str::contains($n, ['ООО'])) return 'ООО';
        if (Str::contains($n, ['АО'])) return 'АО';
        if (Str::contains($n, ['ИП'])) return 'ИП';
        return null;
    }

    private function parseNumber(string $value): float
    {
        $v = trim($value);
        if ($v === '') return 0.0;

        $v = str_replace(["\xC2\xA0", ' '], '', $v);
        $v = str_replace(',', '.', $v);
        $v = preg_replace('/[^0-9\.\-]/', '', $v) ?? $v;

        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function parseMoney(string $value): float
    {
        return $this->parseNumber($value);
    }

    private function parseIntNullable(string $value): ?int
    {
        $v = trim($value);
        if ($v === '') return null;

        $v = str_replace(["\xC2\xA0", ' '], '', $v);
        $v = preg_replace('/[^0-9\-]/', '', $v) ?? $v;

        return is_numeric($v) ? (int) $v : null;
    }

    private function inferVatRate(array $headers, float $totalNoVat, float $totalWithVat): ?float
    {
        if ($totalNoVat > 0 && $totalWithVat > 0) {
            $rate = ($totalWithVat - $totalNoVat) / $totalNoVat;
            if ($rate > 0 && $rate < 1) {
                return round($rate, 4);
            }
        }

        $h = mb_strtolower(implode(' ', array_map(fn ($x) => is_string($x) ? $x : (string) $x, $headers)));
        if (Str::contains($h, ['ндс 5', '5 %', '5%'])) return 0.05;
        if (Str::contains($h, ['ндс 20', '20 %', '20%'])) return 0.20;

        return null;
    }

    private function buildPayload(array $headers, array $row): array
    {
        $payload = [];
        foreach ($headers as $i => $h) {
            $key = is_string($h) ? trim($h) : (string) $h;
            $payload[$key] = $row[$i] ?? null;
        }
        return $payload;
    }

    private function looksLikeHeaderRepeat(array $headers, array $row): bool
    {
        $h0 = isset($headers[0]) ? (string) $headers[0] : '';
        $r0 = isset($row[0]) ? (string) $row[0] : '';
        $h0 = $this->normalizeHeader($h0);
        $r0 = $this->normalizeHeader($r0);

        return $h0 !== '' && $h0 === $r0;
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }

        try {
            $cols = $this->tableColumnsCache[$table] ?? null;
            if ($cols === null) {
                $cols = Schema::getColumnListing($table);
                $this->tableColumnsCache[$table] = $cols;
            }
            return in_array($column, $cols, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function quoteSqlString(string $value): string
    {
        try {
            return DB::getPdo()->quote($value);
        } catch (Throwable) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
    }

    private function formatSqlNumber(float $value): string
    {
        $s = number_format($value, 6, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
