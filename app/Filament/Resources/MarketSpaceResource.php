<?php
# app/Filament/Resources/MarketSpaceResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\MarketLocation;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationsStateService;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
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
            $options[$currentTypeCode] = 'Старое значение (код ' . $currentTypeCode . ')';
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
            'maintenance' => 'На обслуживании',
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
                . '<div style="font-size:13px;font-weight:700;color:#0f172a;">Свободно</div>'
                . '<div style="font-size:13px;opacity:.88;">Арендатор не назначен</div>'
                . '<div style="font-size:12px;opacity:.7;">Место пока не занято</div>'
                . '</div>'
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
            $sourceLabel = '#' . (int) $sourceSpace->id;
        }

        $tenantLabel = $tenantName ?: '—';
        $title = 'Занято';
        $sourceLabelText = $source === 'parent'
            ? 'Арендатор наследуется от группы.'
            : 'Арендатор указан у этого места.';

        $html = '<div style="display:grid;gap:4px;">'
            . '<div style="font-size:13px;font-weight:700;color:#0f172a;">' . e($title) . '</div>'
            . '<div style="font-size:13px;opacity:.88;">Арендатор: ' . e($tenantLabel) . '</div>'
            . '<div style="font-size:12px;opacity:.7;">' . e($sourceLabelText) . '</div>'
            . '</div>';
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
            $parentLabel = '#' . (int) $parent->id;
        }

        if ($parentLabel === '') {
            $parentLabel = '#' . (int) ($record?->space_group_parent_id ?? 0);
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
                . '<div style="font-size:12px;font-weight:700;color:#64748b;">' . e($label) . '</div>'
                . '<div style="font-size:13px;font-weight:700;color:#0f172a;">' . e($value) . '</div>'
                . '</div>';
        }

        $openParentButton = $parentUrl
            ? '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
                . '<a href="' . e($parentUrl) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#2563eb;color:#fff;font-size:12px;font-weight:800;padding:8px 12px;text-decoration:none;">Открыть карточку группы</a>'
                . '</div>'
            : '';

        return new HtmlString(
            '<div style="display:grid;gap:10px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e293b;">'
            . '<div style="font-size:13px;font-weight:800;color:#1d4ed8;">Это место входит в группу</div>'
            . $rows
            . $openParentButton
            . '<div style="font-size:12px;line-height:1.45;color:#475569;">Родительская группа и номер внутри группы меняются через действие «Перенести в группу», а не через обычные поля карточки.</div>'
            . '</div>'
        );
    }

    private static function hasSharedUseTenants(?MarketSpace $record): bool
    {
        return static::sharedUseTenantRows($record) !== [];
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
                    'source' => trim((string) ($row->source ?? '')),
                ];
            })
            ->values()
            ->all();
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
            if (! empty($row['started_at'])) {
                $meta[] = 'с ' . \Carbon\Carbon::parse($row['started_at'])->format('d.m.Y');
            }
            if (! empty($row['source'])) {
                $meta[] = 'источник: ' . $row['source'];
            }

            $items .= '<li style="display:grid;gap:2px;padding:7px 0;border-top:1px solid rgba(37,99,235,.14);">'
                . '<div style="font-size:13px;font-weight:800;color:#0f172a;">' . e((string) $row['tenant_name']) . '</div>'
                . ($meta !== [] ? '<div style="font-size:12px;color:#475569;">' . e(implode(' ? ', $meta)) . '</div>' : '')
                . '</li>';
        }

        return new HtmlString(
            '<div style="display:grid;gap:8px;padding:12px 14px;border:1px solid #93c5fd;border-radius:12px;background:#eff6ff;color:#1e293b;">'
            . '<div style="font-size:13px;font-weight:900;color:#1d4ed8;">Место используют несколько арендаторов</div>'
            . '<div style="font-size:12px;line-height:1.45;color:#475569;">Это shared-use: несколько арендаторов используют одно физическое место.</div>'
            . '<ul style="display:grid;gap:0;margin:0;padding:0;list-style:none;">' . $items . '</ul>'
            . '</div>'
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
            . '<div style="font-size:13px;font-weight:800;">' . e('У этого места есть активная фигура карты') . '</div>'
            . '<div style="font-size:12px;line-height:1.45;">' . e('Parent-группа не должна иметь обычную фигуру карты. Выберите, что сделать с фигурой при сохранении.') . '</div>'
            . '<div style="font-size:12px;line-height:1.45;">' . e('Активных фигур: ' . $shapeCount) . '</div>'
            . '</div>'
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
            $sourceLabel = '#' . (int) ($sourceSpace?->id ?? 0);
        }

        return new HtmlString(
            '<div style="display:grid;gap:4px;">'
            . '<div style="font-size:13px;font-weight:600;color:#0f172a;">' . e($tenantName !== '' ? $tenantName : '—') . '</div>'
            . '<div style="font-size:12px;opacity:.7;">Наследуется от группы ' . e($sourceLabel) . '</div>'
            . '</div>'
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
            $label = '#' . (int) $parent->id;
        }

        if ($label === '') {
            $label = '—';
        }

        return new HtmlString(
            '<div style="display:grid;gap:4px;">'
            . '<div style="font-size:14px;font-weight:600;color:#0f172a;">' . e($label) . '</div>'
            . '<div style="font-size:12px;opacity:.7;">Изменяется через действие «Перенести в группу» в шапке карточки.</div>'
            . '</div>'
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
            . '<div style="font-size:14px;font-weight:600;color:#0f172a;">' . e($slot) . '</div>'
            . '<div style="font-size:12px;opacity:.7;">Изменяется через действие «Перенести в группу» в шапке карточки.</div>'
            . '</div>'
        );
    }

    private static function renderPriorityCard(string $label, string $value, ?string $note = null, string $tone = 'default'): HtmlString
    {
        $noteHtml = filled($note)
            ? '<div class="market-space-priority-card__note">' . e($note) . '</div>'
            : '';

        return new HtmlString(
            '<div class="market-space-priority-card market-space-priority-card--' . e($tone) . '">'
            . '<div class="market-space-priority-card__label">' . e($label) . '</div>'
            . '<div class="market-space-priority-card__value">' . e($value) . '</div>'
            . $noteHtml
            . '</div>'
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
                $parentLabel = '#' . (int) ($record->space_group_parent_id ?? 0);
            }

            $slot = trim((string) ($record->space_group_slot ?? ''));
            $note = $slot !== ''
                ? 'Место внутри группы, позиция ' . $slot
                : 'Место входит в группу';

            return static::renderPriorityCard('Группа', $parentLabel, $note);
        }

        if ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();

            $note = $childrenCount > 0
                ? 'Группа объединяет ' . $childrenCount . ' мест'
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
                $sourceLabel = '#' . (int) ($sourceSpace?->id ?? 0);
            }

            $note = 'Наследуется от группы ' . $sourceLabel;
        }

        return static::renderPriorityCard('Арендатор', $tenantName, $note, 'occupied');
    }

    private static function renderPriorityAvailabilityCard(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return static::renderPriorityCard('Свободно / занято', 'Появится после сохранения');
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

        return static::renderPriorityCard('Площадь', $formatted . ' м²', 'Используется в ставке и аналитике');
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
            ? number_format((float) $record->rent_rate_value, 2, ',', ' ') . ' ₽'
            : null;

        $factRate = null;
        $period = static::resolveOperationPeriod($record);
        $resolvedFactRate = static::resolveRentRateFact($record, $period);

        if ($resolvedFactRate !== null) {
            $factRate = number_format($resolvedFactRate, 2, ',', ' ') . ' ₽';
        }

        $value = $currentRate ?? $factRate ?? 'Не задана';
        $noteParts = [];

        if ($unitLabel) {
            $noteParts[] = $unitLabel;
        }

        if ($factRate !== null && $currentRate !== null && $factRate !== $currentRate) {
            $noteParts[] = 'Факт за период: ' . $factRate;
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
            ? '<div class="market-space-priority-summary__meta">' . e($meta) . '</div>'
            : '';

        return '<div class="market-space-priority-summary__item market-space-priority-summary__item--' . e($tone) . '">'
            . ($actionHtml ?? '')
            . '<div class="market-space-priority-summary__label">' . e($label) . '</div>'
            . '<div class="market-space-priority-summary__value">' . e($value) . '</div>'
            . $metaHtml
            . '</div>';
    }

    private static function renderPrioritySummary(?MarketSpace $record): HtmlString
    {
        if (! $record instanceof MarketSpace) {
            return new HtmlString(
                '<div class="market-space-priority-summary">'
                . static::renderPrioritySummaryItem('Номер', 'Появится после сохранения')
                . '</div>'
            );
        }

        $number = trim((string) ($record->number ?? ''));
        $displayName = trim((string) ($record->display_name ?? ''));

        $groupValue = 'Отдельное место';
        $groupMeta = 'Не входит в группу';

        if (static::isChildWithParent($record)) {
            $parent = $record->spaceGroupParent;
            $parentLabel = trim((string) ($parent?->number ?? ''));

            if ($parentLabel === '') {
                $parentLabel = trim((string) ($parent?->display_name ?? ''));
            }

            if ($parentLabel === '') {
                $parentLabel = '#' . (int) ($record->space_group_parent_id ?? 0);
            }

            $groupValue = $parentLabel;
            $slot = trim((string) ($record->space_group_slot ?? ''));
            $groupMeta = $slot !== '' ? 'Место внутри группы, позиция ' . $slot : 'Место входит в группу';
        } elseif ((string) ($record->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT) {
            $childrenCount = $record->spaceGroupChildren()
                ->where('space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                ->count();
            $groupValue = 'Группа мест';
            $groupMeta = $childrenCount > 0 ? 'Объединяет ' . $childrenCount . ' мест' : 'Группа пока пустая';
        }

        $tenantName = trim((string) ($record->effectiveTenantName() ?? ''));
        $tenantValue = $tenantName !== '' ? $tenantName : 'Не назначен';
        $tenantMeta = $tenantName === '' ? 'Сейчас место свободно' : 'Указан на этом месте';
        $tenantTone = $tenantName === '' ? 'vacant' : 'occupied';

        if ($tenantName !== '' && $record->effectiveOccupancySource() === 'parent') {
            $sourceSpace = $record->effectiveOccupancySourceSpace();
            $sourceLabel = trim((string) ($sourceSpace?->number ?? ''));

            if ($sourceLabel === '') {
                $sourceLabel = trim((string) ($sourceSpace?->display_name ?? ''));
            }

            if ($sourceLabel === '') {
                $sourceLabel = '#' . (int) ($sourceSpace?->id ?? 0);
            }

            $tenantMeta = 'Наследуется от группы ' . $sourceLabel;
        }

        $tenantActionHtml = '<button type="button" class="market-space-priority-summary__action" wire:click="mountAction(\'switch_tenant\')" title="Сменить арендатора" aria-label="Сменить арендатора">'
            . '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-8.9 8.9a2 2 0 0 1-.878.513l-2.5.714a.75.75 0 0 1-.927-.927l.714-2.5a2 2 0 0 1 .513-.878l8.9-8.9ZM12.525 5.707 5.533 12.7a.5.5 0 0 0-.128.22l-.425 1.49 1.49-.425a.5.5 0 0 0 .22-.128l6.992-6.992-1.157-1.157Z"/></svg>'
            . '</button>';

        $availabilityValue = $tenantName === '' ? 'Свободно' : 'Занято';
        $availabilityMeta = $tenantName === '' ? 'Арендатор не назначен' : 'Сейчас место используется';
        $availabilityTone = $tenantName === '' ? 'vacant' : 'occupied';

        $areaValue = 'Не указана';
        $areaMeta = 'Нужна для расчётов и отчётов';
        if ($record->area_sqm !== null) {
            $formattedArea = number_format((float) $record->area_sqm, 2, ',', ' ');
            $formattedArea = rtrim(rtrim($formattedArea, '0'), ',');
            $areaValue = $formattedArea . ' м²';
            $areaMeta = 'Используется в ставке и аналитике';
        }

        $unitLabel = filled($record->rent_rate_unit)
            ? static::rentRateUnitLabel((string) $record->rent_rate_unit)
            : null;
        $currentRate = $record->rent_rate_value !== null
            ? number_format((float) $record->rent_rate_value, 2, ',', ' ') . ' ₽'
            : null;
        $factRate = null;
        $period = static::resolveOperationPeriod($record);
        $resolvedFactRate = static::resolveRentRateFact($record, $period);
        if ($resolvedFactRate !== null) {
            $factRate = number_format($resolvedFactRate, 2, ',', ' ') . ' ₽';
        }

        $rentValue = $currentRate ?? $factRate ?? 'Не задана';
        $rentMetaParts = [];
        if ($unitLabel) {
            $rentMetaParts[] = $unitLabel;
        }
        if ($factRate !== null && $currentRate !== null && $factRate !== $currentRate) {
            $rentMetaParts[] = 'Факт: ' . $factRate;
        } elseif ($factRate !== null && $currentRate === null) {
            $rentMetaParts[] = 'Факт за период';
        } elseif ($currentRate === null && $factRate === null) {
            $rentMetaParts[] = 'Ставка ещё не заполнена';
        }

        $items = [
            static::renderPrioritySummaryItem('Группа', $groupValue, $groupMeta),
            static::renderPrioritySummaryItem('Арендатор', $tenantValue, $tenantMeta, $tenantTone, $tenantActionHtml),
            static::renderPrioritySummaryItem('Свободно / занято', $availabilityValue, $availabilityMeta, $availabilityTone),
            static::renderPrioritySummaryItem('Площадь', $areaValue, $areaMeta),
            static::renderPrioritySummaryItem('Ставка', $rentValue, implode(' • ', $rentMetaParts)),
        ];

        return new HtmlString('<div class="market-space-priority-summary">' . implode('', $items) . '</div>');
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
            $sourceLabel = '#' . (int) ($sourceSpace?->id ?? 0);
        }

        return $sourceLabel !== '' ? 'через группу: ' . $sourceLabel : 'через группу';
    }

    private static function tableEffectiveStatusLabel(MarketSpace $record): ?string
    {
        return match ($record->effectiveOccupancySource()) {
            'parent' => 'В группе',
            'direct' => 'Занято',
            default => static::statusLabel($record->status),
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

            return $sourceLabel !== '' ? 'Фактически занято через группу: ' . $sourceLabel : 'Фактически занято через группу';
        }

        if ($source === 'direct') {
            return 'Фактически занято напрямую';
        }

        $label = static::statusLabel($record->status);

        return $label ? 'Прямой статус: ' . $label : null;
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
                    $label = '#' . (int) $space->id;
                } else {
                    $label .= ' #' . (int) $space->id;
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
            $selectedMarketId = static::selectedMarketIdFromSession();

            if (filled($selectedMarketId)) {
                $components[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $components[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->hintIcon('heroicon-m-question-mark-circle')
                    ->hintIconTooltip('Рынок нужен, чтобы корректно фильтровать локации, арендаторов и тарифы.')
                    ->dehydrated(true);
            }
        } else {
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
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

                                        return 'Если поле было пустым, подставлена локация родительской группы: ' . $parentLocationName . '. Значение можно изменить вручную.';
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
                                            $set('display_name', 'Место ' . trim((string) $state));
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
                                    ->options(function (?MarketSpace $record): array {
                                        $options = static::groupRoleOptions();

                                        if (filled($record?->id) && ! static::isChildWithParent($record)) {
                                            unset($options[MarketSpace::SPACE_GROUP_ROLE_CHILD]);
                                        }

                                        return $options;
                                    })
                                    ->default('none')
                                    ->required()
                                    ->live()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Определяет, как место участвует в группировке. Для существующего места перевод в группу выполняется отдельной кнопкой в шапке карточки, чтобы сразу выбрать родительскую группу и номер внутри группы.')
                                    ->helperText(function (?MarketSpace $record): ?string {
                                        if (! filled($record?->id)) {
                                            return null;
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
                                    ->visible(fn (?MarketSpace $record): bool => static::isChildWithParent($record))
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
                                    ->visible(fn ($get, ?MarketSpace $record): bool => static::requiresParentGroupMapShapeResolution(
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
                                    ->visible(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->dehydrated(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Выбирается только для места в группе. Родитель определяет, к какой группе относится child-место.')
                                    ->placeholder('Выберите родительскую группу')
                                    ->nullable(),

                                Forms\Components\TextInput::make('space_group_slot')
                                    ->label('Номер в группе')
                                    ->maxLength(255)
                                    ->required(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
                                    ->visible(fn ($get, ?MarketSpace $record): bool => ! filled($record?->id) && (string) ($get('space_group_role') ?? 'none') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
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
                        ->label('Тип места')
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
                                    ->placeholder('—')
                                    ->helperText(function ($get, ?MarketSpace $record) use ($user): ?string {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return null;
                                        }

                                        $currentType = trim((string) ($get('type') ?? $record?->type ?? ''));
                                        if ($currentType === '') {
                                            return null;
                                        }

                                        $hasCurrentActiveType = MarketSpaceType::query()
                                            ->where('market_id', (int) $marketId)
                                            ->where('is_active', true)
                                            ->where('code', $currentType)
                                            ->exists();

                                        if ($hasCurrentActiveType) {
                                            return null;
                                        }

                                        return 'Сейчас сохранено старое значение с кодом ' . $currentType . '. Его нет в текущем справочнике типов мест.';
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(function ($get, ?MarketSpace $record) use ($user): string {
                                        $parts = ['Категория места для отчётности. Берётся из справочника “Типы мест”.'];
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
                                                $parts[] = 'Для этого рынка справочник типов мест пока пуст. Сначала заполните "Типы мест".';
                                            } elseif ($currentType !== '' && ! $hasCurrentActiveType) {
                                                $parts[] = 'Текущий тип больше не найден среди активных типов мест.';
                                            }
                                        }

                                        return implode(' ', $parts);
                                    }),

                                Forms\Components\TextInput::make('area_sqm')
                                    ->label('Площадь, м²')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->placeholder('Например: 48')
                                    ->suffix('м²')
                                    ->extraFieldWrapperAttributes(['style' => 'width:min(100%, 14rem);'])
                                    ->extraInputAttributes(['style' => 'width:100%;'])
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Площадь используется в отчётах и расчётах. Допускаются десятичные значения.'),

                                Forms\Components\Select::make('status')
                                    ->label('Прямой статус места')
                                    ->options([
                                        'vacant' => 'Свободно',
                                        'occupied' => 'Занято',
                                        'reserved' => 'Зарезервировано',
                                        'maintenance' => 'На обслуживании',
                                    ])
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
                            ->collapsible(),

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
                            ->visible(fn (?MarketSpace $record): bool => (string) ($record?->space_group_role ?? '') === MarketSpace::SPACE_GROUP_ROLE_PARENT)
                            ->schema([
                                Forms\Components\Placeholder::make('group_composition')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderGroupComposition($record))
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),
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
            ? number_format($rentRate, 2, ',', ' ') . ' ₽'
            : 'Не задано';

        $extra = '';
        if ($unitLabel) {
            $extra .= '<div style="margin-top:4px;opacity:.7;">Единица: ' . e($unitLabel) . '</div>';
        }

        $estimate = static::rentRateEstimateHtml($record, $period);

        return new HtmlString(
            '<div style="font-size:13px;">' .
            '<div><strong>' . e($display) . '</strong></div>' .
            $extra .
            $estimate .
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

        return '<div style="margin-top:4px;opacity:.65;">Оценочно: ' . e($display) . ' ₽/м² за период (справочно).</div>';
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
            ->orderByDesc('effective_at')
            ->limit(50)
            ->get([
                'id',
                'effective_at',
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

        $creators = $creatorIds === []
            ? collect()
            : DB::table('users')
                ->whereIn('id', $creatorIds)
                ->pluck('name', 'id');

        $items = $rows->map(function ($row): array {
            $payload = is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
            $labels = \App\Domain\Operations\OperationType::labels();
            $type = (string) ($row->type ?? '');
            $isReview = $type === \App\Domain\Operations\OperationType::SPACE_REVIEW;
            $reviewDecision = $isReview ? trim((string) ($payload['decision'] ?? '')) : '';
            $reviewDecisionLabel = $isReview && $reviewDecision !== ''
                ? (\App\Domain\Operations\SpaceReviewDecision::labels()[$reviewDecision] ?? $reviewDecision)
                : null;

            $summary = static::buildOperationSummary($type, $payload);

            $item = [
                'effective_at' => $row->effective_at ? (string) \Carbon\Carbon::parse($row->effective_at)->format('d.m.Y H:i') : '—',
                'type' => $labels[$type] ?? $type,
                'status' => (string) $row->status,
                'is_review' => $isReview,
                'review_decision' => $reviewDecision !== '' ? $reviewDecision : null,
                'review_decision_label' => $reviewDecisionLabel,
                'review_reason' => $isReview ? static::resolveReviewReason($payload, $row->comment ?? null) : null,
                'review_observed_tenant_name' => $isReview ? static::stringOrNull($payload['observed_tenant_name'] ?? null) : null,
                'summary' => $summary,
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
                . (filled($payload['shape_id'] ?? null) ? ' Shape #' . (int) $payload['shape_id'] . '.' : ''),
            \App\Domain\Operations\SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE => 'Фигура отвязана от места.'
                . (filled($payload['shape_id'] ?? null) ? ' Shape #' . (int) $payload['shape_id'] . '.' : ''),
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
            $parts[] = 'Номер: ' . $number;
        }

        if ($displayName !== null) {
            $parts[] = 'Название: ' . $displayName;
        }

        if ($parts === []) {
            return 'Данные места уточнены.';
        }

        return 'Данные места уточнены. ' . implode(' · ', $parts);
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

        return 'Выполнен разбор дубля: основное место #' . $candidateSpaceId . '.';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildOperationSummary(string $type, array $payload): string
    {
        if ($type === \App\Domain\Operations\OperationType::SPACE_REVIEW) {
            $decision = trim((string) ($payload['decision'] ?? ''));
            return static::buildReviewSummary($decision, $payload);
        }

        if ($type === \App\Domain\Operations\OperationType::GROUP_MEMBERSHIP) {
            return static::buildGroupMembershipSummary($payload);
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function buildGroupMembershipSummary(array $payload): string
    {
        $action = $payload['action'] ?? '';
        $parts = [];

        $label = match ($action) {
            'add_to_group' => 'Добавлено в группу',
            'move_to_group' => 'Перенесено в другую группу',
            'remove_from_group' => 'Убрано из группы',
            default => 'Изменение состава группы',
        };

        $parts[] = $label;

        if ($action === 'add_to_group' || $action === 'move_to_group') {
            $newParentId = $payload['new_space_group_parent_id'] ?? null;
            $newSlot = $payload['new_space_group_slot'] ?? null;

            if ($newParentId !== null && $newSlot !== null) {
                $parts[] = 'в группу #' . (int) $newParentId . ', слот ' . (string) $newSlot;
            } elseif ($newSlot !== null) {
                $parts[] = 'слот ' . (string) $newSlot;
            }
        }

        if ($action === 'move_to_group') {
            $oldParentId = $payload['old_space_group_parent_id'] ?? null;
            if ($oldParentId !== null) {
                $parts[] = 'из группы #' . (int) $oldParentId;
            }
        }

        if ($action === 'remove_from_group') {
            $oldParentId = $payload['old_space_group_parent_id'] ?? null;
            if ($oldParentId !== null) {
                $parts[] = 'из группы #' . (int) $oldParentId;
            }
        }

        $userComment = $payload['user_comment'] ?? null;
        if ($userComment !== null && $userComment !== '') {
            $parts[] = 'Комментарий: ' . (string) $userComment;
        }

        return implode('; ', $parts);
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

                        return $hint ? ($tenantName . ' (' . $hint . ')') : $tenantName;
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

                // "Тип места" — отдельная сущность от локации, по умолчанию прячем
                TextColumn::make('type')
                    ->label('Тип места')
                    ->formatStateUsing(fn (?string $state, MarketSpace $record) => static::resolveSpaceTypeLabel($record->market_id, $state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

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

                TextColumn::make('effective_occupancy')
                    ->label('Фактическая занятость')
                    ->state(fn (MarketSpace $record): ?string => static::tableEffectiveStatusLabel($record))
                    ->badge()
                    ->color(fn (MarketSpace $record): string => static::tableEffectiveStatusColor($record))
                    ->tooltip(fn (MarketSpace $record): ?string => static::tableEffectiveStatusTooltip($record)),

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
                        'maintenance' => 'На обслуживании',
                    ]),

                SelectFilter::make('type')
                    ->label('Тип места')
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
                // 4. rawStatus === maintenance -> На обслуживании
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
                    $statusLabel = 'На обслуживании';
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
