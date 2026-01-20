<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    protected $description = 'Import monthly tenant accruals from CSV into tenant_accruals (upsert tenants/spaces, idempotent by hash).';

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

            $now = now();

            if ($dryRun) {
                $this->warn('DRY RUN: transaction will be rolled back (no data will be written).');
            }

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
                &$stats
            ) {
                $tenantCache = []; // name => id
                $spaceCache = [];  // place_code => id

                while (! $csv->eof()) {
                    $row = $csv->fgetcsv();
                    if (! is_array($row)) {
                        continue;
                    }

                    $row = $this->convertRowEncoding($row, $encoding);

                    if ($this->isEmptyRow($row)) {
                        continue;
                    }

                    // Skip accidental second header repeats
                    if ($this->looksLikeHeaderRepeat($headers, $row)) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    $stats['rows_total']++;

                    if ($limit > 0 && $stats['rows_total'] > $limit) {
                        break;
                    }

                    $tenantName = $this->cell($row, $col, 'tenant_name');
                    $placeCode = $this->cell($row, $col, 'place_code');
                    $placeName = $this->cell($row, $col, 'place_name');
                    $activityType = $this->cell($row, $col, 'activity_type');

                    if ($this->shouldSkipRow($tenantName, $placeCode)) {
                        $stats['rows_skipped']++;
                        continue;
                    }

                    $tenantName = trim($tenantName);
                    $placeCode = trim($placeCode);

                    // Numeric fields
                    $area = $this->parseNumber($this->cell($row, $col, 'area_sqm'));
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

                    $vatRate = $this->inferVatRate($headers, $totalNoVat, $totalWithVat);

                    // Upsert tenant
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

                    // Upsert space (if place code exists)
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
                                $marketSpaceId = DB::table('market_spaces')->insertGetId([
                                    'market_id' => $marketId,
                                    'number' => $placeCode,
                                    'code' => $placeCode,
                                    'area_sqm' => $area > 0 ? $area : null,
                                    'type' => null,
                                    'status' => 'occupied',
                                    'is_active' => 1,
                                    'notes' => $placeName !== '' ? $placeName : null,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                                $stats['spaces_created']++;
                            } else {
                                // Mild update (only if data exists)
                                DB::table('market_spaces')->where('id', $marketSpaceId)->update([
                                    'area_sqm' => $area > 0 ? $area : DB::raw('area_sqm'),
                                    'notes' => $placeName !== '' ? $placeName : DB::raw('notes'),
                                    'updated_at' => $now,
                                ]);
                            }

                            if ($setSpaceTenant) {
                                DB::table('market_spaces')->where('id', $marketSpaceId)->update([
                                    'tenant_id' => $tenantId,
                                    'updated_at' => $now,
                                ]);
                            }

                            $spaceCache[$placeCode] = $marketSpaceId;
                        }
                    }

                    // Idempotent hash per row
                    $hashData = [
                        'market_id' => $marketId,
                        'period' => $period->format('Y-m-d'),
                        'tenant' => $tenantName,
                        'place' => $placeCode,
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
                        'source_row_number' => null,
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
        $this->line("Accruals inserted: {$stats['accruals_inserted']}");
        $this->line("Accruals updated: {$stats['accruals_updated']}");

        return self::SUCCESS;
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

            if (! isset($idx['area_sqm']) && Str::contains($n, ['сданная площадь', 'площадь'])) {
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

            // Two "management fee" columns in your file:
            // "услуги управления" and "услуги управления эл.энергия"
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

        // Ensure missing optional keys exist
        $idx['place_code'] = $idx['place_code'] ?? null;
        $idx['place_name'] = $idx['place_name'] ?? null;
        $idx['activity_type'] = $idx['activity_type'] ?? null;
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

    private function shouldSkipRow(string $tenantName, string $placeCode): bool
    {
        $name = trim($tenantName);
        if ($name === '') {
            return true;
        }

        $ln = mb_strtolower($name);

        // summary rows
        if (Str::contains($ln, ['итого', 'всего', 'свод', 'результат'])) {
            return trim($placeCode) === '';
        }

        return false;
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
        // If the row equals header in first 2-3 key fields, treat as header repeat
        $h0 = isset($headers[0]) ? (string) $headers[0] : '';
        $r0 = isset($row[0]) ? (string) $row[0] : '';
        $h0 = $this->normalizeHeader($h0);
        $r0 = $this->normalizeHeader($r0);

        return $h0 !== '' && $h0 === $r0;
    }
}
