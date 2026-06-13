<?php

// app/Filament/Resources/MarketSpaceResource.php

namespace App\Filament\Resources;

use App\Filament\Pages\OneCSettlements;
use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Services\Debt\DebtStatusResolver;
use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationsStateService;
use App\Support\MarketSpaces\MarketSpaceShapePolicy;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;

class MarketSpaceResource extends BaseResource
{
    protected static ?string $model = MarketSpace::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Торговое место';

    protected static ?string $pluralModelLabel = 'Торговые места';

    protected static ?string $navigationLabel = 'Торговые места';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home-modern';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    /**
     * Resolve label for MarketSpace.type via market_space_types (market_id + code).
     */
    protected static function resolveSpaceTypeLabel(?int $marketId, ?string $typeCode): ?string
    {
        if (blank($marketId) || blank($typeCode)) {
            return null;
        }

        static $cache = []; // [marketId => [code => name_ru]]

        if (! isset($cache[$marketId])) {
            $cache[$marketId] = MarketSpaceType::query()
                ->where('market_id', (int) $marketId)
                ->pluck('name_ru', 'code')
                ->all();
        }

        return $cache[$marketId][$typeCode] ?? $typeCode;
    }

    protected static function resolveSpaceTypeOptions(?int $marketId, ?string $currentTypeCode = null): array
    {
        if (blank($marketId)) {
            return [];
        }

        $options = MarketSpaceType::query()
            ->where('market_id', (int) $marketId)
            ->where('is_active', true)
            ->orderBy('name_ru')
            ->pluck('name_ru', 'code')
            ->all();

        if (filled($currentTypeCode) && ! isset($options[$currentTypeCode])) {
            $options[$currentTypeCode] = 'Старое значение (код '.$currentTypeCode.')';
        }

        return $options;
    }

    /**
     * Canonical statuses for UI (legacy "free" => "vacant").
     */
    protected static function normalizeStatus(?string $state): ?string
    {
        if ($state === 'free') {
            return 'vacant';
        }

        return $state;
    }

    protected static function statusLabel(?string $state): ?string
    {
        $state = static::normalizeStatus($state);

        return match ($state) {
            'vacant' => 'Свободно',
            'occupied' => 'Занято',
            'reserved' => 'Зарезервировано',
            'maintenance' => 'Служебное место',
            default => $state,
        };
    }

    /**
     * Color mapping for badge() in table.
     * Требование: Занято = зелёный, Свободно = красный.
     */
    protected static function statusColor(?string $state): string
    {
        $state = static::normalizeStatus($state);

        return match ($state) {
            'occupied' => 'success',
            'vacant' => 'danger',
            'reserved' => 'warning',
            'maintenance' => 'gray',
            default => 'gray',
        };
    }

    private static function renderEffectiveOccupancy(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Появится после сохранения торгового места.</div>');
        }

        $source = $record->effectiveOccupancySource();
        $tenantName = $record->effectiveTenantName();
        $sourceSpace = $record->effectiveOccupancySourceSpace();

        if ($source === 'none' || ! $sourceSpace instanceof MarketSpace) {
            return new HtmlString(
                '<div style="display:grid;gap:4px;">'
                .'<div style="font-size:13px;font-weight:700;color:#0f172a;">Свободно</div>'
                .'<div style="font-size:13px;opacity:.88;">Арендатор не назначен</div>'
                .'<div style="font-size:12px;opacity:.7;">Место пока не занято</div>'
                .'</div>'
            );
        }

        $sourceLabel = trim((string) ($sourceSpace->number ?? ''));
        if ($sourceLabel === '') {
            $sourceLabel = trim((string) ($sourceSpace->code ?? ''));
        }
        if ($sourceLabel === '') {
            $sourceLabel = trim((string) ($sourceSpace->display_name ?? ''));
        }
        if ($sourceLabel === '') {
            $sourceLabel = '#'.(int) $sourceSpace->id;
        }

        $tenantLabel = $tenantName ?: '—';
        $title = 'Занято';
        $sourceLabelText = $source === 'parent'
            ? 'Арендатор наследуется от группы.'
            : 'Арендатор указан у этого места.';

        $html = '<div style="display:grid;gap:4px;">'
            .'<div style="font-size:13px;font-weight:700;color:#0f172a;">'.e($title).'</div>'
            .'<div style="font-size:13px;opacity:.88;">Арендатор: '.e($tenantLabel).'</div>'
            .'<div style="font-size:12px;opacity:.7;">'.e($sourceLabelText).'</div>'
            .'</div>';

        return new HtmlString($html);
    }

    private static function renderChildInheritanceNotice(?MarketSpace $record): ?HtmlString
    {
        if (! static::isChildWithParent($record)) {
            return null;
        }

        $parent = $record?->spaceGroupParent;
        $parentLabel = trim((string) ($parent?->number ?? ''));

        if ($parentLabel === '') {
            $parentLabel = trim((string) ($parent?->display_name ?? ''));
        }

        if ($parentLabel === '' && $parent instanceof MarketSpace) {
            $parentLabel = '#'.(int) $parent->id;
        }

        if ($parentLabel === '') {
            $parentLabel = '#'.(int) ($record?->space_group_parent_id ?? 0);
        }

        $slot = trim((string) ($record?->space_group_slot ?? ''));
        $tenantName = trim((string) ($record?->effectiveTenantName() ?? ''));
        $parentLocationName = trim((string) ($parent?->location?->name ?? ''));

        $parentUrl = $parent instanceof MarketSpace
            ? static::getUrl('edit', ['record' => $parent])
            : null;

        $items = [
            'Родительская группа' => $parentLabel,
            'Номер в группе' => $slot !== '' ? $slot : '—',
            'Фактический арендатор' => $tenantName !== '' ? $tenantName : 'Не назначен',
        ];

        if ($parentLocationName !== '') {
            $items['Локация группы'] = $parentLocationName;
        }

        $rows = '';

        foreach ($items as $label => $value) {
            $rows .= '<div style="display:grid;grid-template-columns:minmax(130px,0.42fr) minmax(0,1fr);gap:8px;align-items:start;">'
                .'<div style="font-size:12px;font-weight:700;color:#64748b;">'.e($label).'</div>'
                .'<div style="font-size:13px;font-weight:700;color:#0f172a;">'.e($value).'</div>'
                .'</div>';
        }

        $openParentButton = $parentUrl
            ? '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
                .'<a href="'.e($parentUrl).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#2563eb;color:#fff;font-size:12px;font-weight:800;padding:8px 12px;text-decoration:none;">Открыть карточку группы</a>'
                .'</div>'
            : '';

        return new HtmlString(
            '<div style="display:grid;gap:10px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e293b;">'
            .'<div style="font-size:13px;font-weight:800;color:#1d4ed8;">Это место входит в группу</div>'
            .$rows
            .$openParentButton
            .'<div style="font-size:12px;line-height:1.45;color:#475569;">Родительская группа и номер внутри группы меняются через действие «Перенести в группу», а не через обычные поля карточки.</div>'
            .'</div>'
        );
    }

    public static function hasSharedUseTenants(?MarketSpace $record): bool
    {
        return static::sharedUseTenantRows($record) !== [];
    }

    public static function sharedUseCanonicalSpaceForSource(?MarketSpace $record): ?MarketSpace
    {
        if (! filled($record?->id) || ! filled($record?->market_id) || ! SchemaFacade::hasTable('market_space_tenant_bindings')) {
            return null;
        }

        $sourceSpaceId = (int) $record->id;
        static $canonicalSpaceCache = [];
        $cacheKey = (int) $record->market_id.':'.$sourceSpaceId;

        if (array_key_exists($cacheKey, $canonicalSpaceCache)) {
            return $canonicalSpaceCache[$cacheKey];
        }

        $bindings = DB::table('market_space_tenant_bindings as b')
            ->where('b.market_id', (int) $record->market_id)
            ->where('b.binding_type', 'shared_use')
            ->whereNull('b.ended_at')
            ->orderBy('b.id')
            ->get(['b.market_space_id', 'b.meta']);

        foreach ($bindings as $binding) {
            $canonicalSpaceId = (int) ($binding->market_space_id ?? 0);
            if ($canonicalSpaceId <= 0 || $canonicalSpaceId === $sourceSpaceId) {
                continue;
            }

            $meta = static::sharedUseBindingMeta($binding->meta ?? null);
            $sourceSpaceIds = static::sharedUseSourceSpaceIdsFromMeta($meta);

            if ($sourceSpaceIds !== [] && in_array($sourceSpaceId, $sourceSpaceIds, true)) {
                $canonicalSpace = MarketSpace::query()->find($canonicalSpaceId);

                if ($canonicalSpace instanceof MarketSpace) {
                    return $canonicalSpace;
                }
            }
        }

        return $canonicalSpaceCache[$cacheKey] = null;
    }

    public static function isSharedUseSourceSpace(?MarketSpace $record): bool
    {
        return static::sharedUseCanonicalSpaceForSource($record) instanceof MarketSpace;
    }

    private static function sharedUseTenantRows(?MarketSpace $record): array
    {
        if (! filled($record?->id) || ! SchemaFacade::hasTable('market_space_tenant_bindings')) {
            return [];
        }

        return DB::table('market_space_tenant_bindings as b')
            ->leftJoin('tenants as t', 't.id', '=', 'b.tenant_id')
            ->where('b.market_space_id', (int) $record->id)
            ->where('b.binding_type', 'shared_use')
            ->whereNull('b.ended_at')
            ->orderBy('t.name')
            ->orderBy('b.tenant_id')
            ->get([
                'b.tenant_id',
                't.name as tenant_name',
                't.short_name as tenant_short_name',
                'b.started_at',
                'b.area_sqm',
                'b.rent_rate',
                'b.share_note',
                'b.source',
            ])
            ->map(function ($row): array {
                $shortName = trim((string) ($row->tenant_short_name ?? ''));
                $name = trim((string) ($row->tenant_name ?? ''));
                $tenantName = $shortName !== '' ? $shortName : $name;

                return [
                    'tenant_id' => $row->tenant_id ? (int) $row->tenant_id : null,
                    'tenant_name' => $tenantName !== '' ? $tenantName : 'Арендатор',
                    'started_at' => $row->started_at ? (string) $row->started_at : null,
                    'area_sqm' => $row->area_sqm !== null ? (float) $row->area_sqm : null,
                    'rent_rate' => $row->rent_rate !== null ? (float) $row->rent_rate : null,
                    'share_note' => static::sanitizeSharedUseNote((string) ($row->share_note ?? '')),
                    'source' => trim((string) ($row->source ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    private static function sharedUseBindingMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (! is_string($meta) || trim($meta) === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<int>
     */
    private static function sharedUseSourceSpaceIdsFromMeta(array $meta): array
    {
        $ids = [];

        foreach ([
            $meta['source_space_ids'] ?? [],
            data_get($meta, 'sklad21_shared_use.source_space_ids', []),
        ] as $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $sourceSpaceId) {
                if (! is_numeric($sourceSpaceId)) {
                    continue;
                }

                $ids[(int) $sourceSpaceId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private static function sanitizeSharedUseNote(string $note): string
    {
        $note = trim($note);

        if ($note === '') {
            return '';
        }

        $note = preg_replace('/(?:^|[;,.]\s*)источники?:\s*[^;,.]+/iu', '', $note) ?? $note;
        $note = preg_replace('/\s{2,}/u', ' ', $note) ?? $note;
        $note = preg_replace('/\s*;\s*;\s*/u', '; ', $note) ?? $note;
        $note = preg_replace('/^[;,\s]+|[;,\s]+$/u', '', $note) ?? $note;

        return trim($note);
    }

    private static function renderSharedUseTenantsNotice(?MarketSpace $record): HtmlString
    {
        $rows = static::sharedUseTenantRows($record);

        if ($rows === []) {
            return new HtmlString('');
        }

        $items = '';

        foreach ($rows as $row) {
            $meta = [];
            if ($row['area_sqm'] !== null) {
                $area = number_format((float) $row['area_sqm'], 2, ',', ' ');
                $area = rtrim(rtrim($area, '0'), ',');
                $meta[] = 'площадь: '.$area.' м²';
            }
            if ($row['rent_rate'] !== null) {
                $rate = number_format((float) $row['rent_rate'], 2, ',', ' ');
                $rate = rtrim(rtrim($rate, '0'), ',');
                $meta[] = 'ставка: '.$rate.' ₽';
            }
            if (! empty($row['started_at'])) {
                $meta[] = 'с '.\Carbon\Carbon::parse($row['started_at'])->format('d.m.Y');
            }
            $note = $row['share_note'] !== ''
                ? '<div style="font-size:11px;line-height:1.35;color:#64748b;">'.e((string) $row['share_note']).'</div>'
                : '';

            $items .= '<li style="display:grid;gap:2px;padding:7px 0;border-top:1px solid rgba(37,99,235,.14);">'
                .'<div style="font-size:13px;font-weight:800;color:#0f172a;">'.e((string) $row['tenant_name']).'</div>'
                .($meta !== [] ? '<div style="font-size:12px;color:#475569;">'.e(implode(' · ', $meta)).'</div>' : '')
                .$note
                .'</li>';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #93c5fd;border-radius:12px;background:#eff6ff;color:#1e293b;">'
            .'<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">'
            .'<div style="font-size:13px;font-weight:900;color:#1d4ed8;">Участники совместного использования</div>'
            .'<div style="font-size:12px;color:#475569;">Площадь и состав управляются отдельно по каждому участнику.</div>'
            .'</div>'
            .'<ul style="display:grid;gap:0;margin:0;padding:0;list-style:none;">'.$items.'</ul>'
            .'</div>'
        );
    }

    private static function renderSharedUseSourceSpaceNotice(?MarketSpace $record): HtmlString
    {
        $canonicalSpace = static::sharedUseCanonicalSpaceForSource($record);

        if (! $canonicalSpace instanceof MarketSpace) {
            return new HtmlString('');
        }

        $canonicalLabel = trim((string) ($canonicalSpace->number ?? ''));
        if ($canonicalLabel === '') {
            $canonicalLabel = trim((string) ($canonicalSpace->display_name ?? ''));
        }
        if ($canonicalLabel === '') {
            $canonicalLabel = '#'.(int) $canonicalSpace->id;
        }

        $canonicalUrl = static::getUrl('edit', ['record' => $canonicalSpace]);

        return new HtmlString(
            '<div style="display:grid;gap:10px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e293b;">'
            .'<div style="font-size:13px;font-weight:800;color:#1d4ed8;">Это служебная запись участника совместного использования</div>'
            .'<div style="font-size:12px;line-height:1.45;color:#475569;">Управляйте участниками в карточке основного места. Основное место: <a href="'.e($canonicalUrl).'" target="_blank" rel="noopener" style="color:#1d4ed8;font-weight:800;text-decoration:underline;">'.e($canonicalLabel).'</a>.</div>'
            .'<div><a href="'.e($canonicalUrl).'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#2563eb;color:#fff;font-size:12px;font-weight:800;padding:8px 12px;text-decoration:none;">Открыть основное место</a></div>'
            .'</div>'
        );
    }

    private static function renderSharedUseReferenceArea(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return new HtmlString('');
        }

        $area = 'Не указана';

        if ($record->area_sqm !== null) {
            $formatted = number_format((float) $record->area_sqm, 2, ',', ' ');
            $formatted = rtrim(rtrim($formatted, '0'), ',');
            $area = $formatted.' м²';
        }

        return new HtmlString(
            '<div style="display:grid;gap:6px;padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px;background:#f8fafc;color:#1e293b;width:min(100%,22rem);">'
            .'<div style="font-size:12px;font-weight:800;color:#64748b;">Справочная площадь физического места, м²</div>'
            .'<div style="font-size:16px;font-weight:800;color:#0f172a;">'.e($area).'</div>'
            .'<div style="font-size:12px;line-height:1.45;color:#475569;">Справочное поле карточки. Рабочая площадь задаётся у участников.</div>'
            .'</div>'
        );
    }

    public static function activeMapShapeCountForRecord(?MarketSpace $record): int
    {
        if (! filled($record?->id) || ! SchemaFacade::hasTable('market_space_map_shapes')) {
            return 0;
        }

        $query = MarketSpaceMapShape::query()
            ->where('market_space_id', (int) $record->id);

        if (SchemaFacade::hasColumn('market_space_map_shapes', 'is_active')) {
            $query->where('is_active', true);
        }

        return (int) $query->count();
    }

    public static function requiresParentGroupMapShapeResolution(?MarketSpace $record, ?string $selectedRole): bool
    {
        return filled($record?->id)
            && (string) $selectedRole === MarketSpace::SPACE_GROUP_ROLE_PARENT
            && static::activeMapShapeCountForRecord($record) > 0;
    }

    private static function renderParentGroupMapShapeWarning(?MarketSpace $record): ?HtmlString
    {
        $shapeCount = static::activeMapShapeCountForRecord($record);

        if ($shapeCount <= 0) {
            return null;
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #f59e0b;border-radius:12px;background:#fffbeb;color:#92400e;">'
            .'<div style="font-size:13px;font-weight:800;">'.e('У этого места есть активная фигура карты').'</div>'
            .'<div style="font-size:12px;line-height:1.45;">'.e('Parent-группа не должна иметь обычную фигуру карты. Выберите, что сделать с фигурой при сохранении.').'</div>'
            .'<div style="font-size:12px;line-height:1.45;">'.e('Активных фигур: '.$shapeCount).'</div>'
            .'</div>'
        );
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'number',
            'space_group_token',
            'space_group_slot',
            'display_name',
            'activity_type',
            'location.name',
            'tenant.name',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var MarketSpace $record */
        return static::compactGlobalSearchTitle(
            trim((string) ($record->number ?? '')),
            trim((string) ($record->display_name ?? '')),
            'Торговое место'
        );
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var MarketSpace $record */
        return static::compactGlobalSearchDetails([
            'Локация' => trim((string) ($record->location?->name ?? '')),
            'Арендатор' => trim((string) ($record->tenant?->name ?? '')),
            'Статус' => static::statusLabel($record->status),
            'Тип деятельности' => trim((string) ($record->activity_type ?? '')),
        ]);
    }

    private static function isChildWithParent(?MarketSpace $record): bool
    {
        return $record instanceof MarketSpace
            && (string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD
            && filled($record->space_group_parent_id);
    }

    private static function isExistingChild(?MarketSpace $record): bool
    {
        return $record instanceof MarketSpace
            && filled($record->id)
            && (string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD;
    }

    private static function canDirectlyFillMissingIdentityField(?MarketSpace $record, string $field): bool
    {
        if (! static::isExistingChild($record)) {
            return false;
        }

        return blank($record?->{$field});
    }

    private static function renderEffectiveTenantField(?MarketSpace $record): HtmlString
    {
        if (! static::isChildWithParent($record)) {
            return new HtmlString('—');
        }

        $tenantName = trim((string) ($record?->effectiveTenantName() ?? ''));
        $sourceSpace = $record?->effectiveOccupancySourceSpace();
        $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

        if ($sourceLabel === '') {
            $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
        }

        if ($sourceLabel === '') {
            $sourceLabel = '#'.(int) ($sourceSpace?->id ?? 0);
        }

        return new HtmlString(
            '<div style="display:grid;gap:4px;">'
            .'<div style="font-size:13px;font-weight:600;color:#0f172a;">'.e($tenantName !== '' ? $tenantName : '—').'</div>'
            .'<div style="font-size:12px;opacity:.7;">Наследуется от группы '.e($sourceLabel).'</div>'
            .'</div>'
        );
    }

    private static function renderCurrentParentGroupField(?MarketSpace $record): HtmlString
    {
        if (! static::isExistingChild($record)) {
            return new HtmlString('—');
        }

        $parent = $record?->spaceGroupParent;
        $label = trim((string) ($parent?->number ?? ''));

        if ($label === '') {
            $label = trim((string) ($parent?->display_name ?? ''));
        }

        if ($label === '' && $parent instanceof MarketSpace) {
            $label = '#'.(int) $parent->id;
        }

        if ($label === '') {
            $label = '—';
        }

        return new HtmlString(
            '<div style="display:grid;gap:4px;">'
            .'<div style="font-size:14px;font-weight:600;color:#0f172a;">'.e($label).'</div>'
            .'<div style="font-size:12px;opacity:.7;">Изменяется через действие «Перенести в группу» в шапке карточки.</div>'
            .'</div>'
        );
    }

    private static function renderCurrentGroupSlotField(?MarketSpace $record): HtmlString
    {
        if (! static::isExistingChild($record)) {
            return new HtmlString('—');
        }

        $slot = trim((string) ($record?->space_group_slot ?? ''));
        if ($slot === '') {
            $slot = '—';
        }

        return new HtmlString(
            '<div style="display:grid;gap:4px;">'
            .'<div style="font-size:14px;font-weight:600;color:#0f172a;">'.e($slot).'</div>'
            .'<div style="font-size:12px;opacity:.7;">Изменяется через действие «Перенести в группу» в шапке карточки.</div>'
            .'</div>'
        );
    }

    private static function renderPriorityCard(string $label, string $value, ?string $note = null, string $tone = 'default'): HtmlString
    {
        $noteHtml = filled($note)
            ? '<div class="market-space-priority-card__note">'.e($note).'</div>'
            : '';

        return new HtmlString(
            '<div class="market-space-priority-card market-space-priority-card--'.e($tone).'">'
            .'<div class="market-space-priority-card__label">'.e($label).'</div>'
            .'<div class="market-space-priority-card__value">'.e($value).'</div>'
            .$noteHtml
            .'</div>'
        );
    }

    private static function renderPriorityNumberCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Номер', 'Появится после сохранения');
        }

        $number = trim((string) ($record->number ?? ''));
        $displayName = trim((string) ($record->display_name ?? ''));

        return static::renderPriorityCard(
            'Номер',
            $number !== '' ? $number : 'Не указан',
            $displayName !== '' ? $displayName : 'Основной идентификатор места'
        );
    }

    private static function renderPriorityGroupCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Группа', 'Пока не определена');
        }

        if (static::isChildWithParent($record)) {
            $parent = $record->spaceGroupParent;
            $parentLabel = trim((string) ($parent?->number ?? ''));

            if ($parentLabel === '') {
                $parentLabel = trim((string) ($parent?->display_name ?? ''));
            }

            if ($parentLabel === '') {
                $parentLabel = '#'.(int) ($record->space_group_parent_id ?? 0);
            }

            $slot = trim((string) ($record->space_group_slot ?? ''));
            $note = $slot !== ''
                ? 'Место внутри группы, позиция '.$slot
                : 'Место входит в группу';

            return static::renderPriorityCard('Группа', $parentLabel, $note);
        }

        if ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();

            $note = $childrenCount > 0
                ? 'Группа объединяет '.$childrenCount.' мест'
                : 'Группа создана, но места пока не добавлены';

            return static::renderPriorityCard('Группа', 'Группа мест', $note);
        }

        return static::renderPriorityCard('Группа', 'Отдельное место', 'Не входит в группу');
    }

    private static function renderPriorityTenantCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Арендатор', 'Появится после сохранения');
        }

        if ((string) ($record->status ?? '') === 'maintenance') {
            return static::renderPriorityCard('Назначение', 'Управляющая компания', 'Служебное место', 'default');
        }

        $tenantName = trim((string) ($record->effectiveTenantName() ?? ''));

        if ($tenantName === '') {
            return static::renderPriorityCard('Арендатор', 'Не назначен', 'Сейчас место свободно', 'vacant');
        }

        $source = $record->effectiveOccupancySource();
        $note = 'Указан на этом месте';

        if ($source === 'parent') {
            $sourceSpace = $record->effectiveOccupancySourceSpace();
            $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

            if ($sourceLabel === '') {
                $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
            }

            if ($sourceLabel === '') {
                $sourceLabel = '#'.(int) ($sourceSpace?->id ?? 0);
            }

            $note = 'Наследуется от группы '.$sourceLabel;
        }

        return static::renderPriorityCard('Арендатор', $tenantName, $note, 'occupied');
    }

    private static function renderPriorityAvailabilityCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Свободно / занято', 'Появится после сохранения');
        }

        if ((string) ($record->status ?? '') === 'maintenance') {
            return static::renderPriorityCard('Свободно / занято', 'Служебное место', 'В распоряжении управляющей компании', 'default');
        }

        $tenantName = trim((string) ($record->effectiveTenantName() ?? ''));

        if ($tenantName === '') {
            return static::renderPriorityCard('Свободно / занято', 'Свободно', 'Арендатор не назначен', 'vacant');
        }

        return static::renderPriorityCard('Свободно / занято', 'Занято', 'Сейчас место используется арендатором', 'occupied');
    }

    private static function renderPriorityAreaCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace || $record->area_sqm === null) {
            return static::renderPriorityCard('Площадь', 'Не указана', 'Нужна для расчётов и отчётов');
        }

        $formatted = number_format((float) $record->area_sqm, 2, ',', ' ');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return static::renderPriorityCard('Площадь', $formatted.' м²', 'Используется в ставке и аналитике');
    }

    private static function renderPriorityRentCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Ставка', 'Появится после сохранения');
        }

        $unitLabel = filled($record->rent_rate_unit)
            ? static::rentRateUnitLabel((string) $record->rent_rate_unit)
            : null;

        $currentRate = $record->rent_rate_value !== null
            ? number_format((float) $record->rent_rate_value, 2, ',', ' ').' ₽'
            : null;

        $factRate = null;
        $period = static::resolveOperationPeriod($record);
        $resolvedFactRate = static::resolveRentRateFact($record, $period);

        if ($resolvedFactRate !== null) {
            $factRate = number_format($resolvedFactRate, 2, ',', ' ').' ₽';
        }

        $value = $currentRate ?? $factRate ?? 'Не задана';
        $noteParts = [];

        if ($unitLabel) {
            $noteParts[] = $unitLabel;
        }

        if ($factRate !== null && $currentRate !== null && $factRate !== $currentRate) {
            $noteParts[] = 'Факт за период: '.$factRate;
        } elseif ($factRate !== null && $currentRate === null) {
            $noteParts[] = 'Факт за период';
        }

        if ($currentRate === null && $factRate === null) {
            $noteParts[] = 'Ставка ещё не заполнена';
        }

        return static::renderPriorityCard('Ставка', $value, implode(' • ', $noteParts));
    }

    private static function renderPrioritySummaryItem(string $label, string $value, ?string $meta = null, string $tone = 'default', ?string $actionHtml = null): string
    {
        $metaHtml = filled($meta)
            ? '<div class="market-space-priority-summary__meta">'.e($meta).'</div>'
            : '';

        return '<div class="market-space-priority-summary__item market-space-priority-summary__item--'.e($tone).'">'
            .($actionHtml ?? '')
            .'<div class="market-space-priority-summary__label">'.e($label).'</div>'
            .'<div class="market-space-priority-summary__value">'.e($value).'</div>'
            .$metaHtml
            .'</div>';
    }

    private static function renderPrioritySummary(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return new HtmlString(
                '<div class="market-space-priority-summary">'
                .static::renderPrioritySummaryItem('Номер', 'Появится после сохранения')
                .'</div>'
            );
        }

        $groupValue = 'Не состоит в группе';
        $groupMeta = 'Можно использовать как самостоятельное место';

        if ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD && $record->spaceGroupParent) {
            $parent = $record->spaceGroupParent;
            $parentLabel = trim((string) ($parent?->number ?? ''));

            if ($parentLabel === '') {
                $parentLabel = trim((string) ($parent?->display_name ?? ''));
            }

            if ($parentLabel === '') {
                $parentLabel = '#'.(int) ($record->space_group_parent_id ?? 0);
            }

            $groupValue = $parentLabel;
            $slot = trim((string) ($record->space_group_slot ?? ''));
            $groupMeta = $slot !== '' ? 'Место внутри группы, позиция '.$slot : 'Место входит в группу';
        } elseif ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();
            $groupValue = 'Группа мест';
            $groupMeta = $childrenCount > 0 ? 'Объединяет '.$childrenCount.' мест' : 'Группа пока пустая';
        }

        $sharedUseRows = static::sharedUseTenantRows($record);
        $hasSharedUseTenants = $sharedUseRows !== [];
        $isSharedUseSourceSpace = static::isSharedUseSourceSpace($record);
        $isMaintenance = (string) ($record->status ?? '') === 'maintenance';
        $sharedUseTenantCount = count($sharedUseRows);
        $sharedUseAreaSum = array_sum(array_map(
            static fn (array $row): float => $row['area_sqm'] !== null ? (float) $row['area_sqm'] : 0.0,
            $sharedUseRows,
        ));

        $tenantName = trim((string) ($record->effectiveTenantName() ?? ''));
        $tenantValue = $tenantName !== '' ? $tenantName : 'Не назначен';
        $tenantMeta = $tenantName === '' ? 'Сейчас место свободно' : 'Указан на этом месте';
        $tenantTone = $tenantName === '' ? 'vacant' : 'occupied';
        $tenantLabel = 'Арендатор';
        $tenantActionHtml = '<button type="button" class="market-space-priority-summary__action" wire:click="mountAction(\'switch_tenant\')" title="Сменить арендатора" aria-label="Сменить арендатора">'
            .'<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-8.9 8.9a2 2 0 0 1-.878.513l-2.5.714a.75.75 0 0 1-.927-.927l.714-2.5a2 2 0 0 1 .513-.878l8.9-8.9ZM12.525 5.707 5.533 12.7a.5.5 0 0 0-.128.22l-.425 1.49 1.49-.425a.5.5 0 0 0 .22-.128l6.992-6.992-1.157-1.157Z"/></svg>'
            .'</button>';

        if ($isMaintenance) {
            $tenantLabel = 'Назначение';
            $tenantValue = 'Управляющая компания';
            $tenantMeta = 'Служебное место';
            $tenantTone = 'default';
            $tenantActionHtml = '';
        }

        if ($hasSharedUseTenants) {
            $tenantWord = match (true) {
                $sharedUseTenantCount % 10 === 1 && $sharedUseTenantCount % 100 !== 11 => 'участник',
                in_array($sharedUseTenantCount % 10, [2, 3, 4], true)
                    && ! in_array($sharedUseTenantCount % 100, [12, 13, 14], true) => 'участника',
                default => 'участников',
            };

            $tenantLabel = 'Участники';
            $tenantValue = $sharedUseTenantCount.' '.$tenantWord;
            $tenantMeta = 'Площадь и состав управляются отдельно';
            $tenantTone = 'occupied';
            $tenantActionHtml = '';
        }

        if ($isSharedUseSourceSpace) {
            $tenantActionHtml = '';
        }

        if ($tenantName !== '' && $record->effectiveOccupancySource() === 'parent') {
            $sourceSpace = $record->effectiveOccupancySourceSpace();
            $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

            if ($sourceLabel === '') {
                $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
            }

            if ($sourceLabel === '') {
                $sourceLabel = '#'.(int) ($sourceSpace?->id ?? 0);
            }

            $tenantMeta = 'Наследуется от группы '.$sourceLabel;
            $tenantLabel = 'Арендатор';
        }

        if ($hasSharedUseTenants) {
            $tenantActionHtml = '<button type="button" class="market-space-priority-summary__action" wire:click="mountAction(\'manage_shared_use\')" title="Управлять участниками" aria-label="Управлять участниками">'
                .'<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3.75a3.25 3.25 0 1 1 0 6.5 3.25 3.25 0 0 1 0-6.5ZM4.75 5.5a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5Zm10.5 0a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5ZM10 11.5c-2.65 0-4.75 1.49-4.75 3.25 0 .41.34.75.75.75h8c.41 0 .75-.34.75-.75 0-1.76-2.1-3.25-4.75-3.25Zm-5.25.75c-1.76 0-3.25.95-3.25 2.25 0 .41.34.75.75.75h1.88c.1-1.17.8-2.2 1.88-2.9a4.89 4.89 0 0 0-1.26-.1Zm10.5 0c-.43 0-.85.04-1.25.12 1.06.7 1.76 1.72 1.86 2.88h1.89c.41 0 .75-.34.75-.75 0-1.3-1.5-2.25-3.25-2.25Z"/></svg>'
                .'</button>';
        }

        $availabilityValue = $tenantName === '' ? 'Свободно' : 'Занято';
        $availabilityMeta = $tenantName === '' ? 'Арендатор не назначен' : 'Сейчас место используется';
        $availabilityTone = $tenantName === '' ? 'vacant' : 'occupied';

        if ($isMaintenance) {
            $availabilityValue = 'Служебное место';
            $availabilityMeta = 'В распоряжении управляющей компании';
            $availabilityTone = 'default';
        }

        if ($hasSharedUseTenants) {
            $availabilityValue = 'Занято совместно';
            $availabilityMeta = 'Место используется несколькими арендаторами';
        }

        $areaLabel = 'Площадь';
        $areaValue = 'Не указана';
        $areaMeta = 'Нужна для расчётов и отчётов';
        if ($record->area_sqm !== null) {
            $formattedArea = number_format((float) $record->area_sqm, 2, ',', ' ');
            $formattedArea = rtrim(rtrim($formattedArea, '0'), ',');
            $areaValue = $formattedArea.' м²';
            $areaMeta = 'Используется в ставке и аналитике';
        }

        if ($hasSharedUseTenants) {
            $formattedSharedArea = number_format((float) $sharedUseAreaSum, 2, ',', ' ');
            $formattedSharedArea = rtrim(rtrim($formattedSharedArea, '0'), ',');
            $areaLabel = 'Общая площадь участников';
            $areaValue = $formattedSharedArea !== '' ? $formattedSharedArea.' м²' : 'Не указана';
            $areaMeta = 'Сумма площадей активных участников';
        }

        $unitLabel = filled($record->rent_rate_unit)
            ? static::rentRateUnitLabel((string) $record->rent_rate_unit)
            : null;
        $currentRate = $record->rent_rate_value !== null
            ? number_format((float) $record->rent_rate_value, 2, ',', ' ').' ₽'
            : null;
        $factRate = null;
        $period = static::resolveOperationPeriod($record);
        $resolvedFactRate = static::resolveRentRateFact($record, $period);
        if ($resolvedFactRate !== null) {
            $factRate = number_format($resolvedFactRate, 2, ',', ' ').' ₽';
        }

        $rentValue = $currentRate ?? $factRate ?? 'Не задана';
        $rentMetaParts = [];
        if ($unitLabel) {
            $rentMetaParts[] = $unitLabel;
        }
        if ($factRate !== null && $currentRate !== null && $factRate !== $currentRate) {
            $rentMetaParts[] = 'Факт: '.$factRate;
        } elseif ($factRate !== null && $currentRate === null) {
            $rentMetaParts[] = 'Факт за период';
        } elseif ($currentRate === null && $factRate === null) {
            $rentMetaParts[] = 'Ставка ещё не заполнена';
        }

        $items = [];

        if (! $hasSharedUseTenants && ! $isSharedUseSourceSpace && ! $isMaintenance) {
            $items[] = static::renderPrioritySummaryItem('Группа', $groupValue, $groupMeta);
        }

        $items[] = static::renderPrioritySummaryItem($tenantLabel, $tenantValue, $tenantMeta, $tenantTone, $tenantActionHtml);
        $items[] = static::renderPrioritySummaryItem($areaLabel, $areaValue, $areaMeta);

        if (! $isSharedUseSourceSpace) {
            $items[] = static::renderPrioritySummaryItem('Свободно / занято', $availabilityValue, $availabilityMeta, $availabilityTone);
        }

        if (! $hasSharedUseTenants && ! $isMaintenance) {
            $items[] = static::renderPrioritySummaryItem('Ставка', $rentValue, implode(' • ', $rentMetaParts));
        }

        return new HtmlString('<div class="market-space-priority-summary">'.implode('', $items).'</div>');
    }

    private static function tableEffectiveTenantName(MarketSpace $record): ?string
    {
        return static::stringOrNull($record->effectiveTenantName());
    }

    private static function tableEffectiveTenantHint(MarketSpace $record): ?string
    {
        if ($record->effectiveOccupancySource() !== 'parent') {
            return null;
        }

        $sourceSpace = $record->effectiveOccupancySourceSpace();
        $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

        if ($sourceLabel === '') {
            $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
        }

        if ($sourceLabel === '') {
            $sourceLabel = '#'.(int) ($sourceSpace?->id ?? 0);
        }

        return $sourceLabel !== '' ? 'через группу: '.$sourceLabel : 'через группу';
    }

    private static function tableEffectiveStatusLabel(MarketSpace $record): ?string
    {
        return match ($record->effectiveOccupancySource()) {
            'parent' => 'В группе',
            'direct' => 'Занято',
            'none' => 'Свободно',
        };
    }

    private static function tableEffectiveStatusColor(MarketSpace $record): string
    {
        return match ($record->effectiveOccupancySource()) {
            'parent', 'direct' => 'success',
            default => static::statusColor($record->status),
        };
    }

    private static function tableEffectiveStatusTooltip(MarketSpace $record): ?string
    {
        $source = $record->effectiveOccupancySource();

        if ($source === 'parent') {
            $sourceSpace = $record->effectiveOccupancySourceSpace();
            $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

            if ($sourceLabel === '') {
                $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
            }

            return $sourceLabel !== '' ? 'Фактически занято через группу: '.$sourceLabel : 'Фактически занято через группу';
        }

        if ($source === 'direct') {
            return 'Фактически занято напрямую';
        }

        $label = static::statusLabel($record->status);

        return $label ? 'Прямой статус: '.$label : null;
    }

    /**
     * @return array{label:string,color:string,tooltip:string}
     */
    private static function tableFinancialStatusMeta(MarketSpace $record): array
    {
        static $cache = [];

        $sourceSpace = $record->effectiveOccupancySourceSpace() ?: $record;
        $cacheKey = (int) $record->id.':'.(int) $sourceSpace->id;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $tenant = $sourceSpace->tenant;

        if (! $tenant instanceof Tenant) {
            return $cache[$cacheKey] = [
                'label' => '—',
                'color' => 'gray',
                'tooltip' => 'У места нет активного арендатора для расчёта финансового статуса 1С.',
            ];
        }

        $resolved = app(DebtStatusResolver::class)->resolveForMarketSpace((int) $sourceSpace->id, (int) $tenant->market_id);
        $status = (string) ($resolved['status'] ?? 'gray');
        $scope = (string) ($resolved['extra']['scope'] ?? 'none');
        $source = (string) ($resolved['source'] ?? '');

        if ($scope === 'shared_use') {
            $activeCount = (int) ($resolved['extra']['active_count'] ?? 0);

            return $cache[$cacheKey] = [
                'label' => 'Нет точной связи 1С',
                'color' => 'gray',
                'tooltip' => 'Совместное использование'
                    .($activeCount > 0 ? ': '.$activeCount.' участн.' : '')
                    .'. Долг не относится к одному арендатору, пока нет точной связи договоров 1С с участниками.',
            ];
        }

        if ($scope === 'tenant_fallback') {
            return $cache[$cacheKey] = [
                'label' => match ($status) {
                    'red', 'orange' => 'По арендатору: просрочка',
                    'pending' => 'По арендатору: срок не нарушен',
                    'green' => 'По арендатору: нет долга',
                    default => 'По арендатору',
                },
                'color' => static::debtStatusTableColor($status),
                'tooltip' => 'Точной связи договора 1С с местом нет, статус показан по общему сальдо арендатора.',
            ];
        }

        if ($scope === 'space') {
            return $cache[$cacheKey] = [
                'label' => match ($status) {
                    'red', 'orange' => 'По месту: просрочка',
                    'pending' => 'По месту: срок не нарушен',
                    'green' => 'По месту: нет долга',
                    default => 'По месту',
                },
                'color' => static::debtStatusTableColor($status),
                'tooltip' => 'Договор 1С сопоставлен с этим местом, статус рассчитан как точный per-space.',
            ];
        }

        return $cache[$cacheKey] = [
            'label' => $status === 'gray' ? 'Нет данных 1С' : (string) ($resolved['label'] ?? '—'),
            'color' => static::debtStatusTableColor($status),
            'tooltip' => $source !== '' ? ('Источник: '.$source) : 'Недостаточно данных для точного финансового статуса.',
        ];
    }

    private static function debtStatusTableColor(string $status): string
    {
        return match ($status) {
            'green' => 'success',
            'pending' => 'info',
            'orange' => 'warning',
            'red' => 'danger',
            default => 'gray',
        };
    }

    public static function groupRoleOptions(): array
    {
        return [
            MarketSpace::SPACE_GROUP_ROLE_NONE => 'Обычное место',
            MarketSpace::SPACE_GROUP_ROLE_PARENT => 'Группа мест',
            MarketSpace::SPACE_GROUP_ROLE_CHILD => 'Место в группе',
        ];
    }

    public static function parentGroupOptionsForMarket(?int $marketId, ?int $excludeId = null): array
    {
        if (! $marketId) {
            return [];
        }

        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT)
            ->where('is_active', true);

        if ($excludeId) {
            $query->whereKeyNot($excludeId);
        }

        return $query
            ->orderBy('number')
            ->orderBy('display_name')
            ->get()
            ->mapWithKeys(function (MarketSpace $space): array {
                $label = trim((string) ($space->number ?? ''));
                $displayName = trim((string) ($space->display_name ?? ''));

                if ($label === '' && $displayName !== '') {
                    $label = $displayName;
                }

                if ($label === '') {
                    $label = '#'.(int) $space->id;
                } else {
                    $label .= ' #'.(int) $space->id;
                }

                return [$space->id => $label];
            })
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        // ВАЖНО: в форме должен быть РОВНО ОДИН market_id.
        $components = [];

        if ((bool) $user && $user->isSuperAdmin()) {
            $components[] = Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->relationship('market', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->reactive()
                ->default(fn () => static::selectedMarketIdFromSession())
                ->visible(fn (?MarketSpace $record): bool => blank($record))
                ->hintIcon('heroicon-m-question-mark-circle')
                ->hintIconTooltip('Рынок нужен, чтобы корректно фильтровать локации, арендаторов и тарифы.')
                ->dehydrated(true);
        } else {
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => static::selectedMarketIdFromSession() ?? $user?->market_id)
                ->dehydrated(true);
        }

        $tabs = Tabs::make('market_space_tabs')
            ->columnSpanFull();

        // Безопасно: если в вашей версии Filament нет этого метода — просто пропускаем.
        if (method_exists($tabs, 'persistTabInQueryString')) {
            $tabs->persistTabInQueryString();
        }

        return $schema->components([
            ...$components,
            $tabs->tabs([
                Tab::make('Основное')
                    ->schema([
                        Section::make('Ключевая информация')
                            ->schema([
                                Forms\Components\Placeholder::make('shared_use_source_space_notice')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderSharedUseSourceSpaceNotice($record))
                                    ->visible(fn (?MarketSpace $record): bool => static::isSharedUseSourceSpace($record))
                                    ->columnSpanFull(),
                                Forms\Components\Placeholder::make('priority_summary')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderPrioritySummary($record))
                                    ->columnSpanFull(),
                                Forms\Components\Placeholder::make('shared_use_tenants')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderSharedUseTenantsNotice($record))
                                    ->visible(fn (?MarketSpace $record): bool => static::hasSharedUseTenants($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),
                        Section::make('Редактируемые данные')
                            ->schema([
                                Forms\Components\Select::make('location_id')
                                    ->label('Локация')
                                    ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return [];
                                        }

                                        return MarketLocation::query()
                                            ->where('market_id', (int) $marketId)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->afterStateHydrated(function (Forms\Components\Select $component, ?MarketSpace $record, $state): void {
                                        if (filled($state) || ! $record instanceof MarketSpace) {
                                            return;
                                        }

                                        if (($record->space_group_role ?? null) !== MarketSpace::SPACE_GROUP_ROLE_CHILD) {
                                            return;
                                        }

                                        $parentLocationId = $record->spaceGroupParent?->location_id;
                                        if (filled($parentLocationId)) {
                                            $component->state((int) $parentLocationId);
                                        }
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Физическая зона рынка: павильоны, острова, уличная торговля и т.д.')
                                    ->helperText(function (?MarketSpace $record): ?string {
                                        if (! $record instanceof MarketSpace) {
                                            return null;
                                        }

                                        if (($record->space_group_role ?? null) !== MarketSpace::SPACE_GROUP_ROLE_CHILD) {
                                            return null;
                                        }

                                        $parentLocationName = trim((string) ($record->spaceGroupParent?->location?->name ?? ''));

                                        if ($parentLocationName === '') {
                                            return null;
                                        }

                                        return 'Если поле было пустым, подставлена локация родительской группы: '.$parentLocationName.'. Значение можно изменить вручную.';
                                    })
                                    ->disabled(function ($get, ?MarketSpace $record) use ($user) {
                                        if (! ((bool) $user && $user->isSuperAdmin())) {
                                            return false;
                                        }

                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        return blank($marketId);
                                    })
                                    ->nullable(),

                                Forms\Components\Select::make('tenant_id')
                                    ->label(fn (?MarketSpace $record): string => static::isChildWithParent($record) ? 'Прямой арендатор' : 'Арендатор')
                                    ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return [];
                                        }

                                        return Tenant::query()
                                            ->where('market_id', (int) $marketId)
                                            ->active()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Это прямое поле арендатора карточки. Для мест в группе арендатор группы показывается отдельно и не записывается в это поле.')
                                    ->helperText(function (?MarketSpace $record): ?string {
                                        if ($record instanceof MarketSpace
                                            && (string) ($record->space_group_role ?? '') === 'child'
                                            && filled($record->space_group_parent_id)) {
                                            return 'Для мест в группе арендатор группы показывается отдельно и не записывается в это поле.';
                                        }

                                        return null;
                                    })
                                    ->disabled(function ($get, ?MarketSpace $record) use ($user) {
                                        if ($record) {
                                            return true;
                                        }

                                        if (! ((bool) $user && $user->isSuperAdmin())) {
                                            return false;
                                        }

                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        return blank($marketId);
                                    })
                                    ->visible(fn (?MarketSpace $record): bool => ! filled($record?->id) && ! static::isChildWithParent($record))
                                    ->nullable(),
                                Forms\Components\Placeholder::make('effective_tenant_display')
                                    ->label('Фактический арендатор')
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderEffectiveTenantField($record))
                                    ->visible(fn (): bool => false)
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('shared_use_financial_mode')
                                    ->label('Финансовый учет совместного места')
                                    ->options([
                                        MarketSpace::SHARED_USE_FINANCIAL_MODE_SEPARATE_CONTRACT => 'Отдельный договор участника',
                                        MarketSpace::SHARED_USE_FINANCIAL_MODE_INCLUDED_IN_PRIMARY_RENT => 'Включено в аренду основного места',
                                        MarketSpace::SHARED_USE_FINANCIAL_MODE_EXCLUDED => 'Не учитывать в задолженности',
                                    ])
                                    ->default(MarketSpace::SHARED_USE_FINANCIAL_MODE_SEPARATE_CONTRACT)
                                    ->required()
                                    ->native(false)
                                    ->helperText('Используется только для совместного использования: карта либо ищет отдельный договор участника, либо берет задолженность по его основному месту.')
                                    ->visible(fn (?MarketSpace $record): bool => static::hasSharedUseTenants($record))
                                    ->columnSpanFull(),

                                Section::make('Статус места')
                                    ->schema([
                                        Forms\Components\Placeholder::make('effective_occupancy')
                                            ->hiddenLabel()
                                            ->content(fn (?MarketSpace $record): HtmlString => static::renderEffectiveOccupancy($record))
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull()
                                    ->visible(fn (): bool => false)
                                    ->compact(),

                                Forms\Components\TextInput::make('number')
                                    ->label('Обозначение места')
                                    ->maxLength(255)
                                    ->reactive()
                                    ->placeholder('Например: П/1 или A-101')
                                    ->disabled(fn (?MarketSpace $record): bool => filled($record?->id) && ! static::canDirectlyFillMissingIdentityField($record, 'number'))
                                    ->helperText(function (?MarketSpace $record): HtmlString|string|null {
                                        if (! filled($record?->id)) {
                                            return null;
                                        }

                                        if (static::canDirectlyFillMissingIdentityField($record, 'number')) {
                                            return 'Для места в группе обозначение ещё не заполнено. Его можно безопасно указать здесь один раз без ревизии.';
                                        }

                                        return 'Обозначение меняется кнопкой справа. Оно используется на карте, в поиске и истории.';
                                    })
                                    ->suffixAction(
                                        \Filament\Actions\Action::make('change_number')
                                            ->label('Изменить обозначение места')
                                            ->tooltip('Изменить обозначение места')
                                            ->icon('heroicon-o-pencil-square')
                                            ->color('gray')
                                            ->iconButton()
                                            ->visible(fn (?MarketSpace $record): bool => filled($record?->id) && ! static::canDirectlyFillMissingIdentityField($record, 'number'))
                                            ->modalHeading('Изменить обозначение места')
                                            ->modalSubmitActionLabel('Сохранить')
                                            ->modalCancelActionLabel('Отмена')
                                            ->form([
                                                \Filament\Forms\Components\Placeholder::make('change_number_notice')
                                                    ->hiddenLabel()
                                                    ->content('Обозначение используется на карте, в поиске и истории. Изменяйте его только если это исправление текущего обозначения места.'),
                                                \Filament\Forms\Components\TextInput::make('number')
                                                    ->label('Новое обозначение')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->default(fn (?MarketSpace $record): string => trim((string) ($record?->number ?? '')))
                                                    ->placeholder('Например: П/1 или A-101')
                                                    ->helperText('Введите исправленное обозначение места.'),
                                            ])
                                            ->action(function (array $data, \App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace $livewire): void {
                                                $livewire->changeNumber($data);
                                            }),
                                        isInline: true,
                                    )
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Для создания: если display_name пуст — подставляем "Место {number}"
                                        if (blank($get('display_name')) && filled($state)) {
                                            $set('display_name', 'Место '.trim((string) $state));
                                        }
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Короткий идентификатор места. Используется в поиске, импорте начислений и привязке договоров. Для существующего места меняется кнопкой справа, кроме безопасного дозаполнения пустого номера у места в группе.'),

                                Forms\Components\TextInput::make('display_name')
                                    ->label('Название (для отображения)')
                                    ->maxLength(255)
                                    ->placeholder('Например: Аптека 22')
                                    ->helperText('Отображаемое название места в карточках, списках и поиске.')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Отображаемое название места в карточках, списках и поиске.')
                                    ->nullable(),

                                Forms\Components\Select::make('space_group_role')
                                    ->label('Тип группировки')
                                    ->options(function ($get, ?MarketSpace $record): array {
                                        $options = static::groupRoleOptions();

                                        if (! filled($record?->id) && (string) ($get('status') ?? 'vacant') === 'maintenance') {
                                            return [
                                                MarketSpace::SPACE_GROUP_ROLE_NONE => $options[MarketSpace::SPACE_GROUP_ROLE_NONE] ?? 'Не входит в группу',
                                            ];
                                        }

                                        if (static::hasSharedUseTenants($record)) {
                                            return [
                                                MarketSpace::SPACE_GROUP_ROLE_NONE => $options[MarketSpace::SPACE_GROUP_ROLE_NONE] ?? 'Не входит в группу',
                                            ];
                                        }

                                        if (filled($record?->id) && ! static::isChildWithParent($record)) {
                                            unset($options[MarketSpace::SPACE_GROUP_ROLE_CHILD]);
                                        }

                                        return $options;
                                    })
                                    ->default('none')
                                    ->required()
                                    ->live()
                                    ->visible(fn ($get, ?MarketSpace $record): bool => (string) ($record?->status ?? $get('status') ?? 'vacant') !== 'maintenance'
                                        && ! static::hasSharedUseTenants($record)
                                        && ! static::isSharedUseSourceSpace($record))
                                    ->disabled(fn ($get, ?MarketSpace $record): bool => static::hasSharedUseTenants($record)
                                        || static::isSharedUseSourceSpace($record)
                                        || (! filled($record?->id) && (string) ($get('status') ?? 'vacant') === 'maintenance'))
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(function (?MarketSpace $record): string {
                                        if (static::isSharedUseSourceSpace($record)) {
                                            return 'Это служебная запись участника совместного использования. Управляйте участниками в карточке основного места.';
                                        }

                                        return 'Определяет, как место участвует в группировке. Для существующего места перевод в группу выполняется отдельной кнопкой в шапке карточки, чтобы сразу выбрать родительскую группу и номер внутри группы.';
                                    })
                                    ->helperText(function ($get, ?MarketSpace $record): ?string {
                                        if (! filled($record?->id) && (string) ($get('status') ?? 'vacant') === 'maintenance') {
                                            return 'Служебное место не может входить в группу и не может быть parent-группой.';
                                        }

                                        if (! filled($record?->id)) {
                                            return null;
                                        }

                                        if (static::isSharedUseSourceSpace($record)) {
                                            return 'Это служебная запись участника совместного использования. Управляйте участниками в карточке основного места.';
                                        }

                                        if (static::hasSharedUseTenants($record)) {
                                            return 'Совместное место не может быть parent-группой или местом внутри группы.';
                                        }

                                        if (static::isChildWithParent($record)) {
                                            return 'Связь с группой меняется кнопкой «Перенести в группу» в шапке карточки.';
                                        }

                                        return 'Чтобы сделать место частью группы, используйте кнопку «Добавить в группу» в шапке карточки.';
                                    })
                                    ->afterStateHydrated(function (Forms\Components\Select $component, ?string $state, ?MarketSpace $record): void {
                                        if (filled($record?->id)
                                            && (string) ($record?->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD
                                            && blank($record?->space_group_parent_id)
                                        ) {
                                            $component->state(MarketSpace::SPACE_GROUP_ROLE_NONE);
                                        }
                                    })
                                    ->afterStateUpdated(function (?string $state, callable $set): void {
                                        if ($state === 'none') {
                                            $set('space_group_token', null);
                                            $set('space_group_slot', null);
                                            $set('space_group_parent_id', null);
                                        } elseif ($state === 'parent') {
                                            $set('space_group_slot', null);
                                            $set('space_group_parent_id', null);
                                            // space_group_token не трогаем — legacy поле, может быть уже установлено
                                        } elseif ($state === 'child') {
                                            // space_group_token не трогаем — legacy поле
                                            // space_group_parent_id не трогаем — устанавливается через Select
                                            // space_group_slot не трогаем — вводится пользователем
                                        }
                                    }),

                                Section::make('Связь с группой')
                                    ->schema([
                                        Forms\Components\Placeholder::make('child_group_context')
                                            ->hiddenLabel()
                                            ->content(fn (?MarketSpace $record): ?HtmlString => static::renderChildInheritanceNotice($record))
                                            ->columnSpanFull(),
                                    ])
                                    ->visible(fn (?MarketSpace $record): bool => (string) ($record?->status ?? '') !== 'maintenance'
                                        && ! static::hasSharedUseTenants($record)
                                        && ! static::isSharedUseSourceSpace($record)
                                        && static::isChildWithParent($record))
                                    ->columnSpanFull()
                                    ->compact(),

                                Section::make('Фигура карты у parent-группы')
                                    ->schema([
                                        Forms\Components\Placeholder::make('parent_group_map_shape_warning')
                                            ->hiddenLabel()
                                            ->content(fn (?MarketSpace $record): ?HtmlString => static::renderParentGroupMapShapeWarning($record))
                                            ->columnSpanFull(),

                                        Forms\Components\Radio::make('parent_group_map_shape_action')
                                            ->label('Что сделать с активной фигурой карты?')
                                            ->options([
                                                'deactivate' => 'Отвязать от места и деактивировать',
                                                'delete' => 'Удалить фигуру полностью',
                                            ])
                                            ->required(fn ($get, ?MarketSpace $record): bool => static::requiresParentGroupMapShapeResolution(
                                                $record,
                                                (string) ($get('space_group_role') ?? MarketSpace::SPACE_GROUP_ROLE_NONE),
                                            ))
                                            ->visible(fn ($get, ?MarketSpace $record): bool => static::requiresParentGroupMapShapeResolution(
                                                $record,
                                                (string) ($get('space_group_role') ?? MarketSpace::SPACE_GROUP_ROLE_NONE),
                                            ))
                                            ->dehydrated(fn ($get, ?MarketSpace $record): bool => static::requiresParentGroupMapShapeResolution(
                                                $record,
                                                (string) ($get('space_group_role') ?? MarketSpace::SPACE_GROUP_ROLE_NONE),
                                            ))
                                            ->helperText('Без этого выбора parent-группа осталась бы с обычной фигурой карты, что может запутать учёт и ревизию.'),
                                    ])
                                    ->visible(fn ($get, ?MarketSpace $record): bool => (string) ($record?->status ?? $get('status') ?? 'vacant') !== 'maintenance'
                                        && static::requiresParentGroupMapShapeResolution(
                                            $record,
                                            (string) ($get('space_group_role') ?? MarketSpace::SPACE_GROUP_ROLE_NONE),
                                        ))
                                    ->columnSpanFull()
                                    ->compact(),

                                Forms\Components\Select::make('space_group_parent_id')
                                    ->label('Родительская группа')
                                    ->options(function ($get, ?MarketSpace $record): array {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        return static::parentGroupOptionsForMarket(
                                            filled($marketId) ? (int) $marketId : null,
                                            filled($record?->id) ? (int) $record->id : null,
                                        );
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->visible(fn ($get, ?MarketSpace $record): bool => (string) ($record?->status ?? $get('status') ?? 'vacant') !== 'maintenance'
                                        && ! filled($record?->id)
                                        && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->dehydrated(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Выбирается только для места в группе. Родитель определяет, к какой группе относится child-место.')
                                    ->placeholder('Выберите родительскую группу')
                                    ->nullable(),

                                Forms\Components\TextInput::make('space_group_slot')
                                    ->label('Номер в группе')
                                    ->maxLength(255)
                                    ->required(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->visible(fn ($get, ?MarketSpace $record): bool => (string) ($record?->status ?? $get('status') ?? 'vacant') !== 'maintenance'
                                        && ! filled($record?->id)
                                        && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->dehydrated(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->placeholder('Например: 6')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Внутренний номер child-места внутри родительской группы.')
                                    ->nullable(),

                                Forms\Components\TextInput::make('activity_type')
                                    ->label('Вид деятельности')
                                    ->maxLength(255)
                                    ->placeholder('Например: аптека / электро / мясо')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Заполняется импортом начислений и может уточняться вручную.')
                                    ->nullable(),

                                Forms\Components\Select::make('type')
                                    ->label('Тарифная категория места')
                                    ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        return static::resolveSpaceTypeOptions(
                                            filled($marketId) ? (int) $marketId : null,
                                            (string) ($get('type') ?? $record?->type ?? '')
                                        );
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->nullable()
                                    ->placeholder('Не выбрана')
                                    ->helperText(function ($get, ?MarketSpace $record) use ($user): ?string {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return 'Категория не меняет занятость, служебность, группу или совместное использование.';
                                        }

                                        $currentType = trim((string) ($get('type') ?? $record?->type ?? ''));

                                        $activeTypeCount = MarketSpaceType::query()
                                            ->where('market_id', (int) $marketId)
                                            ->where('is_active', true)
                                            ->count();

                                        if ($activeTypeCount === 0) {
                                            return 'Справочник тарифных категорий для этого рынка пуст. Категория не меняет занятость, служебность, группу или совместное использование.';
                                        }

                                        if ($currentType === '') {
                                            return 'Категория нужна для тарифов и отчётности. Она не меняет занятость, служебность, группу или совместное использование.';
                                        }

                                        $hasCurrentActiveType = MarketSpaceType::query()
                                            ->where('market_id', (int) $marketId)
                                            ->where('is_active', true)
                                            ->where('code', $currentType)
                                            ->exists();

                                        if ($hasCurrentActiveType) {
                                            return 'Категория нужна для тарифов и отчётности. Она не меняет занятость, служебность, группу или совместное использование.';
                                        }

                                        return 'Сейчас сохранено старое значение с кодом '.$currentType.'. Его нет в текущем справочнике типов мест.';
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(function ($get, ?MarketSpace $record) use ($user): string {
                                        $parts = ['Тарифная категория места для ставок и отчётности. Это не статус занятости и не признак служебного, группового или совместного места.'];
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (filled($marketId)) {
                                            $currentType = (string) ($get('type') ?? $record?->type ?? '');
                                            $activeTypeCount = MarketSpaceType::query()
                                                ->where('market_id', (int) $marketId)
                                                ->where('is_active', true)
                                                ->count();
                                            $hasCurrentActiveType = $currentType !== ''
                                                && MarketSpaceType::query()
                                                    ->where('market_id', (int) $marketId)
                                                    ->where('is_active', true)
                                                    ->where('code', $currentType)
                                                    ->exists();

                                            if ($activeTypeCount === 0) {
                                                $parts[] = 'Для этого рынка справочник категорий пока пуст. Сначала заполните "Тарифные категории мест".';
                                            } elseif ($currentType !== '' && ! $hasCurrentActiveType) {
                                                $parts[] = 'Текущая категория больше не найдена среди активных категорий.';
                                            }
                                        }

                                        return implode(' ', $parts);
                                    }),

                                Forms\Components\Placeholder::make('shared_use_reference_area')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderSharedUseReferenceArea($record))
                                    ->visible(fn (?MarketSpace $record): bool => static::hasSharedUseTenants($record))
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('area_sqm')
                                    ->label(fn (?MarketSpace $record): string => static::hasSharedUseTenants($record)
                                        ? 'Справочная площадь физического места, м²'
                                        : 'Площадь, м²')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->placeholder('Например: 48')
                                    ->suffix('м²')
                                    ->visible(fn (?MarketSpace $record): bool => ! static::hasSharedUseTenants($record))
                                    ->extraFieldWrapperAttributes(['style' => 'width:min(100%, 14rem);'])
                                    ->extraInputAttributes(['style' => 'width:100%;'])
                                    ->helperText(fn (?MarketSpace $record): string => static::hasSharedUseTenants($record)
                                        ? 'Справочное поле старой карточки. Не влияет на общую площадь участников и не меняет их площади.'
                                        : 'Площадь самого места. Если место используется совместно, площади участников задаются отдельно.')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(fn (?MarketSpace $record): string => static::hasSharedUseTenants($record)
                                        ? 'Для совместного места рабочие площади задаются у участников в блоке совместного использования. Это поле оставлено только как справочная площадь физической карточки.'
                                        : 'Площадь используется в отчётах и расчётах. Допускаются десятичные значения.'),

                                Forms\Components\Select::make('status')
                                    ->label('Прямой статус места')
                                    ->options(function ($get): array {
                                        $options = [
                                            'vacant' => 'Свободно',
                                            'occupied' => 'Занято',
                                            'reserved' => 'Зарезервировано',
                                            'maintenance' => 'Служебное место',
                                        ];

                                        if ((string) ($get('space_group_role') ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
                                            unset($options['maintenance']);
                                        }

                                        return $options;
                                    })
                                    ->default('vacant')
                                    ->afterStateHydrated(function (Forms\Components\Select $component, $state): void {
                                        if ($state === 'free') {
                                            $component->state('vacant');
                                        }
                                    })
                                    ->dehydrateStateUsing(fn ($state) => $state === 'free' ? 'vacant' : $state)
                                    ->visible(fn (?MarketSpace $record): bool => ! $record)
                                    ->disabled(fn (?MarketSpace $record): bool => (bool) $record)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Это прямой статус карточки. Для мест в группе фактическая занятость может наследоваться от группы.')
                                    ->helperText('Это прямой статус карточки. Для мест в группе фактическая занятость может наследоваться от группы.'),

                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ]),

                        Section::make('Ставка аренды')
                            ->schema([
                                Forms\Components\Placeholder::make('rent_rate_fact')
                                    ->label('Фактическая ставка за период')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Ставка, которую система фактически видит для выбранного периода. Берётся из операций и начислений, поэтому может отличаться от текущей ставки в карточке.')
                                    ->content(fn (?MarketSpace $record): HtmlString => static::rentRateFactHtml($record))
                                    ->columnSpanFull(),

                                Grid::make([
                                    'default' => 1,
                                    'md' => 2,
                                ])
                                    ->schema([
                                        Forms\Components\TextInput::make('rent_rate_value')
                                            ->label('Текущая ставка')
                                            ->numeric()
                                            ->inputMode('decimal')
                                            ->placeholder('Например: 1500')
                                            ->hintIcon('heroicon-m-question-mark-circle')
                                            ->hintIconTooltip('Текущее значение ставки в карточке места. Используется в интерфейсах и операциях как актуальный снапшот ставки.')
                                            ->disabled(fn (?MarketSpace $record): bool => (bool) $record),

                                        Forms\Components\Select::make('rent_rate_unit')
                                            ->label('Единица ставки')
                                            ->options(static::rentRateUnitOptions())
                                            ->placeholder('Не указано')
                                            ->hintIcon('heroicon-m-question-mark-circle')
                                            ->hintIconTooltip('Показывает, как интерпретировать текущую ставку: за м² в месяц или за всё место в месяц.')
                                            ->nullable()
                                            ->disabled(fn (?MarketSpace $record): bool => (bool) $record),
                                    ])
                                    ->columnSpanFull(),

                            ])
                            ->collapsible()
                            ->visible(fn (?MarketSpace $record): bool => (string) ($record?->status ?? '') !== 'maintenance'),

                        Section::make('Примечания')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->rows(3)
                                    ->placeholder('Добавьте комментарий по торговому месту…')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Свободный комментарий. Это поле не должно перетираться импортом.')
                                    ->columnSpanFull(),
                            ])
                            ->collapsible()
                            ->collapsed(),

                        Section::make('Состав группы')
                            ->visible(fn (?MarketSpace $record): bool => (string) ($record?->status ?? '') !== 'maintenance'
                                && (string) ($record?->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT)
                            ->schema([
                                Forms\Components\Placeholder::make('group_composition')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderGroupComposition($record))
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),
                    ]),
                Tab::make('Финансы')
                    ->visible(fn (?MarketSpace $record): bool => (string) ($record?->status ?? '') !== 'maintenance')
                    ->schema([
                        Section::make('Финансы 1С')
                            ->schema([
                                Forms\Components\Placeholder::make('space_settlement_balances')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderSpaceSettlementBalances($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),
                    ]),
                Tab::make('История')
                    ->schema([
                        Section::make('Арендаторы')
                            ->schema([
                                Forms\Components\Placeholder::make('tenant_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderTenantHistory($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Ставка')
                            ->schema([
                                Forms\Components\Placeholder::make('rent_rate_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderRentRateHistory($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Операции')
                            ->schema([
                                Forms\Components\Placeholder::make('operations')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderOperations($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),
                    ]),
            ]),
        ]);
    }

    /**
     * Опции единицы ставки.
     *
     * @return array<string, string>
     */
    protected static function rentRateUnitOptions(): array
    {
        return [
            'per_sqm_month' => 'за м² в месяц',
            'per_space_month' => 'за место в месяц',
        ];
    }

    protected static function rentRateUnitLabel(?string $unit): string
    {
        return static::rentRateUnitOptions()[$unit] ?? '—';
    }

    private static function rentRateFactHtml(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('—');
        }

        $market = Market::query()->find($record->market_id);
        if (! $market) {
            return new HtmlString('—');
        }

        $period = static::resolveOperationPeriod($record);
        $rentRate = static::resolveRentRateFact($record, $period);
        $unitLabel = $record->rent_rate_unit ? static::rentRateUnitLabel($record->rent_rate_unit) : null;

        $display = $rentRate !== null
            ? number_format($rentRate, 2, ',', ' ').' ₽'
            : 'Не задано';

        $extra = '';
        if ($unitLabel) {
            $extra .= '<div style="margin-top:4px;opacity:.7;">Единица: '.e($unitLabel).'</div>';
        }

        $estimate = static::rentRateEstimateHtml($record, $period);

        return new HtmlString(
            '<div style="font-size:13px;">'.
            '<div><strong>'.e($display).'</strong></div>'.
            $extra.
            $estimate.
            '</div>'
        );
    }

    private static function rentRateEstimateHtml(MarketSpace $record, \Carbon\CarbonImmutable $period): string
    {
        $row = DB::table('tenant_accruals')
            ->where('market_id', (int) $record->market_id)
            ->where('market_space_id', (int) $record->id)
            ->where('period', $period->toDateString())
            ->select(['rent_amount', 'area_sqm'])
            ->first();

        if (! $row || ! $row->rent_amount || ! $row->area_sqm) {
            return '';
        }

        $area = (float) $row->area_sqm;
        if ($area <= 0) {
            return '';
        }

        $value = (float) $row->rent_amount / $area;
        $display = number_format($value, 2, ',', ' ');

        return '<div style="margin-top:4px;opacity:.65;">Оценочно: '.e($display).' ₽/м² за период (справочно).</div>';
    }

    private static function resolveOperationPeriod(MarketSpace $record): \Carbon\CarbonImmutable
    {
        $market = Market::query()->find($record->market_id);
        $resolver = app(MarketPeriodResolver::class);
        $periodInput = request()->query('period');

        if ($market) {
            return $resolver->resolveMarketPeriod($market, is_string($periodInput) ? $periodInput : null);
        }

        return \Carbon\CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfMonth();
    }

    private static function resolveRentRateFact(MarketSpace $record, \Carbon\CarbonImmutable $period): ?float
    {
        $stateService = app(OperationsStateService::class);
        $state = $stateService->getSpaceStateForPeriod((int) $record->market_id, $period, (int) $record->id);
        $rentRate = $state['rent_rate'];

        if ($rentRate === null) {
            $fallback = DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('market_space_id', (int) $record->id)
                ->where('period', $period->toDateString())
                ->value('rent_rate');

            if ($fallback !== null) {
                $rentRate = (float) $fallback;
            }
        }

        return $rentRate !== null ? (float) $rentRate : null;
    }

    private static function renderSpaceSettlementBalances(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">ОСВ появится после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('tenant_settlement_balances')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица ОСВ 1С ещё не создана — выполните миграции.</div>');
        }

        $tenantId = $record->effectiveTenantId();
        $settlementSnapshots = static::settlementSnapshots((int) $record->market_id);
        $snapshot = static::selectedSettlementSnapshot($settlementSnapshots);
        $isSharedUse = static::hasSharedUseTenants($record);
        $sharedUseParticipants = static::sharedUseFinanceParticipants($record);
        $sharedUseMode = (string) ($record->shared_use_financial_mode ?? MarketSpace::SHARED_USE_FINANCIAL_MODE_SEPARATE_CONTRACT);
        $sharedUseModeLabel = static::sharedUseFinancialModeLabel($sharedUseMode);

        if (! $snapshot) {
            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'empty',
                'emptyReason' => 'По этому рынку ещё нет загруженной ОСВ 1С.',
                'isSharedUse' => $isSharedUse,
                'sharedUseMode' => $sharedUseMode,
                'sharedUseModeLabel' => $sharedUseModeLabel,
                'sharedUseParticipants' => $sharedUseParticipants,
            ])->render());
        }

        if ($isSharedUse && $sharedUseMode === MarketSpace::SHARED_USE_FINANCIAL_MODE_INCLUDED_IN_PRIMARY_RENT) {
            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'shared_included',
                'emptyReason' => 'Задолженность по участникам учитывается по их основным местам. Отдельный долг по этому совместному месту не показывается.',
                'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                'account' => (string) $snapshot->account,
                'isSharedUse' => true,
                'sharedUseMode' => $sharedUseMode,
                'sharedUseModeLabel' => $sharedUseModeLabel,
                'sharedUseParticipants' => $sharedUseParticipants,
                ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
            ])->render());
        }

        if ($isSharedUse && $sharedUseMode === MarketSpace::SHARED_USE_FINANCIAL_MODE_EXCLUDED) {
            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'shared_excluded',
                'emptyReason' => 'Это совместное место исключено из расчёта задолженности. ОСВ доступна только как справочная информация по участникам.',
                'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                'account' => (string) $snapshot->account,
                'isSharedUse' => true,
                'sharedUseMode' => $sharedUseMode,
                'sharedUseModeLabel' => $sharedUseModeLabel,
                'sharedUseParticipants' => $sharedUseParticipants,
                ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
            ])->render());
        }

        if ($isSharedUse) {
            $participantRows = collect();

            foreach ($sharedUseParticipants as $participant) {
                $participantTenantId = (int) ($participant['tenant_id'] ?? 0);
                if ($participantTenantId <= 0) {
                    continue;
                }

                $contractExternalIds = static::activeContractExternalIdsForSpace(
                    (int) $record->market_id,
                    $participantTenantId,
                    (int) $record->id,
                );

                $rows = $contractExternalIds->isNotEmpty()
                    ? static::spaceSettlementRows((int) $record->market_id, $participantTenantId, $snapshot, $contractExternalIds)
                    : collect();

                if ($rows->isEmpty()) {
                    $rows = static::spaceSettlementRows((int) $record->market_id, $participantTenantId, $snapshot, null);
                }

                $participantRows = $participantRows->merge($rows);
            }

            if ($participantRows->isEmpty()) {
                return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                    'state' => 'empty',
                    'emptyReason' => 'В выбранной ОСВ нет строк по активным участникам совместного места.',
                    'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                    'account' => (string) $snapshot->account,
                    'isSharedUse' => true,
                    'sharedUseMode' => $sharedUseMode,
                    'sharedUseModeLabel' => $sharedUseModeLabel,
                    'sharedUseParticipants' => $sharedUseParticipants,
                    'settlementsUrl' => static::spaceSettlementsUrl($snapshot, $record->number),
                    ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
                ])->render());
            }

            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'ready',
                'scope' => 'shared_use',
                'scopeLabel' => 'Данные подобраны по участникам совместного места',
                'scopeTone' => 'success',
                'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                'account' => (string) $snapshot->account,
                'importedAt' => $snapshot->imported_at ? (string) \Carbon\Carbon::parse($snapshot->imported_at)->format('d.m.Y H:i') : null,
                'summary' => static::summarizeSpaceSettlementRows($participantRows),
                'rows' => $participantRows->map(fn (object $row): array => static::normalizeSpaceSettlementRow($row))->values()->all(),
                'currentTenantName' => '',
                'settlementsUrl' => static::spaceSettlementsUrl($snapshot, $record->number),
                'isSharedUse' => true,
                'sharedUseMode' => $sharedUseMode,
                'sharedUseModeLabel' => $sharedUseModeLabel,
                'sharedUseParticipants' => $sharedUseParticipants,
                ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
            ])->render());
        }

        if (! $tenantId) {
            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'empty',
                'emptyReason' => 'У места нет текущего арендатора, поэтому строки ОСВ 1С не подбираются.',
                'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                'account' => (string) $snapshot->account,
                ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
            ])->render());
        }

        $contractExternalIds = static::activeContractExternalIdsForSpace(
            (int) $record->market_id,
            (int) $tenantId,
            (int) $record->id,
        );

        $rows = $contractExternalIds->isNotEmpty()
            ? static::spaceSettlementRows((int) $record->market_id, (int) $tenantId, $snapshot, $contractExternalIds)
            : collect();

        $scope = 'exact';

        if ($rows->isEmpty()) {
            $rows = static::spaceSettlementRows((int) $record->market_id, (int) $tenantId, $snapshot, null);
            $scope = 'tenant_fallback';
        }

        if ($rows->isEmpty()) {
            return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
                'state' => 'empty',
                'emptyReason' => 'В последней ОСВ нет строк по текущему арендатору этого места.',
                'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
                'account' => (string) $snapshot->account,
                'settlementsUrl' => static::spaceSettlementsUrl($snapshot, $record->effectiveTenantName() ?? $record->number),
                ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
            ])->render());
        }

        $summary = static::summarizeSpaceSettlementRows($rows);
        $search = $scope === 'exact' && $contractExternalIds->isNotEmpty()
            ? (string) $contractExternalIds->first()
            : (string) ($record->effectiveTenantName() ?? $record->number);

        return new HtmlString(view('filament.market-spaces.space-settlement-balances', [
            'state' => 'ready',
            'scope' => $scope,
            'scopeLabel' => $scope === 'exact'
                ? 'Данные найдены по договору этого места'
                : 'Показаны данные по арендатору, связь с местом требует проверки',
            'scopeTone' => $scope === 'exact' ? 'success' : 'warning',
            'periodLabel' => static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to),
            'account' => (string) $snapshot->account,
            'importedAt' => $snapshot->imported_at ? (string) \Carbon\Carbon::parse($snapshot->imported_at)->format('d.m.Y H:i') : null,
            'summary' => $summary,
            'rows' => $rows->map(fn (object $row): array => static::normalizeSpaceSettlementRow($row))->values()->all(),
            'currentTenantName' => (string) ($record->effectiveTenantName() ?? ''),
            'contractExternalIds' => $contractExternalIds->all(),
            'settlementsUrl' => static::spaceSettlementsUrl($snapshot, $search),
            ...static::spaceSettlementPeriodPickerProps($settlementSnapshots, $snapshot),
        ])->render());
    }

    private static function sharedUseFinancialModeLabel(string $mode): string
    {
        return match ($mode) {
            MarketSpace::SHARED_USE_FINANCIAL_MODE_INCLUDED_IN_PRIMARY_RENT => 'Включено в аренду основного места',
            MarketSpace::SHARED_USE_FINANCIAL_MODE_EXCLUDED => 'Не учитывать в задолженности',
            default => 'Отдельный договор участника',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sharedUseFinanceParticipants(MarketSpace $record): array
    {
        return collect(static::sharedUseTenantRows($record))
            ->map(static function (array $row): array {
                $area = $row['area_sqm'];
                $rate = $row['rent_rate'];

                return [
                    'tenant_id' => $row['tenant_id'],
                    'tenant_name' => $row['tenant_name'],
                    'area_label' => $area !== null
                        ? rtrim(rtrim(number_format((float) $area, 2, ',', ' '), '0'), ',').' м²'
                        : 'Площадь не указана',
                    'rent_rate_label' => $rate !== null
                        ? rtrim(rtrim(number_format((float) $rate, 2, ',', ' '), '0'), ',').' ₽'
                        : 'Ставка не указана',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, object>
     */
    private static function settlementSnapshots(int $marketId): Collection
    {
        $snapshots = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->select(['period_from', 'period_to', 'account'])
            ->selectRaw('max(imported_at) as imported_at')
            ->groupBy(['period_from', 'period_to', 'account'])
            ->orderByDesc('period_to')
            ->orderByDesc('imported_at')
            ->get();

        $account62Snapshots = $snapshots
            ->filter(fn (object $snapshot): bool => (string) $snapshot->account === '62')
            ->values();

        return $account62Snapshots->isNotEmpty() ? $account62Snapshots : $snapshots;
    }

    /**
     * @param  Collection<int, object>  $snapshots
     */
    private static function selectedSettlementSnapshot(Collection $snapshots): ?object
    {
        if ($snapshots->isEmpty()) {
            return null;
        }

        $selectedPeriod = request()->query('settlement_period');

        if (is_string($selectedPeriod) && $selectedPeriod !== '') {
            $selectedSnapshot = $snapshots->first(
                fn (object $snapshot): bool => static::spaceSettlementPeriodKey($snapshot) === $selectedPeriod
            );

            if ($selectedSnapshot) {
                return $selectedSnapshot;
            }
        }

        return $snapshots->first(fn (object $snapshot): bool => (string) $snapshot->account === '62')
            ?: $snapshots->first();
    }

    private static function spaceSettlementPeriodKey(object $snapshot): string
    {
        return implode('|', [
            (string) $snapshot->period_from,
            (string) $snapshot->period_to,
            (string) $snapshot->account,
        ]);
    }

    /**
     * @param  Collection<int, object>  $snapshots
     * @return array<string, mixed>
     */
    private static function spaceSettlementPeriodPickerProps(Collection $snapshots, object $selectedSnapshot): array
    {
        $firstSnapshot = $snapshots
            ->sortBy(fn (object $snapshot): string => (string) $snapshot->period_from)
            ->first();

        return [
            'periodOptions' => $snapshots
                ->mapWithKeys(function (object $snapshot): array {
                    $label = static::formatSpaceSettlementPeriod((string) $snapshot->period_from, (string) $snapshot->period_to);

                    if ((string) $snapshot->account !== '62') {
                        $label .= ' · счёт '.$snapshot->account;
                    }

                    return [static::spaceSettlementPeriodKey($snapshot) => $label];
                })
                ->all(),
            'selectedPeriodKey' => static::spaceSettlementPeriodKey($selectedSnapshot),
            'firstPeriodLabel' => $firstSnapshot
                ? \Carbon\CarbonImmutable::parse((string) $firstSnapshot->period_from)->format('d.m.Y')
                : null,
        ];
    }

    /**
     * @param  Collection<int, string>|null  $contractExternalIds
     * @return Collection<int, object>
     */
    private static function spaceSettlementRows(int $marketId, int $tenantId, object $snapshot, ?Collection $contractExternalIds): Collection
    {
        $query = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('period_from', (string) $snapshot->period_from)
            ->where('period_to', (string) $snapshot->period_to)
            ->where('account', (string) $snapshot->account);

        if ($contractExternalIds !== null) {
            if ($contractExternalIds->isEmpty()) {
                return collect();
            }

            $query->whereIn('contract_external_id', $contractExternalIds->all());
        }

        return $query
            ->select([
                'tenant_id',
                'tenant_name',
                'tenant_contract_id',
                'contract_external_id',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->selectRaw('count(*) as rows_count')
            ->selectRaw('coalesce(sum(opening_debit),0) as opening_debit')
            ->selectRaw('coalesce(sum(opening_credit),0) as opening_credit')
            ->selectRaw('coalesce(sum(turnover_debit),0) as turnover_debit')
            ->selectRaw('coalesce(sum(turnover_credit),0) as turnover_credit')
            ->selectRaw('coalesce(sum(closing_debit),0) as closing_debit')
            ->selectRaw('coalesce(sum(closing_credit),0) as closing_credit')
            ->groupBy([
                'tenant_id',
                'tenant_name',
                'tenant_contract_id',
                'contract_external_id',
                'contract_name',
                'organization_name',
                'account',
            ])
            ->orderByRaw('(coalesce(sum(closing_debit),0) - coalesce(sum(closing_credit),0)) desc')
            ->orderBy('contract_name')
            ->limit(10)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarizeSpaceSettlementRows(Collection $rows): array
    {
        $openingDebit = (float) $rows->sum(fn (object $row): float => (float) $row->opening_debit);
        $openingCredit = (float) $rows->sum(fn (object $row): float => (float) $row->opening_credit);
        $turnoverDebit = (float) $rows->sum(fn (object $row): float => (float) $row->turnover_debit);
        $turnoverCredit = (float) $rows->sum(fn (object $row): float => (float) $row->turnover_credit);
        $closingDebit = (float) $rows->sum(fn (object $row): float => (float) $row->closing_debit);
        $closingCredit = (float) $rows->sum(fn (object $row): float => (float) $row->closing_credit);
        $closingNet = $closingDebit - $closingCredit;

        return [
            'rows_count' => (int) $rows->sum(fn (object $row): int => (int) $row->rows_count),
            'contracts_count' => $rows->pluck('contract_external_id')->filter()->unique()->count(),
            'opening_net' => $openingDebit - $openingCredit,
            'turnover_debit' => $turnoverDebit,
            'turnover_credit' => $turnoverCredit,
            'closing_net' => $closingNet,
            'closing_label' => abs($closingNet) <= 0.009
                ? 'Нет долга'
                : ($closingNet > 0 ? 'Долг' : 'Переплата'),
            'closing_tone' => abs($closingNet) <= 0.009
                ? 'neutral'
                : ($closingNet > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeSpaceSettlementRow(object $row): array
    {
        $closingNet = (float) $row->closing_debit - (float) $row->closing_credit;

        return [
            'tenant_name' => (string) ($row->tenant_name ?? ''),
            'contract_name' => (string) ($row->contract_name ?? ''),
            'contract_external_id' => (string) ($row->contract_external_id ?? ''),
            'organization_name' => (string) ($row->organization_name ?? ''),
            'rows_count' => (int) $row->rows_count,
            'opening_net' => (float) $row->opening_debit - (float) $row->opening_credit,
            'turnover_debit' => (float) $row->turnover_debit,
            'turnover_credit' => (float) $row->turnover_credit,
            'closing_net' => $closingNet,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private static function activeContractExternalIdsForSpace(int $marketId, int $tenantId, int $marketSpaceId): Collection
    {
        if (
            ! SchemaFacade::hasTable('tenant_contracts')
            || ! SchemaFacade::hasColumn('tenant_contracts', 'external_id')
        ) {
            return collect();
        }

        $contractIds = collect();
        $now = now();

        if (
            SchemaFacade::hasTable('market_space_tenant_bindings')
            && SchemaFacade::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')
        ) {
            $contractIds = $contractIds->merge(DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->where('mstb.market_space_id', $marketSpaceId)
                ->where('mstb.market_id', $marketId)
                ->where('mstb.tenant_id', $tenantId)
                ->whereNotNull('mstb.tenant_contract_id')
                ->whereNotNull('tc.external_id')
                ->where('tc.market_id', $marketId)
                ->where('tc.tenant_id', $tenantId)
                ->where('tc.is_active', true)
                ->whereNotIn('tc.status', ['terminated', 'archived'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->pluck('tc.external_id'));
        }

        if (SchemaFacade::hasColumn('tenant_contracts', 'market_space_id')) {
            $contractIds = $contractIds->merge(DB::table('tenant_contracts')
                ->where('market_id', $marketId)
                ->where('tenant_id', $tenantId)
                ->where('market_space_id', $marketSpaceId)
                ->where('is_active', true)
                ->whereNotIn('status', ['terminated', 'archived'])
                ->whereNotNull('external_id')
                ->pluck('external_id'));
        }

        return $contractIds
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    private static function formatSpaceSettlementPeriod(string $fromDate, string $toDate): string
    {
        return \Carbon\CarbonImmutable::parse($fromDate)->format('d.m.Y')
            .' — '
            .\Carbon\CarbonImmutable::parse($toDate)->format('d.m.Y');
    }

    private static function spaceSettlementsUrl(object $snapshot, ?string $search): string
    {
        $query = [
            'from' => (string) $snapshot->period_from,
            'to' => (string) $snapshot->period_to,
            'account' => (string) $snapshot->account,
        ];

        $search = trim((string) $search);

        if ($search !== '') {
            $query['search'] = $search;
        }

        return OneCSettlements::getUrl().'?'.http_build_query($query);
    }

    private static function renderTenantHistory(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">История появится после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('market_space_tenant_histories')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица истории арендаторов ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('market_space_tenant_histories as h')
            ->leftJoin('tenants as old_t', 'old_t.id', '=', 'h.old_tenant_id')
            ->leftJoin('tenants as new_t', 'new_t.id', '=', 'h.new_tenant_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where('h.market_space_id', (int) $record->id)
            ->orderByDesc('h.changed_at')
            ->limit(200)
            ->get([
                'h.changed_at',
                'old_t.name as old_name',
                'old_t.short_name as old_short_name',
                'new_t.name as new_name',
                'new_t.short_name as new_short_name',
                'u.name as user_name',
            ]);

        $items = $rows->map(function ($row): array {
            $oldShort = trim((string) ($row->old_short_name ?? ''));
            $newShort = trim((string) ($row->new_short_name ?? ''));

            $oldLabel = $oldShort !== '' ? $oldShort : trim((string) ($row->old_name ?? ''));
            $newLabel = $newShort !== '' ? $newShort : trim((string) ($row->new_name ?? ''));

            return [
                'changed_at' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y H:i') : '—',
                'old_label' => $oldLabel !== '' ? $oldLabel : '—',
                'new_label' => $newLabel !== '' ? $newLabel : '—',
                'user_name' => $row->user_name ? (string) $row->user_name : '—',
            ];
        })->all();

        return new HtmlString(view('filament.market-spaces.tenant-history', [
            'items' => $items,
        ])->render());
    }

    private static function renderRentRateHistory(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">История появится после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('market_space_rent_rate_histories')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица истории ставки ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('market_space_rent_rate_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where('h.market_space_id', (int) $record->id)
            ->orderByDesc('h.changed_at')
            ->limit(200)
            ->get([
                'h.changed_at',
                'h.old_value',
                'h.new_value',
                'h.unit',
                'h.note',
                'u.name as user_name',
            ]);

        $items = $rows->map(function ($row): array {
            return [
                'changed_at' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y H:i') : '—',
                'old_value' => $row->old_value !== null ? (float) $row->old_value : null,
                'new_value' => $row->new_value !== null ? (float) $row->new_value : null,
                'unit_label' => $row->unit ? static::rentRateUnitLabel((string) $row->unit) : '—',
                'note' => $row->note ? (string) $row->note : '',
                'user_name' => $row->user_name ? (string) $row->user_name : '—',
            ];
        })->all();

        $chartRows = DB::table('market_space_rent_rate_histories')
            ->where('market_space_id', (int) $record->id)
            ->whereNotNull('new_value')
            ->orderBy('changed_at')
            ->get(['changed_at', 'new_value', 'unit']);

        $chart = $chartRows->map(function ($row): array {
            return [
                'label' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y') : '',
                'value' => (float) $row->new_value,
                'unit' => $row->unit ? (string) $row->unit : null,
            ];
        })->all();

        $unitLabel = static::rentRateUnitLabel($record->rent_rate_unit);

        return new HtmlString(view('filament.market-spaces.rent-rate-history', [
            'items' => $items,
            'chart' => $chart,
            'unitLabel' => $unitLabel,
        ])->render());
    }

    private static function renderOperations(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Операции появятся после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('operations')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица операций ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('operations')
            ->where('market_id', (int) $record->market_id)
            ->whereIn('entity_type', ['market_space', MarketSpace::class])
            ->where('entity_id', (int) $record->id)
            ->orderByRaw('COALESCE(effective_at, created_at) desc')
            ->limit(50)
            ->get([
                'id',
                'effective_at',
                'created_at',
                'type',
                'status',
                'payload',
                'comment',
                'created_by',
            ]);

        $creatorIds = $rows->pluck('created_by')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $tenantIds = $rows
            ->flatMap(function ($row): array {
                $payload = is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);

                return [
                    $payload['from_tenant_id'] ?? null,
                    $payload['to_tenant_id'] ?? null,
                ];
            })
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $creators = $creatorIds === []
            ? collect()
            : DB::table('users')
                ->whereIn('id', $creatorIds)
                ->pluck('name', 'id');

        $tenantNames = $tenantIds === []
            ? collect()
            : DB::table('tenants')
                ->whereIn('id', $tenantIds)
                ->get(['id', 'name', 'short_name'])
                ->mapWithKeys(static function ($tenant): array {
                    $shortName = trim((string) ($tenant->short_name ?? ''));
                    $name = trim((string) ($tenant->name ?? ''));

                    return [(int) $tenant->id => $shortName !== '' ? $shortName : ($name !== '' ? $name : ('#'.(int) $tenant->id))];
                });

        $items = $rows->map(function ($row) use ($tenantNames): array {
            $payload = is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
            $type = (string) ($row->type ?? '');
            $event = static::buildOperationEvent($type, $payload, $tenantNames->all());
            $date = $row->effective_at ?: $row->created_at;

            $item = [
                'effective_at' => $date ? (string) \Carbon\Carbon::parse($date)->format('d.m.Y H:i') : '—',
                'title' => $event['title'],
                'details' => $event['details'],
                'comment' => static::resolveOperationComment($type, $payload, $row->comment ?? null),
                'status_label' => static::operationStatusLabel((string) $row->status),
                'status_color' => static::operationStatusColor((string) $row->status),
            ];

            return $item;
        })->values();

        $items = $items->map(function (array $item) use ($rows, $creators): array {
            $row = $rows->shift();
            $authorName = $row && filled($row->created_by)
                ? (string) ($creators[(int) $row->created_by] ?? '—')
                : '—';

            $item['author_name'] = $authorName;

            return $item;
        })->all();

        return new HtmlString(view('filament.market-spaces.operations', [
            'items' => $items,
            'spaceId' => (int) $record->id,
            'reviewUrl' => route('filament.admin.market-map', [
                'mode' => 'review',
                'return_url' => request()->fullUrl(),
            ]),
        ])->render());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tenantNames
     * @return array{title:string,details:string}
     */
    private static function buildOperationEvent(string $type, array $payload, array $tenantNames): array
    {
        if ($type === \App\Domain\Operations\OperationType::TENANT_SWITCH) {
            $fromTenantId = (int) ($payload['from_tenant_id'] ?? 0);
            $toTenantId = (int) ($payload['to_tenant_id'] ?? 0);
            $fromTenant = $fromTenantId > 0 ? ($tenantNames[$fromTenantId] ?? ('#'.$fromTenantId)) : 'не было арендатора';
            $toTenant = $toTenantId > 0 ? ($tenantNames[$toTenantId] ?? ('#'.$toTenantId)) : 'место свободно';

            $details = 'Было: '.$fromTenant.'. Стало: '.$toTenant.'.';

            if ((bool) ($payload['detach_from_group'] ?? false)) {
                $details .= ' Место выведено из группы.';
            }

            $title = match (true) {
                $fromTenantId <= 0 && $toTenantId > 0 => 'Место занято',
                $toTenantId > 0 => 'Арендатор изменён',
                default => 'Место освобождено',
            };

            return [
                'title' => $title,
                'details' => $details,
            ];
        }

        if ($type === \App\Domain\Operations\OperationType::RENT_RATE_CHANGE) {
            $fromRate = static::moneyOrDash($payload['from_rent_rate'] ?? null);
            $toRate = static::moneyOrDash($payload['rent_rate'] ?? null);
            $unit = static::stringOrNull($payload['unit'] ?? null);
            $unitLabel = $unit !== null ? static::rentRateUnitLabel($unit) : null;

            return [
                'title' => 'Ставка аренды изменена',
                'details' => trim('Было: '.$fromRate.'. Стало: '.$toRate.'.'.($unitLabel ? ' Единица: '.$unitLabel.'.' : '')),
            ];
        }

        if ($type === \App\Domain\Operations\OperationType::SPACE_ATTRS_CHANGE) {
            return static::buildSpaceAttrsEvent($payload);
        }

        if ($type === \App\Domain\Operations\OperationType::SPACE_REVIEW) {
            $decision = trim((string) ($payload['decision'] ?? ''));
            $title = static::spaceReviewEventTitle($decision);
            $details = static::buildReviewSummary($decision, $payload);

            $observedTenant = static::stringOrNull($payload['observed_tenant_name'] ?? null);
            if ($observedTenant !== null) {
                $details .= ' Фактический арендатор: '.$observedTenant.'.';
            }

            return [
                'title' => $title,
                'details' => $details,
            ];
        }

        if ($type === \App\Domain\Operations\OperationType::GROUP_MEMBERSHIP) {
            return [
                'title' => 'Состав группы изменён',
                'details' => static::buildGroupMembershipEventDetails($payload),
            ];
        }

        if ($type === \App\Domain\Operations\OperationType::ELECTRICITY_INPUT) {
            $amount = is_numeric($payload['amount'] ?? null) ? number_format((float) $payload['amount'], 2, ',', ' ') : '—';
            $unit = static::stringOrNull($payload['unit'] ?? null) ?? 'кВт·ч';

            return [
                'title' => 'Внесены показания электроэнергии',
                'details' => 'Объём: '.$amount.' '.$unit.'.',
            ];
        }

        if ($type === \App\Domain\Operations\OperationType::ACCRUAL_ADJUSTMENT) {
            $amount = static::moneyOrDash($payload['amount_delta'] ?? null);

            return [
                'title' => 'Начисление скорректировано',
                'details' => 'Изменение суммы: '.$amount.'.',
            ];
        }

        return [
            'title' => 'Изменение по месту',
            'details' => 'Запись внутреннего журнала сохранена.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title:string,details:string}
     */
    private static function buildSpaceAttrsEvent(array $payload): array
    {
        $parts = [];

        if (array_key_exists('status', $payload)) {
            $parts[] = 'Статус: '.(static::statusLabel(static::stringOrNull($payload['status'] ?? null)) ?? 'не указан');
        }

        if (array_key_exists('is_active', $payload)) {
            $parts[] = 'Активность: '.((bool) ($payload['is_active'] ?? false) ? 'активно' : 'упразднено');
        }

        if (array_key_exists('number', $payload)) {
            $parts[] = 'Номер: '.(static::stringOrNull($payload['number'] ?? null) ?? 'не указан');
        }

        if (array_key_exists('display_name', $payload)) {
            $parts[] = 'Название: '.(static::stringOrNull($payload['display_name'] ?? null) ?? 'не указано');
        }

        if (array_key_exists('area_sqm', $payload)) {
            $area = is_numeric($payload['area_sqm'] ?? null)
                ? number_format((float) $payload['area_sqm'], 2, ',', ' ')
                : 'не указана';
            $parts[] = 'Площадь: '.$area.' м²';
        }

        if (array_key_exists('activity_type', $payload)) {
            $parts[] = 'Вид деятельности: '.(static::stringOrNull($payload['activity_type'] ?? null) ?? 'не указан');
        }

        $title = match ((string) ($payload['status'] ?? '')) {
            'vacant' => 'Место отмечено свободным',
            'maintenance' => 'Место отмечено служебным',
            default => array_key_exists('is_active', $payload) && ! (bool) ($payload['is_active'] ?? false)
                ? 'Место упразднено'
                : 'Данные места изменены',
        };

        return [
            'title' => $title,
            'details' => $parts !== [] ? implode('; ', $parts).'.' : 'Сохранены изменения по месту.',
        ];
    }

    private static function spaceReviewEventTitle(string $decision): string
    {
        return match ($decision) {
            \App\Domain\Operations\SpaceReviewDecision::MARK_SPACE_FREE => 'Место отмечено свободным',
            \App\Domain\Operations\SpaceReviewDecision::MARK_SPACE_SERVICE => 'Место отмечено служебным',
            \App\Domain\Operations\SpaceReviewDecision::TENANT_CHANGED_ON_SITE => 'На месте другой арендатор',
            \App\Domain\Operations\SpaceReviewDecision::OCCUPANCY_CONFLICT => 'Зафиксировано расхождение',
            \App\Domain\Operations\SpaceReviewDecision::BIND_SHAPE_TO_SPACE => 'Фигура привязана к месту',
            \App\Domain\Operations\SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE => 'Фигура отвязана от места',
            \App\Domain\Operations\SpaceReviewDecision::FIX_SPACE_IDENTITY => 'Данные места уточнены',
            \App\Domain\Operations\SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION => 'Разобран дубль места',
            \App\Domain\Operations\SpaceReviewDecision::HISTORICAL_COMPOSED_SPACE_REVIEWED => 'Историческое составное место проверено',
            \App\Domain\Operations\SpaceReviewDecision::CONFIRM_UNCONFIRMED_FINANCIAL_LINK => 'Финансовая связь подтверждена',
            \App\Domain\Operations\SpaceReviewDecision::REJECT_UNCONFIRMED_FINANCIAL_LINK => 'Финансовая связь отклонена',
            \App\Domain\Operations\SpaceReviewDecision::REOPEN_UNCONFIRMED_FINANCIAL_LINK => 'Финансовая связь возвращена в проверку',
            default => 'Ревизионное решение',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildGroupMembershipEventDetails(array $payload): string
    {
        $action = (string) ($payload['action'] ?? '');
        $parts = [];

        $parts[] = match ($action) {
            'add_to_group' => 'Место добавлено в группу',
            'move_to_group' => 'Место перенесено в другую группу',
            'remove_from_group' => 'Место убрано из группы',
            default => 'Состав группы изменён',
        };

        $newParentId = (int) ($payload['new_space_group_parent_id'] ?? $payload['target_parent_id'] ?? 0);
        $oldParentId = (int) ($payload['old_space_group_parent_id'] ?? 0);
        $newPosition = static::stringOrNull($payload['new_space_group_slot'] ?? $payload['target_slot'] ?? null);
        $oldPosition = static::stringOrNull($payload['old_space_group_slot'] ?? null);

        if ($oldParentId > 0) {
            $parts[] = 'прежняя группа: #'.$oldParentId;
        }

        if ($newParentId > 0) {
            $parts[] = 'новая группа: #'.$newParentId;
        }

        if ($oldPosition !== null) {
            $parts[] = 'прежняя позиция: '.$oldPosition;
        }

        if ($newPosition !== null) {
            $parts[] = 'новая позиция: '.$newPosition;
        }

        return implode('; ', $parts).'.';
    }

    private static function operationStatusLabel(string $status): string
    {
        return match ($status) {
            'applied' => 'Выполнено',
            'observed' => 'Наблюдение',
            'draft' => 'Черновик',
            'canceled', 'cancelled' => 'Отменено',
            default => $status !== '' ? $status : 'Записано',
        };
    }

    private static function operationStatusColor(string $status): string
    {
        return match ($status) {
            'applied' => 'success',
            'observed' => 'warning',
            'draft' => 'gray',
            'canceled', 'cancelled' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolveOperationComment(string $type, array $payload, mixed $fallbackComment): ?string
    {
        if ($type === \App\Domain\Operations\OperationType::SPACE_REVIEW) {
            return static::resolveReviewReason($payload, $fallbackComment);
        }

        return static::stringOrNull($payload['reason'] ?? null)
            ?? static::stringOrNull($payload['user_comment'] ?? null)
            ?? static::stringOrNull($fallbackComment);
    }

    private static function moneyOrDash(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, 2, ',', ' ').' ₽';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildReviewSummary(string $decision, array $payload): string
    {
        return match ($decision) {
            'matched' => 'Совпало с данными системы.',
            \App\Domain\Operations\SpaceReviewDecision::OCCUPANCY_CONFLICT => 'Зафиксирован конфликт по занятости.',
            \App\Domain\Operations\SpaceReviewDecision::TENANT_CHANGED_ON_SITE => 'Зафиксировано, что на месте другой арендатор.',
            \App\Domain\Operations\SpaceReviewDecision::SHAPE_NOT_FOUND => 'Зафиксировано, что место не найдено на карте.',
            \App\Domain\Operations\SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION => 'Место помечено как требующее уточнения.',
            \App\Domain\Operations\SpaceReviewDecision::MARK_SPACE_FREE => 'Место отмечено как свободное.',
            \App\Domain\Operations\SpaceReviewDecision::MARK_SPACE_SERVICE => 'Место отмечено как служебное.',
            \App\Domain\Operations\SpaceReviewDecision::BIND_SHAPE_TO_SPACE => 'Фигура привязана к месту.'
                .(filled($payload['shape_id'] ?? null) ? ' Фигура №'.(int) $payload['shape_id'].'.' : ''),
            \App\Domain\Operations\SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE => 'Фигура отвязана от места.'
                .(filled($payload['shape_id'] ?? null) ? ' Фигура №'.(int) $payload['shape_id'].'.' : ''),
            \App\Domain\Operations\SpaceReviewDecision::FIX_SPACE_IDENTITY => static::buildIdentitySummary($payload),
            \App\Domain\Operations\SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION => static::buildDuplicateSummary($payload),
            default => 'Ревизионное решение зафиксировано.',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildIdentitySummary(array $payload): string
    {
        $parts = [];
        $number = static::stringOrNull($payload['number'] ?? null);
        $displayName = static::stringOrNull($payload['display_name'] ?? null);

        if ($number !== null) {
            $parts[] = 'Номер: '.$number;
        }

        if ($displayName !== null) {
            $parts[] = 'Название: '.$displayName;
        }

        if ($parts === []) {
            return 'Данные места уточнены.';
        }

        return 'Данные места уточнены. '.implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildDuplicateSummary(array $payload): string
    {
        $candidateSpaceId = (int) ($payload['candidate_market_space_id'] ?? 0);

        if ($candidateSpaceId <= 0) {
            return 'Выполнен разбор дубля места.';
        }

        return 'Выполнен разбор дубля: основное место #'.$candidateSpaceId.'.';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolveReviewReason(array $payload, mixed $fallbackComment): ?string
    {
        $reason = static::stringOrNull($payload['reason'] ?? null);
        if ($reason !== null) {
            return $reason;
        }

        return static::stringOrNull($fallbackComment);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $state = request()->input('tableFilters.activity_scope.value');
                if (SchemaFacade::hasTable('market_space_map_shapes')) {
                    $query->withCount([
                        'mapShapes as active_map_shapes_count' => function (Builder $shapeQuery): void {
                            if (SchemaFacade::hasColumn('market_space_map_shapes', 'is_active')) {
                                $shapeQuery->where('is_active', true);
                            }
                        },
                    ]);
                }

                return match ($state) {
                    'all' => $query,
                    'inactive' => $query->where('is_active', false),
                    default => $query->where('is_active', true),
                };
            })
            ->columns([
                TextColumn::make('location.name')
                    ->label('Локация')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->tooltip(fn (MarketSpace $record) => $record->location?->name ?: null),

                TextColumn::make('effective_tenant')
                    ->label('Арендатор')
                    ->state(fn (MarketSpace $record): ?string => static::tableEffectiveTenantName($record))
                    ->description(fn (MarketSpace $record): ?string => static::tableEffectiveTenantHint($record))
                    ->placeholder('—')
                    ->tooltip(function (MarketSpace $record): ?string {
                        $tenantName = static::tableEffectiveTenantName($record);
                        $hint = static::tableEffectiveTenantHint($record);

                        if (! $tenantName) {
                            return null;
                        }

                        return $hint ? ($tenantName.' ('.$hint.')') : $tenantName;
                    }),

                TextColumn::make('display_name')
                    ->label('Название')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable()
                    ->tooltip(fn (MarketSpace $record) => $record->display_name ?: null),

                TextColumn::make('number')
                    ->label('Номер')
                    ->sortable()
                    ->searchable()
                    ->tooltip(fn (MarketSpace $record) => $record->number ?: null),

                // Тарифная категория — отдельная сущность от статуса занятости, группы и локации.
                TextColumn::make('type')
                    ->label('Тарифная категория')
                    ->formatStateUsing(fn (?string $state, MarketSpace $record) => static::resolveSpaceTypeLabel($record->market_id, $state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable()
                    ->tooltip('Категория для тарифов и отчётности. Не влияет на занятость, служебность, группу или совместное использование.'),

                TextColumn::make('activity_type')
                    ->label('Вид деятельности')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->tooltip(fn (MarketSpace $record) => $record->activity_type ?: null),

                TextColumn::make('space_group_token')
                    ->label('Группа мест')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('space_group_slot')
                    ->label('Номер в группе')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('map_shape_policy')
                    ->label('Фигура карты')
                    ->state(fn (MarketSpace $record): string => MarketSpaceShapePolicy::requirementFor($record)['label'])
                    ->badge()
                    ->color(fn (MarketSpace $record): string => MarketSpaceShapePolicy::requirementFor($record)['color'])
                    ->tooltip(fn (MarketSpace $record): string => MarketSpaceShapePolicy::requirementFor($record)['tooltip'])
                    ->toggleable(),

                TextColumn::make('effective_occupancy')
                    ->label('Фактическая занятость')
                    ->state(fn (MarketSpace $record): ?string => static::tableEffectiveStatusLabel($record))
                    ->badge()
                    ->color(fn (MarketSpace $record): string => static::tableEffectiveStatusColor($record))
                    ->tooltip(fn (MarketSpace $record): ?string => static::tableEffectiveStatusTooltip($record)),

                TextColumn::make('one_c_financial_status')
                    ->label('Финансы 1С')
                    ->state(fn (MarketSpace $record): string => static::tableFinancialStatusMeta($record)['label'])
                    ->badge()
                    ->color(fn (MarketSpace $record): string => static::tableFinancialStatusMeta($record)['color'])
                    ->tooltip(fn (MarketSpace $record): string => static::tableFinancialStatusMeta($record)['tooltip'])
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Прямой статус места')
                    ->formatStateUsing(fn (?string $state) => static::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state) => static::statusColor($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(function (MarketSpace $record) {
                        $label = static::statusLabel($record->status);

                        return $label ? "Прямой статус: {$label}" : null;
                    }),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->tooltip(fn (MarketSpace $record) => $record->is_active ? 'Активно' : 'Неактивно'),
            ])
            ->filters([
                SelectFilter::make('activity_scope')
                    ->label('Показ')
                    ->default('active')
                    ->options([
                        'active' => 'Только активные',
                        'inactive' => 'Только архивные',
                        'all' => 'Все записи',
                    ])
                    ->query(fn (Builder $query): Builder => $query),

                SelectFilter::make('effective_occupancy')
                    ->label('Фактическая занятость')
                    ->options([
                        'occupied' => 'Занято',
                        'vacant' => 'Свободно',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === 'occupied') {
                            return $query->where(function (Builder $query): void {
                                $query
                                    ->whereNotNull('tenant_id')
                                    ->orWhere(function (Builder $query): void {
                                        $query
                                            ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                            ->whereHas('spaceGroupParent', fn (Builder $parentQuery): Builder => $parentQuery->whereNotNull('tenant_id'));
                                    });
                            });
                        }

                        if ($value === 'vacant') {
                            return $query->where(function (Builder $query): void {
                                $query
                                    ->whereNull('tenant_id')
                                    ->where(function (Builder $query): void {
                                        $query
                                            ->where('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                            ->orWhere(function (Builder $query): void {
                                                $query
                                                    ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                                    ->whereDoesntHave('spaceGroupParent', fn (Builder $parentQuery): Builder => $parentQuery->whereNotNull('tenant_id'));
                                            });
                                    });
                            });
                        }

                        return $query;
                    }),

                SelectFilter::make('status')
                    ->label('Прямой статус места')
                    ->options([
                        'vacant' => 'Свободно',
                        'occupied' => 'Занято',
                        'reserved' => 'Зарезервировано',
                        'maintenance' => 'Служебное место',
                    ]),

                SelectFilter::make('type')
                    ->label('Тарифная категория')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        return MarketSpaceType::query()
                            ->where('market_id', (int) $marketId)
                            ->where('is_active', true)
                            ->orderBy('name_ru')
                            ->pluck('name_ru', 'code')
                            ->all();
                    }),

                SelectFilter::make('activity_type')
                    ->label('Вид деятельности')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        return DB::table('market_spaces')
                            ->where('market_id', (int) $marketId)
                            ->whereNotNull('activity_type')
                            ->where('activity_type', '!=', '')
                            ->distinct()
                            ->orderBy('activity_type')
                            ->pluck('activity_type', 'activity_type')
                            ->all();
                    }),

                SelectFilter::make('space_group_token')
                    ->label('Группа мест')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        if (! SchemaFacade::hasColumn('market_spaces', 'space_group_token')) {
                            return [];
                        }

                        return DB::table('market_spaces')
                            ->where('market_id', (int) $marketId)
                            ->whereNotNull('space_group_token')
                            ->where('space_group_token', '!=', '')
                            ->distinct()
                            ->orderBy('space_group_token')
                            ->pluck('space_group_token', 'space_group_token')
                            ->all();
                    }),
            ])
            ->recordUrl(fn (MarketSpace $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        // Icon-only actions (no text), keep tooltips for usability
        if (class_exists(\Filament\Actions\EditAction::class)) {
            $editAction = \Filament\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->iconButton();

            if (method_exists($editAction, 'slideOver')) {
                $editAction->slideOver();
            }

            if (method_exists($editAction, 'modalWidth')) {
                $editAction->modalWidth('7xl');
            }

            $actions[] = $editAction;
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $editAction = \Filament\Tables\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->iconButton();

            if (method_exists($editAction, 'slideOver')) {
                $editAction->slideOver();
            }

            if (method_exists($editAction, 'modalWidth')) {
                $editAction->modalWidth('7xl');
            }

            $actions[] = $editAction;
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
                ->visible(fn (MarketSpace $record): bool => static::canDelete($record))
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
                ->visible(fn (MarketSpace $record): bool => static::canDelete($record))
                ->iconButton();
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketSpaces::route('/'),
            'create' => Pages\CreateMarketSpace::route('/create'),
            'edit' => Pages\EditMarketSpace::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        $applyOnlyVacant = static function (Builder $builder): Builder {
            if (! request()->boolean('only_vacant')) {
                return $builder;
            }

            return $builder->where('status', 'vacant');
        };

        if (! $user) {
            return $applyOnlyVacant($query->whereRaw('1 = 0'));
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            $query = filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;

            return $applyOnlyVacant($query);
        }

        if ($user->market_id) {
            return $applyOnlyVacant($query->where('market_id', $user->market_id));
        }

        return $applyOnlyVacant($query->whereRaw('1 = 0'));
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $record->market_id === $user->market_id;
    }

    public static function canDelete($record): bool
    {
        if (! $record instanceof MarketSpace) {
            return false;
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! $user->isSuperAdmin()) {
            return false;
        }

        return static::deleteDependencyCounts($record) === [];
    }

    private static function renderGroupComposition(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Состав группы появится после сохранения торгового места.</div>');
        }

        if ((string) ($record->space_group_role ?? '') !== MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            return new HtmlString('');
        }

        // Получаем арендатора parent-группы (short_name > name)
        $parentTenantId = $record->tenant_id;
        $parentTenantName = null;
        if ($parentTenantId) {
            $parentTenantData = DB::table('tenants')
                ->where('id', (int) $parentTenantId)
                ->select('name', 'short_name')
                ->first();

            if ($parentTenantData) {
                // Приоритет: short_name > name
                $parentTenantName = trim((string) ($parentTenantData->short_name ?? ''));
                if ($parentTenantName === '') {
                    $parentTenantName = trim((string) ($parentTenantData->name ?? ''));
                }
                $parentTenantName = $parentTenantName !== '' ? $parentTenantName : null;
            }
        }

        $children = DB::table('market_spaces as ms')
            ->leftJoin('tenants as t', 't.id', '=', 'ms.tenant_id')
            ->where('ms.market_id', (int) $record->market_id)
            ->where('ms.space_group_parent_id', (int) $record->id)
            ->where('ms.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->where('ms.is_active', true)
            ->orderBy('ms.id')
            ->get([
                'ms.id',
                'ms.number',
                'ms.display_name',
                'ms.space_group_slot',
                'ms.status',
                'ms.tenant_id',
                't.name as tenant_name',
                't.short_name as tenant_short_name',
            ])
            ->map(function ($child): array {
                $status = $child->status ?? 'vacant';
                $status = $status === 'free' ? 'vacant' : $status;

                $editUrl = static::getUrl('edit', ['record' => (int) $child->id]);

                // Вычисляем человекочитаемое имя child tenant (short_name > name)
                $childTenantName = null;
                if ($child->tenant_id) {
                    $shortName = trim((string) ($child->tenant_short_name ?? ''));
                    $name = trim((string) ($child->tenant_name ?? ''));

                    if ($shortName !== '') {
                        $childTenantName = $shortName;
                    } elseif ($name !== '') {
                        $childTenantName = $name;
                    }
                }

                return [
                    'id' => (int) $child->id,
                    'slot' => $child->space_group_slot ? trim((string) $child->space_group_slot) : '',
                    'number' => $child->number ? trim((string) $child->number) : '—',
                    'display_name' => $child->display_name ? trim((string) $child->display_name) : '—',
                    'child_tenant_name' => $childTenantName,
                    'child_tenant_id' => $child->tenant_id ? (int) $child->tenant_id : null,
                    'status' => $status,
                    'edit_url' => $editUrl,
                ];
            })
            ->sortBy(function ($child) {
                $slot = $child['slot'];
                $number = $child['number'];
                $id = $child['id'];

                // Пустой слот — в конец (очень большое число)
                if ($slot === '') {
                    return [1, PHP_INT_MAX, '', PHP_INT_MAX, PHP_INT_MAX];
                }

                // Numeric slot для сортировки
                $numericSlot = (int) $slot;

                // Number для fallback-сортировки
                $numericNumber = (int) $number;

                // Сортировка: empty -> numeric slot -> slot как строка -> number -> id
                return [0, $numericSlot, $slot, $numericNumber, $id];
            })
            ->values()
            ->map(function ($child) use ($parentTenantId, $parentTenantName): array {
                // Сохраняем сырой статус
                $rawStatus = $child['status'] ?? 'vacant';

                // Вычисляем effective occupancy
                $hasChildTenant = (bool) $child['child_tenant_id'];
                $hasParentTenant = (bool) $parentTenantId;

                // Приоритет вычисления занятости:
                // 1. child tenant есть -> Занято напрямую
                // 2. parent tenant есть -> Занято через группу
                // 3. rawStatus === reserved -> Зарезервировано
                // 4. rawStatus === maintenance -> Служебное место
                // 5. иначе -> Свободно

                if ($hasChildTenant) {
                    // child tenant есть
                    $tenantName = $child['child_tenant_name'] ?? 'Не указан';
                    $statusLabel = 'Занято напрямую';
                    $statusColor = 'success';
                    $occupancyStatus = 'occupied_direct';
                } elseif ($hasParentTenant) {
                    // child tenant нет, parent tenant есть
                    $tenantName = $parentTenantName ?? 'Не указан';
                    $statusLabel = 'Занято через группу';
                    $statusColor = 'success';
                    $occupancyStatus = 'occupied_via_group';
                } elseif ($rawStatus === 'reserved') {
                    $tenantName = 'Не указан';
                    $statusLabel = 'Зарезервировано';
                    $statusColor = 'warning';
                    $occupancyStatus = 'reserved';
                } elseif ($rawStatus === 'maintenance') {
                    $tenantName = 'Не указан';
                    $statusLabel = 'Служебное место';
                    $statusColor = 'gray';
                    $occupancyStatus = 'maintenance';
                } else {
                    $tenantName = 'Не указан';
                    $statusLabel = 'Свободно';
                    $statusColor = 'danger';
                    $occupancyStatus = 'vacant';
                }

                return [
                    'id' => $child['id'],
                    'slot' => $child['slot'] !== '' ? $child['slot'] : '—',
                    'number' => $child['number'],
                    'display_name' => $child['display_name'],
                    'tenant_name' => $tenantName,
                    'status_label' => $statusLabel,
                    'status_color' => $statusColor,
                    'occupancy_status' => $occupancyStatus,
                    'edit_url' => $child['edit_url'],
                ];
            })
            ->all();

        return new HtmlString(view('filament.market-spaces.group-composition', [
            'children' => $children,
            'hasChildren' => count($children) > 0,
        ])->render());
    }

    /**
     * @return array<string, int>
     */
    public static function deleteDependencyCounts(MarketSpace $record): array
    {
        $counts = [];

        if (filled($record->tenant_id)) {
            $counts['tenant_id'] = 1;
        }

        $recordId = (int) $record->getKey();

        if ($recordId <= 0) {
            $counts['invalid_record'] = 1;

            return $counts;
        }

        foreach (static::deleteDependencyChecks() as [$table, $column]) {
            if (! SchemaFacade::hasTable($table) || ! SchemaFacade::hasColumn($table, $column)) {
                continue;
            }

            $count = (int) DB::table($table)->where($column, $recordId)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        if (
            SchemaFacade::hasTable('operations')
            && SchemaFacade::hasColumn('operations', 'entity_type')
            && SchemaFacade::hasColumn('operations', 'entity_id')
        ) {
            $count = (int) DB::table('operations')
                ->where('entity_type', 'market_space')
                ->where('entity_id', $recordId)
                ->count();

            if ($count > 0) {
                $counts['operations'] = $count;
            }
        }

        return $counts;
    }

    public static function canDeleteWithMapShapeCascade($record): bool
    {
        if (! $record instanceof MarketSpace) {
            return false;
        }

        $user = Filament::auth()->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return false;
        }

        $counts = static::deleteDependencyCounts($record);

        if (! isset($counts['market_space_map_shapes'])) {
            return false;
        }

        foreach (array_keys($counts) as $key) {
            if (! in_array($key, ['market_space_map_shapes', 'operations'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function deleteDependencyChecks(): array
    {
        return [
            ['tenant_contracts', 'market_space_id'],
            ['tenant_requests', 'market_space_id'],
            ['tenant_accruals', 'market_space_id'],
            ['market_space_map_shapes', 'market_space_id'],
            ['market_space_tenant_histories', 'market_space_id'],
            ['market_space_rent_rate_histories', 'market_space_id'],
            ['market_space_tenant_bindings', 'market_space_id'],
            ['tenant_user_market_spaces', 'market_space_id'],
            ['tenant_space_showcases', 'market_space_id'],
            ['marketplace_products', 'market_space_id'],
            ['marketplace_chats', 'market_space_id'],
            ['tickets', 'market_space_id'],
            ['tenant_reviews', 'market_space_id'],
        ];
    }

    private static function hasDeleteDependencies(MarketSpace $record): bool
    {
        return static::deleteDependencyCounts($record) !== [];
    }
}
