<x-filament-panels::page>
    <style>
        .ops-page-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            align-items: start;

            /* чтобы контент не "лип" к краям окна */
            padding: 0 1rem;
            box-sizing: border-box;
        }

        @media (min-width: 1024px) {
            .ops-page-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .ops-main {
                grid-column: span 2 / span 2;
            }
            .ops-notes {
                grid-column: span 1 / span 1;
            }
        }

        .ops-notes {
            overflow-wrap: anywhere;
            word-break: break-word;
            box-sizing: border-box;
        }

        .ops-kv-wrap {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.10);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-wrap {
                border-color: rgba(255, 255, 255, 0.14);
            }
        }

        .ops-kv {
            min-width: 520px; /* чтобы 2 колонки не схлопывались на узком контейнере */
            font-size: 0.875rem;
        }

        .ops-kv-row {
            display: grid;
            grid-template-columns: 14rem minmax(0, 1fr);
            column-gap: 1.5rem;
            row-gap: .5rem;
            padding: 0.75rem 1rem;
            border-top: 1px solid rgba(0, 0, 0, 0.08);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-row {
                border-top-color: rgba(255, 255, 255, 0.12);
            }
        }

        .ops-kv-row:first-child {
            border-top: 0;
        }

        .ops-kv-head {
            font-weight: 600;
            background: rgba(0, 0, 0, 0.04);
        }

        @media (prefers-color-scheme: dark) {
            .ops-kv-head {
                background: rgba(255, 255, 255, 0.06);
            }
        }

        .ops-kv-key {
            white-space: nowrap;
            opacity: 0.85;
        }

        .ops-kv-val {
            min-width: 0;
        }

        /* Inline “код” делаем НЕ <code>, чтобы Filament не навязывал nowrap */
        .ops-inline-code {
            display: inline;
            padding: .125rem .25rem;
            border-radius: .25rem;
            background: rgba(0,0,0,.06);
            font-size: .75rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;

            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-width: 100%;
        }

        @media (prefers-color-scheme: dark) {
            .ops-inline-code {
                background: rgba(255,255,255,.08);
            }
        }

        .ops-side-stack {
            display: grid;
            gap: 2rem;
        }

        .ops-sidebar-sticky {
            display: grid;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .ops-sidebar-sticky {
                position: sticky;
                top: 1.5rem;
            }
        }

        .ops-hero {
            border: 1px solid rgba(15, 23, 42, .08);
            border-radius: 1.25rem;
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, .18), transparent 36%),
                radial-gradient(circle at bottom right, rgba(16, 185, 129, .14), transparent 34%),
                linear-gradient(135deg, rgba(255,255,255,.98), rgba(248,250,252,.96));
            padding: 1.25rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, .05);
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero {
                border-color: rgba(255, 255, 255, .10);
                background:
                    radial-gradient(circle at top left, rgba(14, 165, 233, .20), transparent 32%),
                    radial-gradient(circle at bottom right, rgba(16, 185, 129, .16), transparent 30%),
                    linear-gradient(135deg, rgba(15,23,42,.96), rgba(17,24,39,.92));
                box-shadow: none;
            }
        }

        .ops-hero-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: minmax(0, 1.4fr) repeat(2, minmax(0, 1fr));
        }

        @media (max-width: 1100px) {
            .ops-hero-grid {
                grid-template-columns: 1fr;
            }
        }

        .ops-hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .3rem .65rem;
            border-radius: 999px;
            background: rgba(15, 23, 42, .06);
            color: #0f172a;
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero-kicker {
                background: rgba(255,255,255,.08);
                color: #e5e7eb;
            }
        }

        .ops-hero-title {
            margin-top: .9rem;
            font-size: 1.4rem;
            line-height: 1.15;
            font-weight: 800;
            color: #0f172a;
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero-title {
                color: #f8fafc;
            }
        }

        .ops-hero-copy {
            margin-top: .55rem;
            max-width: 54rem;
            font-size: .875rem;
            line-height: 1.6;
            color: #475569;
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero-copy {
                color: #cbd5e1;
            }
        }

        .ops-hero-meta {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }

        .ops-hero-card {
            border: 1px solid rgba(15, 23, 42, .08);
            border-radius: 1rem;
            background: rgba(255,255,255,.7);
            padding: 1rem;
            min-height: 100%;
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero-card {
                border-color: rgba(255,255,255,.1);
                background: rgba(15,23,42,.52);
            }
        }

        .ops-hero-card-label {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #64748b;
        }

        .ops-hero-card-value {
            margin-top: .35rem;
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
        }

        .ops-hero-card-note {
            margin-top: .35rem;
            font-size: .78rem;
            line-height: 1.45;
            color: #64748b;
        }

        @media (prefers-color-scheme: dark) {
            .ops-hero-card-value {
                color: #f8fafc;
            }

            .ops-hero-card-note,
            .ops-hero-card-label {
                color: #94a3b8;
            }
        }

        .ops-overview-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 1100px) {
            .ops-overview-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .ops-overview-grid {
                grid-template-columns: 1fr;
            }
        }

        .ops-overview-card {
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: 1rem;
            background: rgba(248, 250, 252, .9);
            padding: 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .ops-overview-card {
                border-color: rgba(255,255,255,.1);
                background: rgba(15,23,42,.45);
            }
        }

        .ops-overview-label {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #64748b;
        }

        .ops-overview-value {
            margin-top: .4rem;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .ops-overview-note {
            margin-top: .3rem;
            font-size: .76rem;
            line-height: 1.45;
            color: #64748b;
        }

        @media (prefers-color-scheme: dark) {
            .ops-overview-value {
                color: #f8fafc;
            }

            .ops-overview-label,
            .ops-overview-note {
                color: #94a3b8;
            }
        }

        .ops-actions-grid {
            display: grid;
            gap: .75rem;
            grid-template-columns: 1fr;
        }

        .ops-actions-note {
            font-size: .78rem;
            line-height: 1.5;
            color: #64748b;
        }

        .ops-signal-grid {
            display: grid;
            gap: 1rem;
        }

        .ops-signal-group {
            display: grid;
            gap: .75rem;
        }

        .ops-signal-card {
            border: 1px solid rgba(0,0,0,.08);
            border-radius: 1rem;
            background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.94));
            padding: 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .ops-signal-card {
                border-color: rgba(255,255,255,.1);
                background: linear-gradient(180deg, rgba(17,24,39,.96), rgba(15,23,42,.9));
            }
        }

        .ops-signal-list {
            display: grid;
            gap: .45rem;
        }

        .ops-signal-row {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
            align-items: center;
            font-size: .75rem;
        }

        .ops-info-stack {
            display: grid;
            gap: .75rem;
        }

        .ops-info-item {
            border: 1px solid rgba(0, 0, 0, .10);
            border-radius: .9rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(249, 250, 251, .94));
            padding: .9rem 1rem;
            font-size: .875rem;
            line-height: 1.55;
        }

        @media (prefers-color-scheme: dark) {
            .ops-info-item {
                border-color: rgba(255, 255, 255, .12);
                background: linear-gradient(180deg, rgba(17, 24, 39, .98), rgba(17, 24, 39, .90));
            }
        }

        .ops-info-item strong {
            font-weight: 700;
        }

        .ops-command-stack {
            display: grid;
            gap: 1rem;
        }

        .ops-command-group {
            display: grid;
            gap: .5rem;
        }

        .ops-command-title {
            font-size: .875rem;
            font-weight: 700;
            color: #111827;
        }

        @media (prefers-color-scheme: dark) {
            .ops-command-title {
                color: #f8fafc;
            }
        }

        .ops-command-help {
            font-size: .75rem;
            color: #6b7280;
            line-height: 1.45;
        }

        /* КОД-БЛОКИ: теперь ПЕРЕНОСЯТСЯ, не вылезают за экран */
        .ops-codeblock {
            border-radius: .75rem;
            border: 1px solid rgba(0,0,0,.10);
            background: rgba(0,0,0,.03);
            padding: .75rem .875rem;

            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden; /* без горизонтального "выползания" */
        }

        @media (prefers-color-scheme: dark) {
            .ops-codeblock {
                border-color: rgba(255,255,255,.14);
                background: rgba(255,255,255,.06);
            }
        }

        .ops-codeblock pre {
            margin: 0;
            font-size: .75rem;
            line-height: 1.45;

            /* ключевой фикс */
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .ops-muted {
            opacity: .85;
        }

        .ops-backup-settings-card {
            border: 1px solid rgba(0, 0, 0, 0.10);
            border-radius: 1rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.94));
            padding: 1rem;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-settings-card {
                border-color: rgba(255, 255, 255, 0.12);
                background: linear-gradient(180deg, rgba(17, 24, 39, 0.96), rgba(17, 24, 39, 0.88));
                box-shadow: none;
            }
        }

        .ops-backup-settings-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .ops-backup-settings-kicker {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .55rem;
            border-radius: 999px;
            background: rgba(14, 165, 233, 0.10);
            color: #0f7490;
            font-size: .6875rem;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-settings-kicker {
                background: rgba(14, 165, 233, 0.18);
                color: #7dd3fc;
            }
        }

        .ops-backup-settings-title {
            margin-top: .15rem;
            font-size: .95rem;
            font-weight: 700;
            color: #111827;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-settings-title {
                color: #f9fafb;
            }
        }

        .ops-backup-settings-meta {
            margin-top: .25rem;
            font-size: .75rem;
            line-height: 1.45;
            color: #6b7280;
            max-width: 56rem;
        }

        .ops-backup-settings-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: .75rem;
        }

        .ops-backup-settings-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            padding: .3rem .6rem;
            background: rgba(14, 165, 233, 0.10);
            color: #0f7490;
            font-size: .6875rem;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-settings-chip {
                background: rgba(14, 165, 233, 0.18);
                color: #7dd3fc;
            }
        }

        .ops-backup-settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            align-items: start;
        }

        @media (max-width: 640px) {
            .ops-backup-settings-grid {
                grid-template-columns: 1fr;
            }
        }

        .ops-backup-field--wide {
            grid-column: 1 / -1;
        }

        .ops-backup-field {
            display: grid;
            gap: .35rem;
        }

        .ops-backup-field-label {
            font-size: .75rem;
            font-weight: 700;
            color: #374151;
            line-height: 1.2;
        }

        .ops-backup-input {
            width: 100%;
            border: 1px solid rgba(0, 0, 0, .12);
            border-radius: .75rem;
            padding: .85rem .9rem;
            font-size: .875rem;
            background: rgba(255, 255, 255, .96);
            min-height: 3.25rem;
            box-sizing: border-box;
        }

        .ops-backup-input--compact {
            width: min(100%, 8rem);
            justify-self: start;
            text-align: left;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-input {
                border-color: rgba(255, 255, 255, .14);
                background: rgba(17, 24, 39, .75);
                color: #f9fafb;
            }
        }

        .ops-backup-help {
            font-size: .6875rem;
            color: #6b7280;
            line-height: 1.4;
        }

        .ops-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 1100px) {
            .ops-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .ops-stat-grid {
                grid-template-columns: 1fr;
            }
        }

        .ops-stat-card {
            border: 1px solid rgba(0, 0, 0, .10);
            border-radius: .95rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(249, 250, 251, .94));
            padding: .95rem 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .ops-stat-card {
                border-color: rgba(255, 255, 255, .12);
                background: linear-gradient(180deg, rgba(17, 24, 39, .98), rgba(17, 24, 39, .90));
            }
        }

        .ops-stat-label {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6b7280;
        }

        .ops-stat-value {
            margin-top: .25rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            overflow-wrap: anywhere;
        }

        @media (prefers-color-scheme: dark) {
            .ops-stat-value {
                color: #f8fafc;
            }
        }

        .ops-stat-subtext {
            margin-top: .15rem;
            font-size: .75rem;
            color: #94a3b8;
            overflow-wrap: anywhere;
        }

        .ops-backup-actions-row {
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
        }

        .ops-loading-note {
            margin-bottom: 1rem;
            font-size: .75rem;
            color: #6b7280;
        }

        .ops-backup-files-section {
            display: grid;
            gap: .75rem;
        }

        .ops-backup-files-title {
            font-size: .875rem;
            font-weight: 700;
            color: #374151;
        }

        .ops-backup-files-list {
            border: 1px solid rgba(0, 0, 0, .10);
            border-radius: 1rem;
            overflow: hidden;
            background: rgba(255, 255, 255, .92);
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-files-list {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(17, 24, 39, .82);
            }
        }

        .ops-backup-file-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .75rem;
            padding: .85rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, .06);
        }

        .ops-backup-file-row:last-child {
            border-bottom: 0;
        }

        .ops-backup-file-meta {
            display: flex;
            align-items: center;
            gap: .75rem;
            flex: 1;
            min-width: 0;
        }

        .ops-backup-file-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .625rem;
            background: rgba(0, 0, 0, .06);
            flex-shrink: 0;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-file-icon {
                background: rgba(255, 255, 255, .08);
            }
        }

        .ops-backup-file-name {
            font-size: .8125rem;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (prefers-color-scheme: dark) {
            .ops-backup-file-name {
                color: #f8fafc;
            }
        }

        .ops-backup-file-meta-text {
            font-size: .6875rem;
            color: #6b7280;
        }

        .ops-backup-file-actions {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
        }

        .ops-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 1px dashed rgba(0, 0, 0, .15);
            border-radius: 1rem;
            padding: 2.5rem 1.5rem;
            text-align: center;
        }

        @media (prefers-color-scheme: dark) {
            .ops-empty-state {
                border-color: rgba(255, 255, 255, .18);
            }
        }

        .ops-toggle-preview {
            margin-top: 1.25rem;
        }

        .ops-toggle-preview-button {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            border-radius: .75rem;
            border: 1px solid rgba(0, 0, 0, .10);
            background: rgba(0, 0, 0, .03);
            padding: .6rem .85rem;
            font-size: .8125rem;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
        }

        @media (prefers-color-scheme: dark) {
            .ops-toggle-preview-button {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(255, 255, 255, .06);
                color: #e5e7eb;
            }
        }

        .ops-toggle-preview-panel {
            margin-top: .75rem;
            border: 1px solid rgba(0, 0, 0, .10);
            border-radius: 1rem;
            background: rgba(0, 0, 0, .03);
            padding: 1rem;
        }

        @media (prefers-color-scheme: dark) {
            .ops-toggle-preview-panel {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(255, 255, 255, .06);
            }
        }

        .ops-tabs-shell {
            grid-column: 1 / -1;
            display: grid;
            gap: 1.25rem;
        }

        .ops-tablist {
            display: flex;
            flex-wrap: wrap;
            gap: .6rem;
            padding: .35rem;
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: 1rem;
            background: rgba(248, 250, 252, .92);
        }

        @media (prefers-color-scheme: dark) {
            .ops-tablist {
                border-color: rgba(255,255,255,.1);
                background: rgba(15, 23, 42, .55);
            }
        }

        .ops-tabbutton {
            appearance: none;
            border: 0;
            border-radius: .8rem;
            background: transparent;
            color: #475569;
            cursor: pointer;
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .01em;
            padding: .8rem 1rem;
            transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
        }

        .ops-tabbutton:hover {
            background: rgba(15, 23, 42, .05);
            color: #0f172a;
        }

        .ops-tabbutton[data-active="true"] {
            background: linear-gradient(135deg, rgba(14, 165, 233, .12), rgba(16, 185, 129, .10));
            box-shadow: inset 0 0 0 1px rgba(14, 165, 233, .16);
            color: #0f172a;
        }

        @media (prefers-color-scheme: dark) {
            .ops-tabbutton {
                color: #cbd5e1;
            }

            .ops-tabbutton:hover {
                background: rgba(255,255,255,.06);
                color: #f8fafc;
            }

            .ops-tabbutton[data-active="true"] {
                background: linear-gradient(135deg, rgba(14, 165, 233, .22), rgba(16, 185, 129, .18));
                box-shadow: inset 0 0 0 1px rgba(125, 211, 252, .18);
                color: #f8fafc;
            }
        }

        .ops-tabpanel {
            display: grid;
            gap: 1.5rem;
        }
    </style>

    @php
        $telescopeInstalledLocal = isset($telescopeInstalled) ? (bool) $telescopeInstalled : false;
        $telescopeConfigEnabledLocal = $telescopeConfigEnabled ?? ($telescopeInstalledLocal ? (bool) config('telescope.enabled', true) : false);
        $telescopeRecordingEnabledLocal = $telescopeRecordingEnabled ?? ($telescopeEnabled ?? false);

        $telescopeEnabledUntilLocal = $telescopeEnabledUntil ?? null;
        $telescopeEnabledUntilHumanLocal = $telescopeEnabledUntilHuman ?? null;

        $pgBackupSettingsLocal = $this->pgBackupSettings ?: ($pgBackupSettings ?? []);
        $pgBackupDefaultsLocal = $pgBackupDefaults ?? [
            'compressAfterDays' => isset($pgBackupSettingsLocal['compress_after_days'])
                ? (int) $pgBackupSettingsLocal['compress_after_days']
                : 2,
            'deleteArchiveAfterDays' => isset($pgBackupSettingsLocal['delete_archive_after_days'])
                ? (int) $pgBackupSettingsLocal['delete_archive_after_days']
                : 60,
        ];
        $pgBackupStatusLocal = $this->pgBackupStatus ?: ($pgBackupStatus ?? []);
        $pgBackupFilesLocal = $this->pgBackupFiles ?: ($pgBackupFiles ?? []);
        $pgBackupPreviewLocal = $this->pgBackupPreview ?: ($pgBackupPreview ?? [
            'compress' => [],
            'deleteDuplicates' => [],
            'deleteArchives' => [],
        ]);
        $pgBackupLogLocal = $this->pgBackupLog ?: ($pgBackupLog ?? []);
        $selectedMarketIdLocal = (int) ($selectedMarketId ?? 0);
        $selectedMarketNameLocal = (string) ($selectedMarketName ?? '');
        $tenantDuplicateSignalsLocal = is_array($tenantDuplicateSignals ?? null) ? $tenantDuplicateSignals : [];
        $spaceDuplicateSignalsLocal = is_array($spaceDuplicateSignals ?? null) ? $spaceDuplicateSignals : [];
        $groupEpisodesUrlLocal = $groupEpisodesUrl ?? null;
    @endphp

    @if ($canViewIntegrationJournal && ! $canUseOpsTools)
        <x-filament::section
            heading="Журнал интеграций"
            description="Просмотр входящих и исходящих обменов с 1С и другими интеграциями."
        >
            <div style="display: grid; gap: 1rem;">
                <div class="ops-muted" style="font-size: .875rem; line-height: 1.5;">
                    Для <span class="ops-inline-code">market-operator</span> этот раздел является основным режимом страницы
                    диагностики. Открывается журнал обменов по вашему рынку без доступа к ops-инструментам.
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                    <x-filament::badge color="success">
                        Доступ открыт
                    </x-filament::badge>

                    <x-filament::button
                        tag="a"
                        href="{{ $integrationExchangesUrl }}"
                        icon="heroicon-m-arrow-top-right-on-square"
                    >
                        Открыть журнал интеграций
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($canUseOpsTools)
    <div class="ops-page-grid">
        <div class="ops-hero" style="grid-column: 1 / -1;">
            <div class="ops-hero-grid">
                <div>
                    <span class="ops-hero-kicker">Ops Diagnostics</span>
                    <div class="ops-hero-title">Операционная диагностика и контроль качества данных</div>
                    <div class="ops-hero-copy">
                        Здесь должны быть быстрый обзор состояния, проблемные сигналы и инструменты обслуживания. Всё, что требует чтения и анализа, остаётся в основной колонке. Всё, что запускает действия или служит шпаргалкой, уходит в правую колонку.
                    </div>
                    <div class="ops-hero-meta">
                        <x-filament::badge color="gray">окружение: {{ $appEnv }}</x-filament::badge>
                        <x-filament::badge color="gray">коммит: {{ $gitCommitShort ?: '—' }}</x-filament::badge>
                        @if ($selectedMarketIdLocal > 0)
                            <x-filament::badge color="success">рынок: {{ $selectedMarketIdLocal }}</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">рынок не выбран</x-filament::badge>
                        @endif
                    </div>
                </div>

                <div class="ops-hero-card">
                    <div class="ops-hero-card-label">Текущий рынок</div>
                    <div class="ops-hero-card-value">{{ $selectedMarketNameLocal !== '' ? $selectedMarketNameLocal : 'Не выбран' }}</div>
                    <div class="ops-hero-card-note">
                        @if ($selectedMarketIdLocal > 0)
                            ID рынка: {{ $selectedMarketIdLocal }}
                        @else
                            Сигналы дублей и часть диагностики зависят от выбранного рынка.
                        @endif
                    </div>
                </div>

                <div class="ops-hero-card">
                    <div class="ops-hero-card-label">Сигналы сейчас</div>
                    <div class="ops-hero-card-value">{{ count($spaceDuplicateSignalsLocal) + count($tenantDuplicateSignalsLocal) }}</div>
                    <div class="ops-hero-card-note">
                        {{ count($spaceDuplicateSignalsLocal) }} групп дублей мест · {{ count($tenantDuplicateSignalsLocal) }} сигналов дублей арендаторов
                    </div>
                </div>
            </div>
        </div>

        <div class="ops-tabs-shell" x-data="{ tab: 'overview' }">
            <div class="ops-tablist" role="tablist" aria-label="Разделы диагностики">
                <button type="button" class="ops-tabbutton" :data-active="tab === 'overview'" @click="tab = 'overview'">Обзор</button>
                <button type="button" class="ops-tabbutton" :data-active="tab === 'signals'" @click="tab = 'signals'">Сигналы</button>
                <button type="button" class="ops-tabbutton" :data-active="tab === 'onec-debt-preview'" @click="tab = 'onec-debt-preview'">Аудит карты</button>
                <button type="button" class="ops-tabbutton" :data-active="tab === 'maintenance'" @click="tab = 'maintenance'">Обслуживание</button>
                <button type="button" class="ops-tabbutton" :data-active="tab === 'backups'" @click="tab = 'backups'">Бэкапы</button>
                <button type="button" class="ops-tabbutton" :data-active="tab === 'commands'" @click="tab = 'commands'">Команды</button>
            </div>

            <div class="ops-tabpanel" x-show="tab === 'overview'">
                @if ($canViewIntegrationJournal)
                    <x-filament::section
                        heading="Журнал интеграций"
                        description="Отдельный вход в журнал обменов. Не смешивается с ops-инструментами."
                    >
                        <div style="display: grid; gap: 1rem;">
                            <div class="ops-muted" style="font-size: .875rem; line-height: 1.5;">
                                Быстрый переход в журнал входящих и исходящих обменов с 1С и другими интеграциями.
                            </div>

                            <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                                <x-filament::badge color="success">
                                    Доступ открыт
                                </x-filament::badge>

                                <x-filament::button
                                    tag="a"
                                    href="{{ $integrationExchangesUrl }}"
                                    icon="heroicon-m-arrow-top-right-on-square"
                                >
                                    Открыть журнал интеграций
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                @endif

                @if ($groupEpisodesUrlLocal)
                    <x-filament::section
                        heading="Служебные справочники"
                        description="Редко используемые инструменты для проверки и сопровождения данных карты."
                    >
                        <div style="display: grid; gap: 1rem;">
                            <div class="ops-muted" style="font-size: .875rem; line-height: 1.5;">
                                Эпизоды групп мест хранят исторический состав временных групп. Это справочный слой для проверки договоров и начислений по датам, а не ежедневный рабочий раздел.
                            </div>

                            <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                                <x-filament::badge color="gray">
                                    Только super-admin
                                </x-filament::badge>

                                <x-filament::button
                                    tag="a"
                                    href="{{ $groupEpisodesUrlLocal }}"
                                    icon="heroicon-m-arrow-top-right-on-square"
                                >
                                    Открыть эпизоды групп мест
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                @endif

                <x-filament::section
                    heading="Диагностика системы"
                    description="Короткий обзор окружения, версии и состояния Telescope."
                >
                    <div class="ops-overview-grid">
                        <div class="ops-overview-card">
                            <div class="ops-overview-label">Окружение</div>
                            <div class="ops-overview-value">{{ $appEnv }}</div>
                            <div class="ops-overview-note">Текущее окружение приложения</div>
                        </div>

                        <div class="ops-overview-card">
                            <div class="ops-overview-label">Telescope</div>
                            <div class="ops-overview-value">{{ $telescopeInstalled ? 'Установлен' : 'Отсутствует' }}</div>
                            <div class="ops-overview-note">{{ $telescopeRecordingEnabledLocal ? 'Запись включена' : 'Запись выключена' }}</div>
                        </div>

                        <div class="ops-overview-card">
                            <div class="ops-overview-label">Ветка</div>
                            <div class="ops-overview-value">{{ $gitBranch ?: '—' }}</div>
                            <div class="ops-overview-note">PR: {{ $gitVersionLabel ?: '—' }}</div>
                        </div>

                        <div class="ops-overview-card">
                            <div class="ops-overview-label">Путь</div>
                            <div class="ops-overview-value">{{ $gitCommitShort ?: '—' }}</div>
                            <div class="ops-overview-note">{{ $appPath ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="ops-kv-wrap">
                        <div class="ops-kv">
                            <div class="ops-kv-row ops-kv-head">
                                <div>Параметр</div>
                                <div>Значение</div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Окружение</div>
                                <div class="ops-kv-val">
                                    <div style="display:flex; align-items:center; gap: 1rem;">
                                        <x-filament::badge color="success">
                                            {{ $appEnv }}
                                        </x-filament::badge>
                                    </div>
                                </div>
                            </div>

                            <div class="ops-kv-row" style="align-items: start;">
                                <div class="ops-kv-key">Telescope</div>
                                <div class="ops-kv-val">
                                    <div style="display:flex; flex-wrap:wrap; gap: .75rem; align-items:center;">
                                        <x-filament::badge :color="$telescopeInstalled ? 'success' : 'gray'">
                                            {{ $telescopeInstalled ? 'Установлен' : 'Не установлен' }}
                                        </x-filament::badge>

                                        @if ($telescopeInstalled)
                                            <x-filament::badge :color="$telescopeConfigEnabledLocal ? 'success' : 'warning'">
                                                {{ $telescopeConfigEnabledLocal ? 'Интерфейс доступен' : 'Интерфейс выключен в конфиге' }}
                                            </x-filament::badge>

                                            <x-filament::badge :color="$telescopeRecordingEnabledLocal ? 'success' : 'warning'">
                                                {{ $telescopeRecordingEnabledLocal ? 'Запись включена' : 'Запись выключена' }}
                                            </x-filament::badge>
                                        @endif
                                    </div>

                                    @if ($telescopeInstalled)
                                        <div class="ops-muted" style="font-size: .75rem; margin-top: .35rem;">
                                            @if ($telescopeRecordingEnabledLocal)
                                                Авто-выключение:
                                                <span class="ops-inline-code">{{ $telescopeEnabledUntilLocal ?? '—' }}</span>
                                                @if (! empty($telescopeEnabledUntilHumanLocal))
                                                    ({{ $telescopeEnabledUntilHumanLocal }})
                                                @endif
                                            @else
                                                Запись по умолчанию выключена вне локального окружения. Можно включить временно на 30 минут.
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Доступ</div>
                                <div class="ops-kv-val">
                                    <x-filament::badge color="warning">
                                        Только суперадмин
                                    </x-filament::badge>
                                </div>
                            </div>

                            <div class="ops-kv-row" style="align-items: start;">
                                <div class="ops-kv-key">Путь приложения</div>
                                <div class="ops-kv-val">
                                    <span class="ops-inline-code">{{ $appPath ?? '—' }}</span>
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Ветка</div>
                                <div class="ops-kv-val">
                                    <x-filament::badge color="gray">
                                        {{ $gitBranch ?: '—' }}
                                    </x-filament::badge>
                                </div>
                            </div>

                            <div class="ops-kv-row">
                                <div class="ops-kv-key">Коммит</div>
                                <div class="ops-kv-val">
                                    <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                                        <x-filament::badge color="gray">
                                            {{ $gitCommitShort ?: '—' }}
                                        </x-filament::badge>

                                        @if (! empty($gitVersionLabel))
                                            <x-filament::badge color="gray">
                                                PR: {{ $gitVersionLabel }}
                                            </x-filament::badge>
                                        @endif
                                    </div>

                                    <div class="ops-muted" style="font-size: .75rem; margin-top: .25rem;">
                                        Номер PR берётся из сообщения последнего коммита merge/squash, чтобы проще сравнивать, что новее.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            <div class="ops-tabpanel" x-show="tab === 'signals'">
                <x-filament::section
                    heading="Сигналы дублей"
                    description="Сигналы только для чтения по возможным дублям торговых мест и арендаторов для выбранного рынка."
                >
                    <div class="ops-signal-grid">
                        <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;">
                            <x-filament::badge :color="$selectedMarketIdLocal > 0 ? 'success' : 'warning'">
                                {{ $selectedMarketIdLocal > 0 ? 'ID рынка: ' . $selectedMarketIdLocal : 'Рынок не выбран' }}
                            </x-filament::badge>

                            @if ($selectedMarketNameLocal !== '')
                                <span class="ops-muted" style="font-size:.8125rem;">{{ $selectedMarketNameLocal }}</span>
                            @endif
                        </div>

                        <div style="display:grid; gap:1rem; grid-template-columns:repeat(auto-fit, minmax(18rem, 1fr));">
                            <div class="ops-stat-card">
                                <p class="ops-stat-label">Дубли мест</p>
                                <p class="ops-stat-value">{{ count($spaceDuplicateSignalsLocal) }}</p>
                                <p class="ops-stat-subtext">Группы с одинаковым нормализованным номером</p>
                            </div>

                            <div class="ops-stat-card">
                                <p class="ops-stat-label">Дубли арендаторов</p>
                                <p class="ops-stat-value">{{ count($tenantDuplicateSignalsLocal) }}</p>
                                <p class="ops-stat-subtext">Сигналы по похожим или конфликтующим карточкам</p>
                            </div>
                        </div>

                        @if ($spaceDuplicateSignalsLocal === [] && $tenantDuplicateSignalsLocal === [])
                            <div class="ops-empty-state">
                                <p style="font-size:.8125rem; color:#6b7280;">Для выбранного рынка явных сигналов дублей сейчас нет.</p>
                            </div>
                        @else
                            @if ($spaceDuplicateSignalsLocal !== [])
                                <div class="ops-signal-group">
                                    <p class="ops-backup-files-title">Торговые места</p>
                                    @foreach ($spaceDuplicateSignalsLocal as $signal)
                                        <div class="ops-signal-card">
                                            <div style="display:grid; gap:.4rem;">
                                                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                                                    <x-filament::badge :color="$signal['severity'] === 'high' ? 'danger' : 'warning'" size="sm">
                                                        {{ ($signal['severity'] ?? '') === 'high' ? 'Высокий риск' : 'Средний риск' }}
                                                    </x-filament::badge>
                                                    <span style="font-size:.875rem; font-weight:700;">{{ $signal['normalized_number'] }}</span>
                                                    <span class="ops-muted" style="font-size:.75rem;">{{ $signal['count'] }} шт.</span>
                                                </div>

                                                @if (! empty($signal['reasons']))
                                                    <div class="ops-muted" style="font-size:.75rem; line-height:1.45;">
                                                        {{ implode(' · ', $signal['reasons']) }}
                                                    </div>
                                                @endif

                                                <div class="ops-signal-list">
                                                    @foreach (($signal['spaces'] ?? []) as $space)
                                                        <div class="ops-signal-row">
                                                            @if (! empty($space['url']))
                                                                <a href="{{ $space['url'] }}" style="font-weight:700; color:inherit; text-decoration:underline;">
                                                                    #{{ $space['id'] ?? '—' }} · {{ ($space['number'] ?? '') !== '' ? $space['number'] : 'без номера' }}
                                                                </a>
                                                            @else
                                                                <span style="font-weight:700;">#{{ $space['id'] ?? '—' }} · {{ ($space['number'] ?? '') !== '' ? $space['number'] : 'без номера' }}</span>
                                                            @endif
                                                            <span class="ops-muted">{{ ($space['display_name'] ?? '') !== '' ? $space['display_name'] : '—' }}</span>
                                                            <span class="ops-muted">ID арендатора: {{ $space['tenant_id'] ?? 'нет' }}</span>
                                                            <span class="ops-muted">Статус: {{ ($space['status'] ?? '') !== '' ? $space['status'] : '—' }}</span>
                                                            <span class="ops-muted">Роль в группе: {{ ($space['space_group_role'] ?? '') !== '' ? $space['space_group_role'] : '—' }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>

                                                <div class="ops-muted" style="font-size:.75rem; line-height:1.45;">
                                                    {{ $signal['recommendation'] }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($tenantDuplicateSignalsLocal !== [])
                                <div class="ops-signal-group">
                                    <p class="ops-backup-files-title">Арендаторы</p>
                                    @foreach ($tenantDuplicateSignalsLocal as $signal)
                                        <div class="ops-signal-card">
                                            <div style="display:grid; gap:.4rem;">
                                                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                                                    <x-filament::badge :color="($signal['severity'] ?? '') === 'high' ? 'danger' : 'warning'" size="sm">
                                                        {{ ($signal['severity'] ?? '') === 'high' ? 'Высокий риск' : 'Средний риск' }}
                                                    </x-filament::badge>
                                                    <span style="font-size:.875rem; font-weight:700;">{{ $signal['title'] ?? 'Возможный дубль арендатора' }}</span>
                                                    <span class="ops-muted" style="font-size:.75rem;">оценка {{ $signal['score'] ?? '—' }}</span>
                                                </div>

                                                <div class="ops-signal-list">
                                                    @foreach (['candidate_a', 'candidate_b'] as $key)
                                                        @php $tenant = $signal[$key] ?? []; @endphp
                                                        <div class="ops-signal-row">
                                                            @if (! empty($tenant['url']))
                                                                <a href="{{ $tenant['url'] }}" style="font-weight:700; color:inherit; text-decoration:underline;">
                                                                    #{{ $tenant['id'] ?? '—' }} · {{ $tenant['name'] ?? '—' }}
                                                                </a>
                                                            @else
                                                                <span style="font-weight:700;">#{{ $tenant['id'] ?? '—' }} · {{ $tenant['name'] ?? '—' }}</span>
                                                            @endif
                                                            <span class="ops-muted">ИНН {{ ($tenant['inn'] ?? '') !== '' ? $tenant['inn'] : '—' }}</span>
                                                            <span class="ops-muted">внешний ID {{ ($tenant['external_id'] ?? '') !== '' ? $tenant['external_id'] : '—' }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>

                                                @if (! empty($signal['reasons']))
                                                    <div class="ops-muted" style="font-size:.75rem; line-height:1.45;">
                                                        {{ implode(' · ', $signal['reasons']) }}
                                                    </div>
                                                @endif

                                                <div class="ops-muted" style="font-size:.75rem; line-height:1.45;">
                                                    {{ $signal['recommendation'] ?? '' }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </x-filament::section>
            </div>

            <div class="ops-tabpanel" x-show="tab === 'onec-debt-preview'">
                @livewire(\App\Filament\Pages\OneCDebtDecisionPreview::class, ['embedded' => true], key('ops-one-c-debt-decision-preview'))
            </div>

            <div class="ops-tabpanel" x-show="tab === 'maintenance'">
                <x-filament::section
                    heading="Быстрые действия"
                    description="Опасные и технические действия отделены от аналитических блоков."
                >
                    <div class="ops-actions-grid">
                        <x-filament::button
                            icon="heroicon-m-arrow-path"
                            wire:click="clearCaches"
                            title="Выполняет php artisan optimize:clear."
                        >
                            Очистить кэши
                        </x-filament::button>

                        <x-filament::button
                            color="success"
                            icon="heroicon-m-play"
                            wire:click="enableTelescope30m"
                            :disabled="! $telescopeInstalled || $telescopeRecordingEnabledLocal"
                            title="Включает запись Telescope на 30 минут и автоматически выключает её по TTL. Доступ только для суперадмина."
                        >
                            Включить Telescope (30 мин)
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            icon="heroicon-m-stop"
                            wire:click="disableTelescope"
                            :disabled="! $telescopeInstalled || ! $telescopeRecordingEnabledLocal"
                        >
                            Выключить Telescope
                        </x-filament::button>

                        @if ($telescopeInstalled && $telescopeConfigEnabledLocal)
                            <x-filament::button
                                color="gray"
                                icon="heroicon-m-arrow-top-right-on-square"
                                tag="a"
                                href="{{ url('/telescope') }}"
                                target="_blank"
                                rel="noopener"
                            >
                                Открыть Telescope
                            </x-filament::button>
                        @endif

                        <x-filament::button
                            color="warning"
                            icon="heroicon-m-trash"
                            wire:click="pruneTelescope"
                            :disabled="! $telescopeInstalled"
                            title="Удаляет записи Telescope старше 48 часов, если Telescope установлен и таблицы доступны."
                        >
                            Очистить Telescope (48ч)
                        </x-filament::button>

                        <div class="ops-actions-note">
                            Эти действия запускаются на сервере. Они вынесены в отдельную вкладку, чтобы не конкурировать визуально с диагностическими данными и не провоцировать случайные нажатия во время анализа.
                        </div>
                    </div>
                </x-filament::section>
            </div>

            <div class="ops-tabpanel" x-show="tab === 'backups'">
                <div wire:poll.visible.15s="refreshPgBackupState">
                    <x-filament::section
                        heading="Бэкапы PostgreSQL"
                        description="Управление дампами базы данных и ротация архивов."
                    >
                {{-- Статистика: 4 карточки в ряд --}}
                <div class="ops-stat-grid">
                    <div class="ops-stat-card">
                        <p class="ops-stat-label">База данных</p>
                        <p class="ops-stat-value">{{ $pgBackupStatusLocal['dbName'] ?? '—' }}</p>
                        <p class="ops-stat-subtext">{{ $pgBackupStatusLocal['dbHost'] ?? '—' }}:{{ $pgBackupStatusLocal['dbPort'] ?? '—' }}</p>
                    </div>

                    <div class="ops-stat-card">
                        <p class="ops-stat-label">Бэкапы</p>
                        <p class="ops-stat-value">{{ $pgBackupStatusLocal['totalBackups'] ?? 0 }}</p>
                        <p class="ops-stat-subtext">Общий: {{ $pgBackupStatusLocal['totalSizeHuman'] ?? '0 Б' }}</p>
                    </div>

                    <div class="ops-stat-card">
                        <p class="ops-stat-label">Последний бэкап</p>
                        <p class="ops-stat-value">{{ $pgBackupStatusLocal['lastBackupTimeHuman'] ?? 'Нет' }}</p>
                        <p class="ops-stat-subtext">{{ $pgBackupStatusLocal['lastBackupSizeHuman'] ?? '' }}</p>
                    </div>

                    <div class="ops-stat-card">
                        <p class="ops-stat-label">Диск</p>
                        <p class="ops-stat-value">{{ $pgBackupStatusLocal['diskFreeHuman'] ?? '—' }}</p>
                        <p class="ops-stat-subtext">Всего: {{ $pgBackupStatusLocal['diskTotalHuman'] ?? '—' }}</p>
                    </div>
                </div>

                <div class="ops-loading-note" wire:loading.flex wire:target="rotatePgBackups">
                    Выполняется ротация бэкапов, это может занять время.
                </div>

                {{-- Действия --}}
                <div style="margin-bottom:1.5rem;">
                    <div class="ops-backup-settings-card">
                        <div class="ops-backup-settings-head">
                            <div>
                                <div class="ops-backup-settings-kicker">Технические параметры</div>
                                <p class="ops-backup-settings-title">Настройки бэкапов</p>
                                <p class="ops-backup-settings-meta">
                                    Управляют тем, как создается бэкап и как дальше применяется ротация.
                                    Параметры вступают в силу сразу для ручной кнопки и scheduler.
                                </p>
                                <div class="ops-backup-settings-chip-row">
                                    <span class="ops-backup-settings-chip">pg_dump</span>
                                    <span class="ops-backup-settings-chip">ручной запуск</span>
                                    <span class="ops-backup-settings-chip">scheduler</span>
                                </div>
                            </div>

                            <x-filament::button
                                wire:click="savePgBackupSettings"
                                wire:loading.attr="disabled"
                                wire:target="savePgBackupSettings"
                                color="primary"
                                icon="heroicon-o-check"
                                size="sm"
                            >
                                Сохранить
                            </x-filament::button>
                        </div>

                        <div class="ops-backup-settings-grid">
                            <label class="ops-backup-field ops-backup-field--wide">
                                <span class="ops-backup-field-label">Путь к pg_dump</span>
                                <input
                                    class="ops-backup-input"
                                    type="text"
                                    wire:model.defer="pgBackupSettings.dump_binary"
                                    placeholder="Автоопределение"
                                >
                                <span class="ops-backup-help">Если пусто, используется путь из Laragon или `PATH`.</span>
                            </label>

                            <label class="ops-backup-field">
                                <span class="ops-backup-field-label">Сжимать старше, дней</span>
                                <input
                                    class="ops-backup-input ops-backup-input--compact"
                                    type="number"
                                    min="0"
                                    step="1"
                                    wire:model.defer="pgBackupSettings.compress_after_days"
                                >
                            </label>

                            <label class="ops-backup-field">
                                <span class="ops-backup-field-label">Удалять архивы старше, дней</span>
                                <input
                                    class="ops-backup-input ops-backup-input--compact"
                                    type="number"
                                    min="0"
                                    step="1"
                                    wire:model.defer="pgBackupSettings.delete_archive_after_days"
                                >
                            </label>
                        </div>
                    </div>
                </div>

                <div class="ops-backup-actions-row">
                    <x-filament::button
                        wire:click="createPgBackup"
                        wire:loading.attr="disabled"
                        wire:target="createPgBackup"
                        color="primary"
                        icon="heroicon-m-arrow-down-tray"
                    >
                        Создать бэкап
                    </x-filament::button>

                    <x-filament::button
                        wire:click="rotatePgBackups({{ (int) $pgBackupDefaultsLocal['compressAfterDays'] }}, {{ (int) $pgBackupDefaultsLocal['deleteArchiveAfterDays'] }})"
                        wire:loading.attr="disabled"
                        wire:target="rotatePgBackups"
                        color="warning"
                        icon="heroicon-m-arrow-path"
                    >
                        Ротация сейчас
                    </x-filament::button>
                </div>

                {{-- Список файлов --}}
                @if (! empty($pgBackupLogLocal['exists']))
                    <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:1rem;">
                        <x-filament::button
                            tag="a"
                            href="{{ route('filament.admin.ops-diagnostics.backup-log') }}"
                            icon="heroicon-o-document-text"
                            size="sm"
                            color="gray"
                            labeled-from="sm"
                        >
                            Последний backup-log
                        </x-filament::button>

                        <span class="ops-backup-help">
                            {{ $pgBackupLogLocal['name'] }} · {{ $pgBackupLogLocal['mtimeHuman'] }} · {{ $pgBackupLogLocal['sizeHuman'] }}
                        </span>
                    </div>
                @endif

                <div class="ops-backup-files-section">
                    <p class="ops-backup-files-title">Файлы бэкапов</p>

                    @if (! empty($pgBackupFilesLocal))
                        <div class="ops-backup-files-list">
                            @foreach ($pgBackupFilesLocal as $idx => $file)
                                <div
                                    class="ops-backup-file-row"
                                    wire:key="pg-backup-row-{{ $file['name'] }}"
                                >
                                    <div class="ops-backup-file-meta">
                                        <div class="ops-backup-file-icon">
                                            @if ($file['type'] === 'gz')
                                                <x-heroicon-o-archive-box style="width:1.125rem; height:1.125rem; color:#6b7280;" />
                                            @else
                                                <x-heroicon-o-document-text style="width:1.125rem; height:1.125rem; color:#6b7280;" />
                                            @endif
                                        </div>
                                        <div style="min-width:0;">
                                            <p class="ops-backup-file-name">{{ $file['name'] }}</p>
                                            <p class="ops-backup-file-meta-text">{{ $file['mtimeHuman'] }} · {{ $file['sizeHuman'] }}</p>
                                        </div>
                                    </div>

                                    <div class="ops-backup-file-actions">
                                        <x-filament::badge :color="$file['type'] === 'gz' ? 'success' : 'gray'" size="sm">
                                            {{ $file['type'] === 'gz' ? 'GZIP' : 'SQL' }}
                                        </x-filament::badge>

                                        <x-filament::button
                                            tag="a"
                                            :href="route('filament.admin.ops-diagnostics.download', ['file' => $file['name']])"
                                            icon="heroicon-o-arrow-down-tray"
                                            size="sm"
                                            color="gray"
                                            labeled-from="sm"
                                        >
                                            Скачать
                                        </x-filament::button>

                                        <x-filament::button
                                            wire:click="deletePgBackup('{{ $file['name'] }}')"
                                            icon="heroicon-o-trash"
                                            size="sm"
                                            color="danger"
                                            outlined
                                            labeled-from="sm"
                                        >
                                            Удалить
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="ops-empty-state">
                            <svg style="flex-shrink:0; width:2.5rem; height:2.5rem; max-width:2.5rem; max-height:2.5rem; color:#9ca3af; margin-bottom:.5rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                            </svg>
                            <p style="font-size:.8125rem; color:#6b7280;">Бэкапы ещё не создавались</p>
                        </div>
                    @endif
                </div>

                {{-- Предпросмотр ротации (скрыт по умолчанию) --}}
                <div class="ops-toggle-preview" x-data="{ open: false }">
                    <button
                        @click="open = ! open"
                        class="ops-toggle-preview-button"
                    >
                        <!-- Fix: replaced x-heroicon-o-chevron-down with inline svg to avoid Blade :class error -->
                        <svg style="flex-shrink:0; width:1rem; height:1rem; max-width:1rem; max-height:1rem; transition:transform .2s;" x-bind:style="open ? 'transform:rotate(180deg)' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                        <span>Предпросмотр ротации</span>
                    </button>

                    <div x-show="open" x-collapse class="ops-toggle-preview-panel">
                        <p style="font-size:.6875rem; color:#6b7280; margin-bottom:.75rem;">
                            Сжатие старше {{ $pgBackupDefaultsLocal['compressAfterDays'] }} дн., удаление архивов старше {{ $pgBackupDefaultsLocal['deleteArchiveAfterDays'] }} дн.
                        </p>

                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Сжать (*.sql → *.gz)</p>
                                @if (! empty($pgBackupPreviewLocal['compress']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['compress'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Удалить дубли</p>
                                @if (! empty($pgBackupPreviewLocal['deleteDuplicates']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['deleteDuplicates'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
                                @endif
                            </div>
                            <div>
                                <p style="font-size:.6875rem; font-weight:600; color:#374151;">Удалить архивы</p>
                                @if (! empty($pgBackupPreviewLocal['deleteArchives']))
                                    <ul style="margin-top:.25rem; padding-left:1rem;">
                                        @foreach ($pgBackupPreviewLocal['deleteArchives'] as $f)
                                            <li style="font-size:.6875rem; color:#6b7280;">• {{ $f }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p style="margin-top:.25rem; font-size:.6875rem; color:#9ca3af;">Нет</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                    </x-filament::section>
                </div>
            </div>

            <div class="ops-tabpanel" x-show="tab === 'commands'">
                <x-filament::section
                    heading="Полезные команды"
                    description="Шпаргалка для сервера. Выполнять в терминале, не в браузере."
                >
                    <div class="ops-command-stack">
                        <div class="ops-command-group">
                            <div class="ops-command-title">
                                Локации проекта
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># staging
cd /var/www/market-staging/current

# prod
cd /var/www/market/current</code></pre>
                            </div>
                        </div>

                        <div class="ops-command-group">
                            <div class="ops-command-title">
                                Проверить версию (коммит) на окружении
                            </div>
                            <div class="ops-codeblock">
                                <pre><code>sudo -u www-data git -C /var/www/market-staging/current log -1 --oneline
sudo -u www-data git -C /var/www/market/current log -1 --oneline</code></pre>
                            </div>
                        </div>

                        <div class="ops-command-group">
                            <div class="ops-command-title">
                                Обновить окружение до последнего main (без merge)
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># staging
sudo -u www-data git -C /var/www/market-staging/current fetch origin
sudo -u www-data git -C /var/www/market-staging/current pull --ff-only origin main

# prod (делать только при контролируемом релизе)
sudo -u www-data git -C /var/www/market/current fetch origin
sudo -u www-data git -C /var/www/market/current pull --ff-only origin main</code></pre>
                            </div>
                        </div>

                        <div class="ops-command-group">
                            <div class="ops-command-title">
                                Логи/блокировки деплоя staging
                            </div>
                            <div class="ops-codeblock">
                                <pre><code># лог вебхука
tail -n 200 /var/www/market-staging/current/storage/logs/deploy-market-staging.log

# lock (если деплой "завис", сначала проверь лог)
ls -la /var/www/market-staging/current/storage/framework/deploy-market-staging.lock</code></pre>
                            </div>
                        </div>

                        <div class="ops-command-group">
                            <div class="ops-command-title">
                                Очистка кешей Laravel (ручной вариант)
                            </div>
                            <div class="ops-codeblock">
                                <pre><code>cd /var/www/market-staging/current
php artisan optimize:clear</code></pre>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    </div>
    @endif
</x-filament-panels::page>
