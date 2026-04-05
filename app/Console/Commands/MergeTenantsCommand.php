<?php
# app/Console/Commands/MergeTenantsCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class MergeTenantsCommand extends Command
{
    protected $signature = 'tenants:merge
        {from : ID дубля, который гасим}
        {to : ID канонического арендатора, которого оставляем}
        {--execute : Выполнить изменения (без опции работает dry-run)}
        {--dry-run : Явно dry-run, нельзя вместе с --execute}
        {--preflight-limit=20 : Сколько конфликтных строк показывать в preflight}';

    protected $description = 'Безопасное слияние дублей tenants: dry-run по умолчанию, preflight конфликтов, перенос ссылок в транзакции';

    /**
     * Явный список ссылок на tenant_id, которые переносим.
     *
     * @var list<array{table:string,column:string}>
     */
    private array $referenceTargets = [
        ['table' => 'market_spaces', 'column' => 'tenant_id'],
        ['table' => 'tenant_contracts', 'column' => 'tenant_id'],
        ['table' => 'tenant_contract_mappings', 'column' => 'tenant_id'],
        ['table' => 'tenant_requests', 'column' => 'tenant_id'],
        ['table' => 'tenant_accruals', 'column' => 'tenant_id'],
        ['table' => 'tenant_documents', 'column' => 'tenant_id'],
        ['table' => 'tickets', 'column' => 'tenant_id'],
        ['table' => 'users', 'column' => 'tenant_id'],
        ['table' => 'contract_debts', 'column' => 'tenant_id'],
        ['table' => 'market_space_tenant_histories', 'column' => 'old_tenant_id'],
        ['table' => 'market_space_tenant_histories', 'column' => 'new_tenant_id'],
    ];

    /**
     * @var list<array{table:string,column:string}>
     */
    private array $postMergeReferenceTargets = [
        ['table' => 'market_spaces', 'column' => 'tenant_id'],
        ['table' => 'tenant_contracts', 'column' => 'tenant_id'],
        ['table' => 'tenant_contract_mappings', 'column' => 'tenant_id'],
        ['table' => 'tenant_requests', 'column' => 'tenant_id'],
        ['table' => 'tenant_accruals', 'column' => 'tenant_id'],
        ['table' => 'tenant_documents', 'column' => 'tenant_id'],
        ['table' => 'tickets', 'column' => 'tenant_id'],
        ['table' => 'users', 'column' => 'tenant_id'],
        ['table' => 'contract_debts', 'column' => 'tenant_id'],
        ['table' => 'tenant_showcases', 'column' => 'tenant_id'],
    ];

    /**
     * Политика атрибутов: в каноне не перетираем, только дозаполняем пустые.
     *
     * @var list<string>
     */
    private array $fillEmptyAttributes = [
        'external_id',
        'one_c_uid',
        'inn',
        'kpp',
        'ogrn',
        'email',
        'phone',
        'contact_person',
        'short_name',
        'name',
        'type',
        'status',
    ];

    public function handle(): int
    {
        $fromId = (int) $this->argument('from');
        $toId = (int) $this->argument('to');
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run');
        $preflightLimit = max(1, (int) $this->option('preflight-limit'));

        if ($execute && $dryRun) {
            $this->error('Use either --execute or --dry-run, not both');
            return self::FAILURE;
        }

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            $this->error('Invalid ids');
            return self::FAILURE;
        }

        /** @var Tenant|null $from */
        $from = Tenant::query()->find($fromId);
        /** @var Tenant|null $to */
        $to = Tenant::query()->find($toId);

        if (! $from || ! $to) {
            $this->error('Tenant not found');
            return self::FAILURE;
        }

        if ((int) $from->market_id !== (int) $to->market_id) {
            $this->error('Different market_id: merge is forbidden');
            return self::FAILURE;
        }

        $mode = $execute ? 'EXECUTE' : 'DRY-RUN';

        $this->info("mode={$mode}");
        $this->info("market_id={$from->market_id}");
        $this->line("from={$fromId} name=" . (string) ($from->name ?? '') . " external_id=" . (string) ($from->external_id ?? ''));
        $this->line("to={$toId}   name=" . (string) ($to->name ?? '') . " external_id=" . (string) ($to->external_id ?? ''));

        $counts = $this->collectReferenceCounts($fromId);
        $showcasePlan = $this->buildShowcasePlan($fromId, $toId);
        $attributePlan = $this->buildAttributeMergePlan($from, $to);
        $accrualPreflight = $this->preflightAccrualConflicts($fromId, $toId, $preflightLimit);

        $this->line('References to move:');
        foreach ($counts as $key => $count) {
            $this->line(" - {$key}: {$count}");
        }

        $this->line('Showcase action: ' . $showcasePlan['action']);
        if ($showcasePlan['action'] === 'merge_and_delete') {
            $this->line(' - both tenants have showcase, fields will be merged and duplicate showcase deleted');
        }

        $this->line('Attribute policy (fill empty only):');
        foreach ($attributePlan['messages'] as $message) {
            $this->line(" - {$message}");
        }

        $this->line('Preflight unique conflicts:');
        $this->line(' - tenant_accruals( market_id, period, source_row_hash ): ' . $accrualPreflight['count']);
        if ($accrualPreflight['count'] > 0) {
            foreach ($accrualPreflight['sample'] as $row) {
                $this->line(sprintf(
                    '   * period=%s hash=%s from_row_id=%d to_row_id=%d',
                    (string) $row->period,
                    (string) $row->source_row_hash,
                    (int) $row->from_row_id,
                    (int) $row->to_row_id
                ));
            }
            $this->error('Preflight failed: merge aborted');
            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN: nothing changed (use --execute to apply)');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            $locked = Tenant::query()
                ->whereIn('id', [$fromId, $toId])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Tenant|null $fromLocked */
            $fromLocked = $locked->get($fromId);
            /** @var Tenant|null $toLocked */
            $toLocked = $locked->get($toId);

            if (! $fromLocked || ! $toLocked) {
                throw new RuntimeException('Tenant not found during execute');
            }

            if ((int) $fromLocked->market_id !== (int) $toLocked->market_id) {
                throw new RuntimeException('Different market_id during execute');
            }

            $accrualPreflightRecheck = $this->preflightAccrualConflicts($fromId, $toId, 1);
            if ($accrualPreflightRecheck['count'] > 0) {
                throw new RuntimeException('Preflight conflict detected during execute (tenant_accruals unique key)');
            }

            foreach ($this->referenceTargets as $target) {
                $table = $target['table'];
                $column = $target['column'];

                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)->where($column, $fromId)->update([$column => $toId]);
            }

            $showcasePlan = $this->buildShowcasePlan($fromId, $toId);
            $this->applyShowcasePlan($showcasePlan, $fromId, $toId);

            $attributePlan = $this->buildAttributeMergePlan($fromLocked, $toLocked);
            $this->applyAttributeMergePlan($attributePlan, $fromLocked, $toLocked);

            $this->markMergeNotes($fromLocked, $toLocked);

            $toLocked->save();

            $remainingReferences = $this->collectSpecificReferenceCounts($fromId, $this->postMergeReferenceTargets);
            foreach ($remainingReferences as $key => $count) {
                if ($count > 0) {
                    throw new RuntimeException("Source tenant still has references after merge: {$key}={$count}");
                }
            }

            $fromLocked->delete();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Merge failed, rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('OK: tenants merged');
        return self::SUCCESS;
    }

    /**
     * @return array{count:int,sample:list<object>}
     */
    private function preflightAccrualConflicts(int $fromId, int $toId, int $limit): array
    {
        if (! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'tenant_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')
            || ! Schema::hasColumn('tenant_accruals', 'source_row_hash')
            || ! Schema::hasColumn('tenant_accruals', 'market_id')) {
            return ['count' => 0, 'sample' => []];
        }

        $countSql = "
            SELECT COUNT(*)::int AS c
            FROM tenant_accruals f
            INNER JOIN tenant_accruals t
                ON t.market_id = f.market_id
               AND t.tenant_id = :to_id
               AND f.tenant_id = :from_id
               AND t.period = f.period
               AND t.source_row_hash = f.source_row_hash
            WHERE f.source_row_hash IS NOT NULL
        ";

        $sampleSql = "
            SELECT
                f.period,
                f.source_row_hash,
                f.id AS from_row_id,
                t.id AS to_row_id
            FROM tenant_accruals f
            INNER JOIN tenant_accruals t
                ON t.market_id = f.market_id
               AND t.tenant_id = :to_id
               AND f.tenant_id = :from_id
               AND t.period = f.period
               AND t.source_row_hash = f.source_row_hash
            WHERE f.source_row_hash IS NOT NULL
            ORDER BY f.period, f.source_row_hash, f.id
            LIMIT {$limit}
        ";

        $bindings = [
            'from_id' => $fromId,
            'to_id' => $toId,
        ];

        $countRow = DB::selectOne($countSql, $bindings);
        $sampleRows = DB::select($sampleSql, $bindings);

        return [
            'count' => (int) ($countRow->c ?? 0),
            'sample' => $sampleRows,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function collectReferenceCounts(int $fromId): array
    {
        $counts = $this->collectSpecificReferenceCounts($fromId, $this->referenceTargets);

        if (Schema::hasTable('tenant_showcases') && Schema::hasColumn('tenant_showcases', 'tenant_id')) {
            $counts['tenant_showcases.tenant_id'] = (int) DB::table('tenant_showcases')->where('tenant_id', $fromId)->count();
        }

        return $counts;
    }

    /**
     * @param list<array{table:string,column:string}> $targets
     * @return array<string,int>
     */
    private function collectSpecificReferenceCounts(int $tenantId, array $targets): array
    {
        $counts = [];

        foreach ($targets as $target) {
            $table = $target['table'];
            $column = $target['column'];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $counts["{$table}.{$column}"] = (int) DB::table($table)->where($column, $tenantId)->count();
        }

        return $counts;
    }

    /**
     * @return array{
     *   action:string,
     *   from_row_id:int|null,
     *   to_row_id:int|null,
     *   merge_values:array<string,mixed>
     * }
     */
    private function buildShowcasePlan(int $fromId, int $toId): array
    {
        $empty = [
            'action' => 'none',
            'from_row_id' => null,
            'to_row_id' => null,
            'merge_values' => [],
        ];

        if (! Schema::hasTable('tenant_showcases') || ! Schema::hasColumn('tenant_showcases', 'tenant_id')) {
            return $empty;
        }

        $from = DB::table('tenant_showcases')->where('tenant_id', $fromId)->first();
        $to = DB::table('tenant_showcases')->where('tenant_id', $toId)->first();

        if (! $from) {
            return $empty;
        }

        if (! $to) {
            return [
                'action' => 'reassign',
                'from_row_id' => (int) $from->id,
                'to_row_id' => null,
                'merge_values' => [],
            ];
        }

        return [
            'action' => 'merge_and_delete',
            'from_row_id' => (int) $from->id,
            'to_row_id' => (int) $to->id,
            'merge_values' => [
                'title' => $this->preferFilled($to->title ?? null, $from->title ?? null),
                'description' => $this->preferFilled($to->description ?? null, $from->description ?? null),
                'phone' => $this->preferFilled($to->phone ?? null, $from->phone ?? null),
                'telegram' => $this->preferFilled($to->telegram ?? null, $from->telegram ?? null),
                'website' => $this->preferFilled($to->website ?? null, $from->website ?? null),
                'photos' => $this->mergePhotos($to->photos ?? null, $from->photos ?? null),
                'updated_at' => now(),
            ],
        ];
    }

    /**
     * @param array{
     *   action:string,
     *   from_row_id:int|null,
     *   to_row_id:int|null,
     *   merge_values:array<string,mixed>
     * } $showcasePlan
     */
    private function applyShowcasePlan(array $showcasePlan, int $fromId, int $toId): void
    {
        if (! Schema::hasTable('tenant_showcases') || ! Schema::hasColumn('tenant_showcases', 'tenant_id')) {
            return;
        }

        if ($showcasePlan['action'] === 'reassign') {
            DB::table('tenant_showcases')->where('tenant_id', $fromId)->update([
                'tenant_id' => $toId,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($showcasePlan['action'] === 'merge_and_delete') {
            $toRowId = $showcasePlan['to_row_id'];
            $fromRowId = $showcasePlan['from_row_id'];

            if ($toRowId !== null) {
                DB::table('tenant_showcases')->where('id', $toRowId)->update($showcasePlan['merge_values']);
            }

            if ($fromRowId !== null) {
                DB::table('tenant_showcases')->where('id', $fromRowId)->delete();
            }
        }
    }

    /**
     * @return array{
     *   transfers:array<string,mixed>,
     *   clear_on_from:list<string>,
     *   messages:list<string>
     * }
     */
    private function buildAttributeMergePlan(Tenant $from, Tenant $to): array
    {
        $transfers = [];
        $clearOnFrom = [];
        $messages = [];

        foreach ($this->fillEmptyAttributes as $field) {
            if (! Schema::hasColumn('tenants', $field)) {
                continue;
            }

            $fromValue = $this->normalizeScalar($from->{$field});
            $toValue = $this->normalizeScalar($to->{$field});

            if ($fromValue === null) {
                $messages[] = "{$field}: source empty";
                continue;
            }

            if ($toValue === null) {
                $transfers[$field] = $fromValue;
                $messages[] = "{$field}: fill canonical from duplicate";

                if (in_array($field, ['external_id', 'one_c_uid'], true)) {
                    $clearOnFrom[] = $field;
                }

                continue;
            }

            if ((string) $toValue === (string) $fromValue) {
                $messages[] = "{$field}: same value";
                continue;
            }

            $messages[] = "{$field}: conflict, keep canonical";
        }

        return [
            'transfers' => $transfers,
            'clear_on_from' => $clearOnFrom,
            'messages' => $messages,
        ];
    }

    /**
     * @param array{
     *   transfers:array<string,mixed>,
     *   clear_on_from:list<string>,
     *   messages:list<string>
     * } $plan
     */
    private function applyAttributeMergePlan(array $plan, Tenant $from, Tenant $to): void
    {
        foreach ($plan['transfers'] as $field => $value) {
            $to->{$field} = $value;
        }

        foreach ($plan['clear_on_from'] as $field) {
            $from->{$field} = null;
        }
    }

    private function markMergeNotes(Tenant $from, Tenant $to): void
    {
        $stamp = now()->toDateTimeString();

        if (Schema::hasColumn('tenants', 'is_active')) {
            $shouldKeepCanonicalActive = (bool) ($from->is_active || $to->is_active);
            $from->is_active = false;
            $to->is_active = $shouldKeepCanonicalActive;
        }

        if (Schema::hasColumn('tenants', 'notes')) {
            $toNote = trim((string) ($to->notes ?? ''));

            $toSuffix = "merged_from_tenant_id={$from->id} at {$stamp}";
            $toMarker = "merged_from_tenant_id={$from->id}";

            if (! str_contains($toNote, $toMarker)) {
                $to->notes = $toNote === '' ? $toSuffix : ($toNote . "\n" . $toSuffix);
            }
        }
    }

    private function preferFilled(mixed $preferred, mixed $fallback): mixed
    {
        $preferredText = trim((string) ($preferred ?? ''));

        if ($preferredText !== '') {
            return $preferred;
        }

        return $fallback;
    }

    private function mergePhotos(mixed $toPhotos, mixed $fromPhotos): ?string
    {
        $toArray = $this->decodeJsonArray($toPhotos);
        $fromArray = $this->decodeJsonArray($fromPhotos);

        $merged = array_values(array_unique(array_merge($toArray, $fromArray)));

        if ($merged === []) {
            return null;
        }

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
    }

    /**
     * @return list<string>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, static fn ($v) => is_string($v) && trim($v) !== ''));
            }
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && trim($v) !== ''));
        }

        return [];
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }
}
