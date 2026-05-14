@php
    $spaceLabel = $spaceLabel ?? 'Торговое место';
    $statusLabel = $statusLabel ?? 'Можно продолжать только после ручного разбора';
    $statusTone = $statusTone ?? 'warning';
    $introText = $introText ?? 'Простое выключение места несёт риск рассинхрона связей. Сначала нужен просмотр связей и ручной разбор.';
    $liveRelations = $liveRelations ?? [];
    $transferableRelations = $transferableRelations ?? [];
    $blockingRelations = $blockingRelations ?? [];
    $historicalRelations = $historicalRelations ?? [];
    $mapUrl = $mapUrl ?? null;
    $historyUrl = $historyUrl ?? null;
    $tenantUrl = $tenantUrl ?? null;
    $contractsUrl = $contractsUrl ?? null;
    $contractPreview = $contractPreview ?? [];
    $accrualsUrl = $accrualsUrl ?? null;
    $accrualPreview = $accrualPreview ?? [];

    $hasShapeRelations = false;

    foreach ($liveRelations as $relationItem) {
        if (str_contains((string) ($relationItem['label'] ?? ''), 'Фигур')) {
            $hasShapeRelations = true;

            break;
        }
    }

    $hasTransferableRelations = count($transferableRelations) > 0;
    $hasBlockingRelations = count($blockingRelations) > 0;
    $hasHistoricalRelations = count($historicalRelations) > 0;

    $nextSteps = [];

    if ($hasTransferableRelations) {
        $nextSteps[] = 'Сначала разберите переносимые связи и определите целевое место.';
    }

    if ($hasBlockingRelations) {
        $nextSteps[] = 'Нельзя просто выключить место, пока не разобраны блокирующие связи.';
    }

    if (! $hasTransferableRelations && ! $hasBlockingRelations && $hasHistoricalRelations) {
        $nextSteps[] = 'После проверки можно рассматривать деактивацию без переноса.';
    }

    if ($hasShapeRelations) {
        $nextSteps[] = 'Проверьте фигуру на карте перед следующим шагом.';
    }

    if ($nextSteps === []) {
        $nextSteps[] = 'Явных блокирующих связей не найдено, но перед деактивацией нужен ручной просмотр.';
    }

    $liveCount = count($liveRelations);
    $transferableCount = count($transferableRelations);
    $blockingCount = count($blockingRelations);
    $historicalCount = count($historicalRelations);
    $transferableTotal = array_sum(array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $transferableRelations));
    $blockingTotal = array_sum(array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $blockingRelations));
    $historicalTotal = array_sum(array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $historicalRelations));
    $isSimpleDeactivateCase = $liveCount === 0 && $transferableCount === 0 && $blockingCount === 0;

    $verdictLabel = 'Нужен разбор связей перед упразднением';
    $verdictNote = 'Есть зависимости, которые нужно проверить и разнести по правильным точкам входа, прежде чем двигаться дальше.';

    if ($blockingCount > 0) {
        $verdictLabel = 'Нужен разбор связей перед упразднением';
        $verdictNote = 'Обнаружены блокирующие связи. Простое выключение места сейчас создаст риск рассинхрона.';
    } elseif ($transferableCount > 0) {
        $verdictLabel = 'Нужен разбор связей перед упразднением';
        $verdictNote = 'Есть связи, которые нужно подготовить к переносу, прежде чем упразднять место.';
    } elseif ($liveCount === 0) {
        $verdictLabel = 'Можно готовить следующее действие';
        $verdictNote = 'Явных живых связей не найдено. После ручной проверки можно продолжать.';
    }

    $primaryNextStep = $hasBlockingRelations
        ? 'Сначала разберите блокирующие связи вручную.'
        : ($hasTransferableRelations
            ? 'Сначала подготовьте переносимые связи и определите целевое место.'
            : 'Проверьте историю и карту, затем можно продолжать.');

    $secondaryNextSteps = [];

    if ($hasShapeRelations) {
        $secondaryNextSteps[] = 'Проверьте фигуру на карте.';
    }

    if ($historyUrl) {
        $secondaryNextSteps[] = 'Сверьте историю места перед следующим действием.';
    }

    if (! $isSimpleDeactivateCase) {
        if ($blockingCount > 0) {
            $verdictLabel = 'Упразднение пока недоступно';
            $verdictNote = 'Сначала нужно разобрать блокирующие связи. Простое выключение места сейчас небезопасно.';
        } elseif ($transferableCount > 0) {
            $verdictLabel = 'Сначала подготовьте переносимые связи';
            $verdictNote = 'Часть связей нужно подготовить к переносу, и только потом возвращаться к упразднению.';
        }

        $primaryNextStep = $hasBlockingRelations
            ? 'Сначала откройте и разберите блокирующие связи.'
            : 'Сначала подготовьте переносимые связи, затем возвращайтесь к упразднению.';

    }

    $actionSteps = [];

    if ($blockingCount > 0 && $tenantUrl) {
        $actionSteps[] = [
            'label' => 'Открыть арендатора',
            'url' => $tenantUrl,
            'new_tab' => true,
        ];
    }

    if ($blockingCount > 0 && $contractsUrl) {
        $actionSteps[] = [
            'label' => 'Открыть договоры',
            'url' => $contractsUrl,
            'new_tab' => true,
        ];
    }

    if ($blockingCount > 0 && $accrualsUrl) {
        $actionSteps[] = [
            'label' => 'Открыть начисления',
            'url' => $accrualsUrl,
            'new_tab' => true,
        ];
    }

    if ($hasTransferableRelations && $mapUrl) {
        $actionSteps[] = [
            'label' => 'Проверить карту',
            'url' => $mapUrl,
            'new_tab' => true,
        ];
    }

    if ($historyUrl) {
        $actionSteps[] = [
            'label' => 'Открыть историю',
            'url' => $historyUrl,
            'new_tab' => false,
        ];
    }

    $showSummaryStatusBlock = false;
    $showStatsBlock = ! $isSimpleDeactivateCase;
    $showOverviewTable = false;

    $resolveAction = static function (array $item) use ($tenantUrl, $mapUrl, $historyUrl, $contractsUrl, $accrualsUrl): array {
        $label = (string) ($item['label'] ?? '');

        if ($label === 'Текущий арендатор' && $tenantUrl) {
            return [
                'label' => 'Открыть арендатора',
                'url' => $tenantUrl,
                'new_tab' => true,
            ];
        }

        if ($label === 'Договоры' && $contractsUrl) {
            return [
                'label' => 'Открыть договоры',
                'url' => $contractsUrl,
                'new_tab' => true,
            ];
        }

        if ($label === 'Начисления' && $accrualsUrl) {
            return [
                'label' => 'Открыть начисления',
                'url' => $accrualsUrl,
                'new_tab' => true,
            ];
        }

        if ($label === 'Фигуры на карте' && $mapUrl) {
            return [
                'label' => 'Открыть карту',
                'url' => $mapUrl,
                'new_tab' => true,
            ];
        }

        if (($label === 'Журнал операций' || $label === 'История привязок' || $label === 'История арендаторов' || $label === 'История ставок') && $historyUrl) {
            return [
                'label' => 'Открыть историю',
                'url' => $historyUrl,
                'new_tab' => false,
            ];
        }

        return [
            'label' => 'Нужен ручной разбор',
            'url' => null,
            'new_tab' => false,
        ];
    };

    $statusClass = match ($statusTone) {
        'success' => 'deactivate-precheck__status--success',
        'info' => 'deactivate-precheck__status--info',
        'gray' => 'deactivate-precheck__status--gray',
        default => 'deactivate-precheck__status--warning',
    };

    $badgeClass = static function (string $bucketLabel): string {
        return match ($bucketLabel) {
            'Переносится' => 'deactivate-precheck__badge deactivate-precheck__badge--info',
            'Блокирует' => 'deactivate-precheck__badge deactivate-precheck__badge--warning',
            'Архив' => 'deactivate-precheck__badge deactivate-precheck__badge--gray',
            default => 'deactivate-precheck__badge deactivate-precheck__badge--gray',
        };
    };

    $sections = [
        [
            'title' => 'Мешают упразднению',
            'items' => $blockingRelations,
            'note' => 'Эти связи нужно разобрать вручную, прежде чем возвращаться к упразднению.',
            'empty' => 'Блокирующих связей не найдено.',
        ],
        [
            'title' => 'Потребуют отдельного переноса',
            'items' => $transferableRelations,
            'note' => 'Эти связи не блокируют навсегда, но их нужно сначала перенести в отдельном сценарии.',
            'empty' => 'Переносимых связей не найдено.',
        ],
        [
            'title' => 'Останется в истории',
            'items' => $historicalRelations,
            'note' => 'Эти данные не мешают упразднению и остаются как аудит.',
            'empty' => 'Архивных связей не найдено.',
        ],
];

if (! $isSimpleDeactivateCase) {
    $sections = array_slice($sections, 0, 2);
}
@endphp

<style>
    .deactivate-precheck {
        max-height: calc(100vh - 14rem);
        overflow-y: auto;
        padding-right: 0.25rem;
        color: #0f172a;
    }

    .deactivate-precheck__stack {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .deactivate-precheck__card {
        border: 1px solid #dbe4ef;
        border-radius: 1rem;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        padding: 1rem 1.1rem;
    }

    .deactivate-precheck__eyebrow {
        margin: 0;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #64748b;
    }

    .deactivate-precheck__title {
        margin: 0.35rem 0 0;
        font-size: 1.25rem;
        font-weight: 700;
        line-height: 1.2;
        color: #0f172a;
    }

    .deactivate-precheck__text {
        margin: 0.75rem 0 0;
        font-size: 0.95rem;
        line-height: 1.65;
        color: #334155;
    }

    .deactivate-precheck__top {
        display: grid;
        gap: 1rem;
    }

    .deactivate-precheck__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .deactivate-precheck__link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.75rem;
        padding: 0.65rem 0.95rem;
        border-radius: 0.85rem;
        border: 1px solid #cdd9e8;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        color: #1e293b;
        font-size: 0.92rem;
        font-weight: 600;
        text-decoration: none;
        transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
    }

    .deactivate-precheck__link:hover {
        border-color: #b8c9dd;
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
    }

    .deactivate-precheck__link--primary {
        border-color: #bfdbfe;
        background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
        color: #1d4ed8;
    }

    .deactivate-precheck__status {
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        padding: 1rem 1.1rem;
    }

    .deactivate-precheck__status-title {
        margin: 0;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        opacity: 0.8;
    }

    .deactivate-precheck__status-copy {
        margin: 0.35rem 0 0;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.4;
    }

    .deactivate-precheck__status-note {
        margin: 0.55rem 0 0;
        font-size: 0.92rem;
        line-height: 1.6;
        opacity: 0.95;
    }

    .deactivate-precheck__status--warning {
        border-color: #fcd34d;
        background: #fffbeb;
        color: #92400e;
    }

    .deactivate-precheck__status--info {
        border-color: #93c5fd;
        background: #eff6ff;
        color: #1d4ed8;
    }

    .deactivate-precheck__status--success {
        border-color: #86efac;
        background: #ecfdf5;
        color: #166534;
    }

    .deactivate-precheck__status--gray {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #334155;
    }

    .deactivate-precheck__stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.8rem;
    }

    .deactivate-precheck__verdict {
        border-radius: 1rem;
        border: 1px solid #fecaca;
        background: linear-gradient(180deg, #fff7f7 0%, #fff1f2 100%);
        padding: 1rem 1.1rem;
    }

    .deactivate-precheck__verdict-title {
        margin: 0;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #b91c1c;
    }

    .deactivate-precheck__verdict-copy {
        margin: 0.35rem 0 0;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 1.4;
        color: #7f1d1d;
    }

    .deactivate-precheck__verdict-note {
        margin: 0.5rem 0 0;
        font-size: 0.92rem;
        line-height: 1.6;
        color: #991b1b;
    }

    .deactivate-precheck__stat {
        border-radius: 1rem;
        border: 1px solid #dbe4ef;
        background: #ffffff;
        padding: 0.95rem 1rem;
    }

    .deactivate-precheck__stat--info {
        border-color: #bfdbfe;
        background: #eff6ff;
    }

    .deactivate-precheck__stat--warning {
        border-color: #fcd34d;
        background: #fffbeb;
    }

    .deactivate-precheck__stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #64748b;
    }

    .deactivate-precheck__stat-value {
        margin-top: 0.35rem;
        font-size: 1.75rem;
        font-weight: 700;
        line-height: 1;
        color: #0f172a;
    }

    .deactivate-precheck__stat-note {
        margin-top: 0.35rem;
        font-size: 0.85rem;
        line-height: 1.45;
        color: #475569;
    }

    .deactivate-precheck__section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.85rem;
    }

    .deactivate-precheck__section-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        color: #0f172a;
    }

    .deactivate-precheck__section-note {
        margin: 0.2rem 0 0;
        font-size: 0.88rem;
        line-height: 1.55;
        color: #64748b;
    }

    .deactivate-precheck__count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 0.65rem;
        border-radius: 999px;
        background: #eef2f7;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .deactivate-precheck__table-wrap {
        overflow-x: auto;
        border: 1px solid #dbe4ef;
        border-radius: 0.95rem;
        background: #ffffff;
    }

    .deactivate-precheck__table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .deactivate-precheck__table th,
    .deactivate-precheck__table td {
        padding: 0.85rem 1rem;
        vertical-align: top;
        text-align: left;
        border-bottom: 1px solid #e5edf5;
    }

    .deactivate-precheck__table th {
        background: #f8fafc;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #64748b;
    }

    .deactivate-precheck__table tr:last-child td {
        border-bottom: none;
    }

    .deactivate-precheck__table td {
        font-size: 0.92rem;
        line-height: 1.55;
        color: #334155;
        word-break: break-word;
    }

    .deactivate-precheck__table td:first-child {
        font-weight: 600;
        color: #0f172a;
    }

    .deactivate-precheck__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.3rem 0.55rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .deactivate-precheck__badge--info {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .deactivate-precheck__badge--warning {
        background: #fef3c7;
        color: #92400e;
    }

    .deactivate-precheck__badge--gray {
        background: #e2e8f0;
        color: #475569;
    }

    .deactivate-precheck__action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.2rem;
        padding: 0.45rem 0.75rem;
        border-radius: 0.7rem;
        border: 1px solid #cdd9e8;
        background: #ffffff;
        color: #1e40af;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }

    .deactivate-precheck__action--muted {
        border-style: dashed;
        color: #64748b;
        background: #f8fafc;
    }

    .deactivate-precheck__empty {
        padding: 1rem;
        font-size: 0.92rem;
        line-height: 1.5;
        color: #64748b;
    }

    .deactivate-precheck__steps {
        margin: 0;
        padding: 0;
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
    }

    .deactivate-precheck__steps li {
        display: grid;
        grid-template-columns: 2rem 1fr;
        gap: 0.75rem;
        align-items: start;
    }

    .deactivate-precheck__step-index {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 999px;
        background: #e2e8f0;
        color: #334155;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .dark .deactivate-precheck {
        color: #e2e8f0;
    }

    .dark .deactivate-precheck__card,
    .dark .deactivate-precheck__stat,
    .dark .deactivate-precheck__table-wrap {
        border-color: rgba(148, 163, 184, 0.18);
        background: rgba(15, 23, 42, 0.82);
        box-shadow: none;
    }

    .dark .deactivate-precheck__verdict {
        border-color: rgba(248, 113, 113, 0.28);
        background: linear-gradient(180deg, rgba(69, 10, 10, 0.35) 0%, rgba(69, 10, 10, 0.22) 100%);
    }

    .dark .deactivate-precheck__verdict-title {
        color: #fca5a5;
    }

    .dark .deactivate-precheck__verdict-copy {
        color: #fee2e2;
    }

    .dark .deactivate-precheck__verdict-note {
        color: #fecaca;
    }

    .dark .deactivate-precheck__link {
        border-color: rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.82);
        color: #e2e8f0;
    }

    .dark .deactivate-precheck__link--primary {
        border-color: rgba(59, 130, 246, 0.34);
        background: rgba(30, 64, 175, 0.24);
        color: #bfdbfe;
    }

    .dark .deactivate-precheck__action {
        border-color: rgba(59, 130, 246, 0.28);
        background: rgba(30, 41, 59, 0.88);
        color: #bfdbfe;
    }

    .dark .deactivate-precheck__action--muted {
        border-color: rgba(148, 163, 184, 0.2);
        color: #94a3b8;
        background: rgba(30, 41, 59, 0.72);
    }

    .dark .deactivate-precheck__title,
    .dark .deactivate-precheck__section-title,
    .dark .deactivate-precheck__table td:first-child,
    .dark .deactivate-precheck__stat-value {
        color: #f8fafc;
    }

    .dark .deactivate-precheck__text,
    .dark .deactivate-precheck__table td,
    .dark .deactivate-precheck__stat-note {
        color: #cbd5e1;
    }

    .dark .deactivate-precheck__eyebrow,
    .dark .deactivate-precheck__section-note,
    .dark .deactivate-precheck__table th,
    .dark .deactivate-precheck__stat-label,
    .dark .deactivate-precheck__empty {
        color: #94a3b8;
    }

    .dark .deactivate-precheck__table th {
        background: rgba(30, 41, 59, 0.88);
    }

    .dark .deactivate-precheck__table th,
    .dark .deactivate-precheck__table td {
        border-bottom-color: rgba(148, 163, 184, 0.14);
    }

    .dark .deactivate-precheck__count,
    .dark .deactivate-precheck__step-index,
    .dark .deactivate-precheck__badge--gray {
        background: rgba(51, 65, 85, 0.92);
        color: #e2e8f0;
    }

    .dark .deactivate-precheck__badge--info {
        background: rgba(30, 64, 175, 0.38);
        color: #bfdbfe;
    }

    .dark .deactivate-precheck__badge--warning {
        background: rgba(146, 64, 14, 0.36);
        color: #fde68a;
    }

    @media (max-width: 1023px) {
        .deactivate-precheck__stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .deactivate-precheck {
            max-height: calc(100vh - 10rem);
        }

        .deactivate-precheck__stats {
            grid-template-columns: 1fr;
        }

        .deactivate-precheck__table {
            min-width: 44rem;
        }
    }
</style>

<div class="deactivate-precheck">
    <div class="deactivate-precheck__stack">
        @if ($isSimpleDeactivateCase)
            <section class="deactivate-precheck__card">
                <div class="deactivate-precheck__top">
                    <div>
                        <h3 class="deactivate-precheck__title">{{ $spaceLabel }}</h3>
                        <p class="deactivate-precheck__text">
                            Проверьте карту и историю, затем можно упразднять место.
                        </p>
                    </div>

                    <div class="deactivate-precheck__actions">
                        @if ($historyUrl)
                            <a href="{{ $historyUrl }}" class="deactivate-precheck__link">
                                Открыть историю
                            </a>
                        @endif

                        @if ($mapUrl)
                            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="deactivate-precheck__link deactivate-precheck__link--primary">
                                Открыть карту
                            </a>
                        @endif
                    </div>
                </div>
            </section>

            <section class="deactivate-precheck__status deactivate-precheck__status--success">
                <p class="deactivate-precheck__status-title">Итог проверки</p>
                <div class="deactivate-precheck__status-copy">Место можно упразднить</div>
                <div class="deactivate-precheck__status-note">
                    Связей, мешающих упразднению, не найдено. История операций останется как аудит.
                </div>
            </section>

            @if ($historicalCount > 0)
                <section class="deactivate-precheck__card">
                    <div class="deactivate-precheck__section-head">
                        <div>
                            <h4 class="deactivate-precheck__section-title">Останется в истории</h4>
                            <p class="deactivate-precheck__section-note">Архивные записи не удаляются и остаются как след изменений.</p>
                        </div>
                        <span class="deactivate-precheck__count">{{ $historicalTotal }}</span>
                    </div>

                    <p class="deactivate-precheck__text" style="margin-top: 0;">
                        История арендаторов, ставок и привязок останется доступна в журнале изменений.
                    </p>
                </section>
            @endif
        @else
        <section class="deactivate-precheck__card">
            <div class="deactivate-precheck__top">
                <div>
                    <h3 class="deactivate-precheck__title">{{ $spaceLabel }}</h3>
                    <p class="deactivate-precheck__text">
                        Упразднение — это отдельная проверка связей, а не просто перевод места в состояние «Неактивно».
                    </p>
                </div>

                <div class="deactivate-precheck__actions">
                    @if ($mapUrl)
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="deactivate-precheck__link deactivate-precheck__link--primary">
                            Открыть карту
                        </a>
                    @endif

                    @if ($historyUrl)
                        <a href="{{ $historyUrl }}" class="deactivate-precheck__link">
                            Открыть историю
                        </a>
                    @endif
                </div>
            </div>
        </section>

        @if ($showSummaryStatusBlock)
        <section class="deactivate-precheck__status {{ $statusClass }}">
            <p class="deactivate-precheck__status-title">Итог проверки</p>
            <div class="deactivate-precheck__status-copy">{{ $statusLabel }}</div>
            <div class="deactivate-precheck__status-note">{{ $introText }}</div>
        </section>
        @endif

        <section class="deactivate-precheck__verdict">
            <p class="deactivate-precheck__verdict-title">Итог проверки</p>
            <div class="deactivate-precheck__verdict-copy">{{ $verdictLabel }}</div>
            <div class="deactivate-precheck__verdict-note">{{ $verdictNote }}</div>
        </section>

        @if ($showStatsBlock)
        <section class="deactivate-precheck__stats">
            <article class="deactivate-precheck__stat deactivate-precheck__stat--warning">
                <div class="deactivate-precheck__stat-label">Мешают сейчас</div>
                <div class="deactivate-precheck__stat-value">{{ $blockingTotal }}</div>
                <div class="deactivate-precheck__stat-note">Связей нужно разобрать вручную</div>
            </article>

            <article class="deactivate-precheck__stat deactivate-precheck__stat--warning">
                <div class="deactivate-precheck__stat-label">Типов блокеров</div>
                <div class="deactivate-precheck__stat-value">{{ $blockingCount }}</div>
                <div class="deactivate-precheck__stat-note">Отдельных категорий, которые нужно открыть</div>
            </article>

            <article class="deactivate-precheck__stat deactivate-precheck__stat--info">
                <div class="deactivate-precheck__stat-label">Потребуют переноса</div>
                <div class="deactivate-precheck__stat-value">{{ $transferableTotal }}</div>
                <div class="deactivate-precheck__stat-note">Связей нужно перенести в отдельном сценарии</div>
            </article>

            <article class="deactivate-precheck__stat">
                <div class="deactivate-precheck__stat-label">Останется в истории</div>
                <div class="deactivate-precheck__stat-value">{{ $historicalTotal }}</div>
                <div class="deactivate-precheck__stat-note">Эти записи не мешают упразднению</div>
            </article>
        </section>
        @endif

        @if ($showOverviewTable)
        <section class="deactivate-precheck__card">
            <div class="deactivate-precheck__section-head">
                <div>
                    <h4 class="deactivate-precheck__section-title">Что найдено сейчас</h4>
                    <p class="deactivate-precheck__section-note">Ключевые связи места, которые нужно учитывать перед упразднением.</p>
                </div>
                <span class="deactivate-precheck__count">{{ $liveCount }}</span>
            </div>

            <div class="deactivate-precheck__table-wrap">
                <table class="deactivate-precheck__table">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Связь</th>
                            <th style="width: 12%;">Количество</th>
                            <th style="width: 18%;">Статус</th>
                            <th style="width: 34%;">Комментарий</th>
                            <th style="width: 14%;">Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($liveRelations as $item)
                            @php $action = $resolveAction($item); @endphp
                            <tr>
                                <td>{{ $item['label'] }}</td>
                                <td>{{ $item['count'] }}</td>
                                <td>
                                    <span class="{{ $badgeClass((string) $item['bucket_label']) }}">
                                        {{ $item['bucket_label'] }}
                                    </span>
                                </td>
                                <td>{{ $item['note'] }}</td>
                                <td>
                                    @if ($action['url'])
                                        <a
                                            href="{{ $action['url'] }}"
                                            @if($action['new_tab']) target="_blank" rel="noopener" @endif
                                            class="deactivate-precheck__action"
                                        >
                                            {{ $action['label'] }}
                                        </a>
                                    @else
                                        <span class="deactivate-precheck__action deactivate-precheck__action--muted">
                                            {{ $action['label'] }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="deactivate-precheck__empty">Живых связей не найдено.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif

        @foreach ($sections as $section)
            @continue(count($section['items']) === 0)
            <section class="deactivate-precheck__card">
                <div class="deactivate-precheck__section-head">
                    <div>
                        <h4 class="deactivate-precheck__section-title">{{ $section['title'] }}</h4>
                        <p class="deactivate-precheck__section-note">{{ $section['note'] }}</p>
                    </div>
                    <span class="deactivate-precheck__count">{{ array_sum(array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $section['items'])) }}</span>
                </div>

                <div class="deactivate-precheck__table-wrap">
                    <table class="deactivate-precheck__table">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Связь</th>
                                <th style="width: 14%;">Количество</th>
                            <th style="width: 42%;">Комментарий</th>
                            <th style="width: 14%;">Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['items'] as $item)
                            @php $action = $resolveAction($item); @endphp
                            <tr>
                                <td>{{ $item['label'] }}</td>
                                <td>{{ $item['count'] }}</td>
                                <td>{{ $item['note'] }}</td>
                                <td>
                                    @if ($action['url'])
                                        <a
                                            href="{{ $action['url'] }}"
                                            @if($action['new_tab']) target="_blank" rel="noopener" @endif
                                            class="deactivate-precheck__action"
                                        >
                                            {{ $action['label'] }}
                                        </a>
                                    @else
                                        <span class="deactivate-precheck__action deactivate-precheck__action--muted">
                                            {{ $action['label'] }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    </table>
                </div>
            </section>
        @endforeach

        @if (count($contractPreview) > 0)
            <section class="deactivate-precheck__card">
                <details class="deactivate-precheck__details">
                    <summary class="deactivate-precheck__details-summary">
                        <span>
                            <strong>Последние договоры по месту</strong>
                            <span class="deactivate-precheck__details-note">Показать, что именно сейчас блокирует упразднение.</span>
                        </span>
                        <span class="deactivate-precheck__count">{{ count($contractPreview) }}</span>
                    </summary>

                    <div class="deactivate-precheck__table-wrap">
                        <table class="deactivate-precheck__table">
                            <thead>
                                <tr>
                                    <th style="width: 12%;">ID</th>
                                    <th style="width: 20%;">Номер</th>
                                    <th style="width: 24%;">Арендатор</th>
                                    <th style="width: 20%;">Статус</th>
                                    <th style="width: 12%;">Активность</th>
                                    <th style="width: 12%;">Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contractPreview as $item)
                                    <tr>
                                        <td>#{{ $item['id'] }}</td>
                                        <td>{{ $item['number'] }}</td>
                                        <td>{{ $item['tenant_name'] }}</td>
                                        <td>{{ $item['status'] }}</td>
                                        <td>{{ $item['is_active'] ? 'Активен' : 'Неактивен' }}</td>
                                        <td>
                                            <a
                                                href="{{ $item['edit_url'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="deactivate-precheck__action"
                                            >
                                                Открыть
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($contractsUrl)
                        <div class="deactivate-precheck__footer-link">
                            <a href="{{ $contractsUrl }}" target="_blank" rel="noopener" class="deactivate-precheck__link deactivate-precheck__link--primary">
                                Открыть все договоры места
                            </a>
                        </div>
                    @endif
                </details>
            </section>
        @endif

        @if (count($accrualPreview) > 0)
            <section class="deactivate-precheck__card">
                <details class="deactivate-precheck__details">
                    <summary class="deactivate-precheck__details-summary">
                        <span>
                            <strong>Последние начисления по месту</strong>
                            <span class="deactivate-precheck__details-note">Показать, что именно сейчас блокирует упразднение.</span>
                        </span>
                        <span class="deactivate-precheck__count">{{ count($accrualPreview) }}</span>
                    </summary>

                    <div class="deactivate-precheck__table-wrap">
                        <table class="deactivate-precheck__table">
                            <thead>
                                <tr>
                                    <th style="width: 14%;">ID</th>
                                    <th style="width: 20%;">Период</th>
                                    <th style="width: 28%;">Договор</th>
                                    <th style="width: 24%;">Арендатор</th>
                                    <th style="width: 14%;">Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accrualPreview as $item)
                                    <tr>
                                        <td>#{{ $item['id'] }}</td>
                                        <td>{{ $item['period'] }}</td>
                                        <td>{{ $item['contract_number'] }}</td>
                                        <td>{{ $item['tenant_name'] }}</td>
                                        <td>
                                            <a
                                                href="{{ $item['edit_url'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="deactivate-precheck__action"
                                            >
                                                Открыть
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>
        @endif

        <section class="deactivate-precheck__card">
            <div class="deactivate-precheck__section-head">
                <div>
                    <h4 class="deactivate-precheck__section-title">Что сделать сейчас</h4>
                    <p class="deactivate-precheck__section-note">Разберите найденные связи и затем вернитесь к упразднению.</p>
                </div>
            </div>

            <div class="deactivate-precheck__status deactivate-precheck__status--gray" style="margin-bottom: 1rem;">
                <p class="deactivate-precheck__status-title">Следующий шаг</p>
                <div class="deactivate-precheck__status-copy">{{ $primaryNextStep }}</div>
                @if ($secondaryNextSteps !== [])
                    <div class="deactivate-precheck__status-note">
                        {{ implode(' ', array_slice($secondaryNextSteps, 0, 2)) }}
                    </div>
                @endif
            </div>

            @if ($actionSteps !== [])
                <ul class="deactivate-precheck__steps">
                    @foreach ($actionSteps as $index => $step)
                        <li>
                            <span class="deactivate-precheck__step-index">{{ $index + 1 }}</span>
                            <div>
                                <a
                                    href="{{ $step['url'] }}"
                                    @if($step['new_tab']) target="_blank" rel="noopener" @endif
                                    class="deactivate-precheck__action"
                                >
                                    {{ $step['label'] }}
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        @endif
    </div>
</div>
