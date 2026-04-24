<!doctype html>
<html lang="ru">
<head>
  @php
    /** @var bool $hasMap */
    $marketName = $marketName ?? (isset($market) ? (string) ($market->name ?? 'Рынок') : 'Рынок');
    $marketId   = (int) ($marketId ?? (isset($market) ? (int) ($market->id ?? 0) : 0));

    $hasMap   = isset($hasMap) ? (bool) $hasMap : true;
    $canEdit  = isset($canEdit) ? (bool) $canEdit : false;
    $marketSpaceNotLinked = isset($marketSpaceNotLinked) ? (bool) $marketSpaceNotLinked : false;
    $canOpenPdf = isset($canOpenPdf) ? (bool) $canOpenPdf : false;

    $settingsUrl = $settingsUrl ?? url('/admin/market-settings');
    $returnUrl = is_string($returnUrl ?? null) && trim((string) $returnUrl) !== ''
        ? (string) $returnUrl
        : url('/admin');

    // URL’ы API/viewer’а (могут отсутствовать при $hasMap=false — это ок)
    $pdfUrl    = $pdfUrl ?? '';
    $hitUrl    = $hitUrl ?? '';
    $shapesUrl = $shapesUrl ?? '';
    $spaceUrl  = $spaceUrl ?? '';
    $spacesUrl = $spacesUrl ?? '';
  @endphp

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Карта рынка — {{ $marketName }}</title>

  <style>
    :root { color-scheme: light; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
      color: #0f172a;
      background: #f8fafc;
    }
    .wrap {
      padding: 16px;
      max-width: 1400px;
      margin: 0 auto;
      min-height: 100vh;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
    }
    .map-layout {
      margin-top: 14px;
      flex: 1 1 auto;
      min-height: 0;
      height: calc(100vh - 46px);
      max-height: calc(100vh - 46px);
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .btnrow { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

    button {
      border: 1px solid rgba(120,120,120,.35);
      background: rgba(120,120,120,.10);
      padding: 5px 8px;
      border-radius: 9px;
      cursor: pointer;
      font-size: 12px;
      color: #0f172a;
      -webkit-text-fill-color: #0f172a;
    }
    button:hover { background: rgba(120,120,120,.18); }
    button:disabled {
      opacity:.5;
      cursor:not-allowed;
      color: #64748b;
      -webkit-text-fill-color: #64748b;
    }
    .button-accent {
      background: #f59e0b;
      border-color: #d97706;
      color: #fff;
      -webkit-text-fill-color: #fff;
    }
    .button-accent:hover {
      background: #ea580c;
    }
    .button-toggle.is-active {
      background: #1d4ed8;
      border-color: #1d4ed8;
      color: #fff;
      -webkit-text-fill-color: #fff;
      box-shadow: 0 6px 14px rgba(37, 99, 235, 0.22);
    }
    .button-toggle.is-active:hover {
      background: #1e40af;
    }
    .review-progress {
      display: none;
      align-items: center;
      gap: 4px;
      min-width: 164px;
    }
    .review-progress__track {
      position: relative;
      width: 114px;
      height: 4px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(15, 23, 42, 0.12);
    }
    .review-progress__fill {
      position: absolute;
      inset: 0 auto 0 0;
      width: 0;
      background: linear-gradient(90deg, #0f766e 0%, #14b8a6 100%);
      border-radius: 999px;
    }
    .review-progress__text {
      font-size: 8px;
      font-weight: 600;
      white-space: nowrap;
    }
    .review-summary {
      display: none;
      gap: 3px;
      align-items: center;
      flex-wrap: wrap;
    }
    .map-load-progress {
      display: none;
      flex-direction: column;
      gap: 8px;
      width: calc(100% - 36px);
      box-sizing: border-box;
      margin: 12px 18px 16px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(15, 23, 42, 0.08);
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
      transition: opacity .2s ease, transform .2s ease;
    }
    .map-load-progress__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .map-load-progress__track {
      position: relative;
      width: 100%;
      height: 10px;
      border-radius: 999px;
      overflow: hidden;
      background: rgba(15, 23, 42, 0.12);
    }
    .map-load-progress__fill {
      position: absolute;
      inset: 0 auto 0 0;
      width: 0;
      background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
      border-radius: 999px;
    }
    @keyframes mapLoadShimmer {
      0% { background-position: 0% 50%; }
      100% { background-position: 200% 50%; }
    }
    .map-load-progress[data-state="done"] .map-load-progress__fill {
      background: linear-gradient(90deg, #15803d 0%, #22c55e 100%);
    }
    .map-load-progress[data-state="fallback"] .map-load-progress__fill {
      background: linear-gradient(90deg, #9ca3af 0%, #cbd5e1 100%);
    }
    .map-load-progress[data-phase="finalizing"] .map-load-progress__fill {
      width: 100%;
      background: linear-gradient(90deg, #2563eb 0%, #60a5fa 25%, #38bdf8 50%, #60a5fa 75%, #2563eb 100%);
      background-size: 200% 100%;
      animation: mapLoadShimmer 1.1s linear infinite;
    }
    .map-load-progress__text {
      font-size: 12px;
      font-weight: 600;
    }
    .map-load-progress__percent {
      font-size: 12px;
      font-weight: 700;
      color: rgba(15, 23, 42, 0.72);
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }

    .pill {
      font-size: 10.5px;
      opacity: .85;
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid rgba(120,120,120,.25);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      white-space: nowrap;
    }

    .spacePicker {
      position: relative;
      width: 100%;
      min-width: 240px;
    }

    .spaceSearchInput {
      width: 100%;
      height: 40px;
      min-height: 40px;
      padding: 0 16px 0 42px;
      border-radius: 14px;
      border: 1px solid var(--map-control-border);
      background:
        #ffffff
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E")
        no-repeat 14px center / 16px 16px;
      box-shadow: var(--map-control-shadow);
      color: #334155;
      -webkit-text-fill-color: #334155;
    }

    .spaceDropdown {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      z-index: 120;
      border: 1px solid rgba(120,120,120,.25);
      border-radius: 10px;
      background: rgba(30,30,30,.98);
      color: #fff;
      padding: 4px;
      display: none;
      max-height: 320px;
      overflow-y: auto;
      box-shadow: 0 10px 26px rgba(0,0,0,.28);
    }

    .spaceDropdownItem {
      padding: 6px 8px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 12px;
      display: flex;
      gap: 6px;
      align-items: center;
    }

    .spaceDropdownItem:hover,
    .spaceDropdownItem.active {
      background: rgba(255,255,255,.12);
    }

    .spaceDropdownEmpty {
      padding: 6px 8px;
      font-size: 12px;
      opacity: .75;
    }

    .spacePillButton {
      border: 0;
      background: transparent;
      color: inherit;
      font-size: 12px;
      cursor: pointer;
      padding: 0;
      line-height: 1;
    }

    .viewer {
      border: 1px solid rgba(148, 163, 184, 0.28);
      border-radius: 14px;
      overflow: hidden;
      background: transparent;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
    }
    .toolbar {
      padding: 9px 14px 7px;
      display: grid;
      gap: 5px;
      position: relative;
      border-bottom: 1px solid rgba(147, 197, 253, 0.18);
      background:
        radial-gradient(circle at top right, rgba(186, 230, 253, 0.28) 0%, rgba(186, 230, 253, 0) 34%),
        linear-gradient(180deg, rgba(239, 246, 255, 0.68) 0%, rgba(248, 250, 252, 0.76) 100%);
      -webkit-backdrop-filter: blur(10px) saturate(115%);
      backdrop-filter: blur(10px) saturate(115%);
    }
    .toolbar-row {
      display: flex;
      gap: 6px;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
    }
    .toolbar-row.toolbar-row--top {
      display: flex;
      justify-content: flex-end;
      padding: 4px 0 8px 0;
    }
    .toolbar-row.toolbar-row--hero {
      display: flex !important;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      overflow-x: auto !important;
    }
    .toolbar-group--hero-left {
      display: flex !important;
      flex-direction: column;
      align-items: flex-start !important;
      justify-content: flex-start !important;
      gap: 8px;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      flex: 0 0 auto;
      min-width: 0;
      margin-left: 0 !important;
      padding-left: 0 !important;
    }
    .toolbar-group--hero-top {
      display: flex !important;
      align-items: center;
      gap: 10px;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      margin-left: 0 !important;
    }
    .toolbar-group--hero-top > .toolbar-group {
      flex-shrink: 0 !important;
      flex-wrap: nowrap !important;
      display: flex !important;
      align-items: center;
    }
    .toolbar-group--hero-bottom {
      display: flex !important;
      align-items: center;
      justify-content: flex-start !important;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      margin-left: 0 !important;
      padding-left: 0 !important;
    }
    .toolbar-group--hero-bottom .spacePicker {
      display: flex !important;
      align-items: center;
      position: relative;
      width: fit-content !important;
      min-width: 240px;
      flex: 0 0 auto;
      background: rgba(255,255,255,0.8);
      border: 1px solid rgba(147, 197, 253, 0.38);
      border-radius: 11px;
      padding: 0 10px;
      height: 34px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.72), 0 4px 10px rgba(147, 197, 253, 0.12);
      margin-left: 0 !important;
    }
    .toolbar-group--hero-bottom .spacePickerIcon {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(15, 23, 42, 0.5);
      pointer-events: none;
    }
    .toolbar-group--hero-bottom .spaceSearchInput {
      width: 240px !important;
      padding: 6px 12px 6px 30px !important;
      border: none !important;
      background: transparent !important;
      box-shadow: none !important;
      height: 34px !important;
      min-height: 34px !important;
      border-radius: 11px !important;
      color: inherit !important;
      font-size: 13px !important;
      line-height: 20px !important;
      display: block !important;
      white-space: nowrap !important;
      outline: none;
    }
    .toolbar-group--hero-left .toolbar-group--accent {
      flex-wrap: nowrap !important;
    }
    .toolbar-group--hero-left .toolbar-group--accent button,
    .toolbar-group--hero-left .toolbar-group--accent .pill {
      white-space: nowrap !important;
    }
    .toolbar-group--hero-center {
      display: flex;
      align-items: center;
      flex: 1 1 auto;
      justify-content: center;
      min-width: 0;
    }
    .toolbar-group.toolbar-group--hero-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: nowrap !important;
      white-space: nowrap !important;
      flex: 0 0 auto;
      justify-content: flex-end;
    }
    .toolbar-row.toolbar-row--controls {
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .toolbar-row.toolbar-row--edit-hint {
      display: none;
      position: absolute;
      left: 14px;
      top: 86px;
      z-index: 6;
      pointer-events: none;
    }
    .toolbar-row.toolbar-row--review-status {
      display: none;
      align-items: center;
      justify-content: center;
      padding: 4px 8px;
      border-radius: 10px;
      border: 1px solid rgba(147, 197, 253, 0.44);
      background: linear-gradient(180deg, rgba(219, 234, 254, 0.86) 0%, rgba(239, 246, 255, 0.92) 100%);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.78);
    }
    .toolbar-group {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
      padding: 0;
      min-width: 0;
    }
    :root {
      --map-control-height: 46px;
      --map-control-inner-height: 34px;
      --map-control-radius: 16px;
      --map-control-inner-radius: 11px;
      --map-control-border: rgba(147, 197, 253, 0.38);
      --map-control-bg: linear-gradient(180deg, rgba(255,255,255,.92) 0%, rgba(239,246,255,.98) 100%);
      --map-control-shadow: inset 0 1px 0 rgba(255,255,255,.72), 0 8px 18px rgba(147, 197, 253, 0.12);
    }
    .toolbar-group.toolbar-group--accent {
      min-height: 36px;
      padding: 3px;
      border-radius: 12px;
      background: var(--map-control-bg);
      border: 1px solid var(--map-control-border);
      box-shadow: var(--map-control-shadow);
    }
    .toolbar-group.toolbar-group--segmented {
      gap: 4px;
      min-height: 40px;
      padding: 4px;
      border-radius: 13px;
      background: var(--map-control-bg);
      border: 1px solid var(--map-control-border);
      box-shadow: var(--map-control-shadow);
    }
    .toolbar-group.toolbar-group--segmented .toolbar-label {
      min-height: 30px;
      padding: 0 9px 0 6px;
      color: #475569;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
    }
    .toolbar-group.toolbar-group--segmented .button-toggle {
      min-height: 30px;
      padding: 4px 14px;
      border-radius: 10px;
      border-color: transparent;
      background: rgba(255,255,255,.5);
      color: #1e3a8a;
      -webkit-text-fill-color: #1e3a8a;
      font-weight: 600;
      transition: background .18s ease, color .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .toolbar-group.toolbar-group--segmented .button-toggle:hover {
      background: rgba(255,255,255,.82);
      border-color: rgba(147, 197, 253, 0.34);
    }
    .toolbar-group.toolbar-group--segmented .button-toggle.is-active {
      background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
      border-color: #1d4ed8;
      color: #fff;
      -webkit-text-fill-color: #fff;
      box-shadow: 0 8px 16px rgba(37, 99, 235, 0.26);
    }
    .toolbar-group.toolbar-group--segmented .button-toggle.is-active:hover {
      background: linear-gradient(180deg, #1d4ed8 0%, #1e40af 100%);
    }
    .toolbar-group.toolbar-group--segmented .button-toggle.button-toggle--close {
      min-width: 30px;
      padding: 0 10px;
      background: linear-gradient(180deg, #f59e0b 0%, #f59e0b 100%);
      border-color: #d97706;
      color: #fff;
      -webkit-text-fill-color: #fff;
      box-shadow: 0 6px 14px rgba(245, 158, 11, 0.24);
      font-size: 24px;
      line-height: 1;
      justify-content: center;
    }
    .toolbar-group.toolbar-group--segmented .button-toggle.button-toggle--close:hover {
      background: linear-gradient(180deg, #f59e0b 0%, #ea580c 100%);
      border-color: #c2410c;
    }
    .toolbar-group.toolbar-group--control-segmented {
      gap: 4px;
      min-height: 36px;
      padding: 3px;
      border-radius: 12px;
      background: var(--map-control-bg);
      border: 1px solid var(--map-control-border);
      box-shadow: var(--map-control-shadow);
    }
    .toolbar-group.toolbar-group--control-segmented button,
    .toolbar-group.toolbar-group--control-segmented .pill {
      min-height: 26px;
      padding: 2px 11px;
      border-radius: 8px;
      border: 1px solid transparent;
      background: rgba(255,255,255,.58);
      color: #1e3a8a;
      -webkit-text-fill-color: #1e3a8a;
      font-size: 11px;
      font-weight: 600;
      text-decoration: none;
      box-shadow: none;
      transition: background .18s ease, border-color .18s ease, color .18s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      white-space: nowrap;
    }
    .toolbar-group.toolbar-group--control-segmented button:hover,
    .toolbar-group.toolbar-group--control-segmented .pill:hover {
      background: rgba(255,255,255,.88);
      border-color: rgba(147, 197, 253, 0.34);
    }
    .toolbar-group.toolbar-group--control-segmented button:disabled {
      background: rgba(255,255,255,.38);
      color: #94a3b8;
      -webkit-text-fill-color: #94a3b8;
      border-color: transparent;
      opacity: .72;
    }
    .toolbar-group.toolbar-group--control-segmented button.is-active {
      background: linear-gradient(180deg, #dbeafe 0%, #bfdbfe 100%);
      border-color: rgba(96, 165, 250, 0.55);
      color: #1d4ed8;
      -webkit-text-fill-color: #1d4ed8;
      box-shadow: 0 2px 6px rgba(147, 197, 253, 0.18);
    }
    .toolbar-group.toolbar-group--control-segmented button.is-active:hover {
      background: linear-gradient(180deg, #bfdbfe 0%, #93c5fd 100%);
    }
    .toolbar-group.toolbar-group--accent > button,
    .toolbar-group.toolbar-group--accent > .pill {
      min-height: 26px;
      padding: 2px 11px;
      border-radius: 8px;
      border: 1px solid transparent;
      background: rgba(255,255,255,.58);
      color: #1e3a8a;
      -webkit-text-fill-color: #1e3a8a;
      font-size: 11px;
      font-weight: 600;
      text-decoration: none;
      box-shadow: none;
      transition: background .18s ease, border-color .18s ease, color .18s ease;
    }
    .toolbar-group.toolbar-group--accent > button:hover,
    .toolbar-group.toolbar-group--accent > .pill:hover {
      background: rgba(255,255,255,.88);
      border-color: rgba(147, 197, 253, 0.34);
    }
    .toolbar-label {
      display: inline-flex;
      align-items: center;
      padding: 0 4px 0 0;
      color: rgba(15, 23, 42, 0.72);
      font-size: 11px;
      font-weight: 600;
      white-space: nowrap;
    }
    .toolbar-group.toolbar-group--hero-actions-main {
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }
    .toolbar-group.toolbar-group--hero-stack {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
      width: auto;
    }
    .toolbar-group.toolbar-group--hero-center {
      justify-self: center;
      justify-content: center;
      min-width: 0;
    }
    .toolbar-group.toolbar-group--hero-review {
      flex-direction: column;
      align-items: center;
      gap: 8px;
      width: 100%;
      min-width: 0;
    }
    .toolbar-group.toolbar-group--controls-left {
      flex: 0 1 auto;
      justify-content: flex-start;
      gap: 10px;
      flex-wrap: nowrap;
    }
    .toolbar-group.toolbar-group--stretch {
      flex: 1 1 auto;
      min-width: 0;
    }
    .toolbar-group.toolbar-group--review-nav {
      flex: 0 1 auto;
      justify-content: center;
      width: fit-content;
      max-width: 100%;
      padding: 5px;
      border-radius: var(--map-control-radius);
      border: 1px solid var(--map-control-border);
      background: var(--map-control-bg);
      box-shadow: var(--map-control-shadow);
      gap: 6px;
    }
    .toolbar-group.toolbar-group--review-nav button {
      min-height: var(--map-control-inner-height);
      padding: 5px 14px;
      border-radius: var(--map-control-inner-radius);
      border: 1px solid transparent;
      background: rgba(255,255,255,.58);
      color: #1e3a8a;
      -webkit-text-fill-color: #1e3a8a;
      font-size: 12px;
      font-weight: 600;
      box-shadow: none;
      transition: background .18s ease, border-color .18s ease, color .18s ease;
    }
    .toolbar-group.toolbar-group--review-nav button:hover {
      background: rgba(255,255,255,.88);
      border-color: rgba(147, 197, 253, 0.34);
    }
    .toolbar-group.toolbar-group--review-nav button:disabled {
      background: rgba(255,255,255,.38);
      color: #94a3b8;
      -webkit-text-fill-color: #94a3b8;
      border-color: transparent;
      opacity: .72;
    }
    .toolbar-group.toolbar-group--search-slot {
      flex: 0 1 auto;
      width: 340px;
      margin-left: auto;
      justify-content: flex-end;
    }
    .toolbar-group.toolbar-group--review-status-group {
      width: 100%;
      gap: 4px;
      justify-content: flex-start;
      flex-wrap: nowrap;
    }
    .toolbar-group.toolbar-group--utility {
      margin-left: auto;
      opacity: .92;
      gap: 5px;
    }
    .toolbar-group.toolbar-group--utility .pill {
      min-height: 36px;
      padding: 0 11px;
      border-radius: 12px;
      border: 1px solid var(--map-control-border);
      background: var(--map-control-bg);
      box-shadow: var(--map-control-shadow);
      display: inline-flex;
      align-items: center;
      font-size: 11px;
    }
    #editHint {
      min-height: 30px;
      padding: 6px 12px;
      border-radius: 10px;
      border: 1px solid rgba(147, 197, 253, 0.34);
      background: rgba(255,255,255,.94);
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
      color: #475569;
      font-size: 11px;
      line-height: 1.35;
      white-space: nowrap;
    }
    #reviewNavStatus {
      display: inline-flex;
      align-items: center;
      padding: 4px 8px;
      border-radius: 8px;
      border: 1px solid rgba(147, 197, 253, 0.34);
      background: rgba(255,255,255,.76);
      color: #3b82f6;
      font-size: 9px;
      font-weight: 600;
      white-space: nowrap;
    }
    .button-accent.button-accent--icon {
      min-width: var(--map-control-height);
      min-height: var(--map-control-height);
      padding: 0;
      border-radius: var(--map-control-radius);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      line-height: 1;
    }
    #spaceChosenPill.hero-chosen {
      display: none;
      align-items: center;
      gap: 12px;
      width: fit-content;
      min-width: 0;
      max-width: min(500px, 100%);
      padding: 9px 14px;
      border-radius: 16px;
      border: 1px solid rgba(147, 197, 253, 0.40);
      background: linear-gradient(180deg, rgba(255,255,255,.96) 0%, rgba(239, 246, 255, 0.98) 100%);
      box-shadow: 0 10px 22px rgba(147, 197, 253, 0.12);
      color: #0f172a;
      -webkit-text-fill-color: #0f172a;
    }
    #spaceChosenPill .hero-chosen__label {
      color: #64748b;
      font-weight: 500;
      font-size: 13px;
      white-space: nowrap;
    }
    #spaceChosenPill .hero-chosen__body {
      display: grid;
      gap: 1px;
      min-width: 0;
      align-items: start;
    }
    #spaceChosenPill .hero-chosen__line,
    #spaceChosenPill .hero-chosen__meta {
      display: grid;
      grid-template-columns: max-content minmax(0, 1fr);
      column-gap: 6px;
      align-items: baseline;
      line-height: 1.08;
      min-width: 0;
    }
    #spaceChosenPill .hero-chosen__value,
    #spaceChosenPill .hero-chosen__tenant {
      font-weight: 800;
      font-size: 14px;
      color: #0f172a;
      -webkit-text-fill-color: #0f172a;
      justify-self: start;
      text-align: left;
    }
    #spaceChosenPill .hero-chosen__prefix,
    #spaceChosenPill .hero-chosen__meta-label {
      min-width: 78px;
      font-weight: 500;
      font-size: 13px;
      color: #475569;
      -webkit-text-fill-color: #475569;
      justify-self: start;
      text-align: left;
    }
    #spaceChosenPill .hero-chosen__empty {
      color: #64748b;
      font-weight: 600;
      font-size: 14px;
    }
    #spaceChosenPill .spacePillButton {
      margin-left: auto;
      color: #1e3a8a;
      font-size: 16px;
      -webkit-text-fill-color: #1e3a8a;
    }
    #reviewSummary .pill {
      min-height: 18px;
      padding: 2px 7px;
      border-radius: 7px;
      font-size: 9px;
      background: rgba(255,255,255,.58);
      border-color: rgba(147, 197, 253, 0.28);
      color: #475569;
      -webkit-text-fill-color: #475569;
    }
    /* === Tablet hero: 3-column grid (no wrap, no column stacking) === */
    @media (max-width: 1120px) {
      .toolbar-row.toolbar-row--hero {
        display: grid !important;
        grid-template-columns: auto 1fr auto !important;
        align-items: center !important;
        gap: 10px !important;
      }
      /* Left: zoom over search, compact */
      .toolbar-group--hero-left {
        flex-direction: column !important;
        align-items: flex-start !important;
        flex-wrap: nowrap !important;
        justify-content: flex-start !important;
        grid-column: 1 !important;
      }
      /* Center: chosen place, takes remaining space */
      .toolbar-group--hero-center {
        grid-column: 2 !important;
        min-width: 0 !important;
        justify-content: center !important;
      }
      /* Right: mode / layers, compact */
      .toolbar-group.toolbar-group--hero-actions {
        width: 100% !important;
        justify-content: flex-end !important;
        justify-self: end !important;
        grid-column: 3 !important;
      }
      .toolbar-group.toolbar-group--hero-actions-main {
        width: 100% !important;
        justify-content: flex-end !important;
      }
      .toolbar-group.toolbar-group--hero-stack {
        width: 100% !important;
        align-items: flex-end !important;
      }
      /* Center block: compact vertical layout */
      .toolbar-group.toolbar-group--hero-center {
        justify-content: center !important;
      }
      .toolbar-group.toolbar-group--hero-review {
        align-items: center !important;
        gap: 6px !important;
      }
      /* Override any inline display set by JS */
      #spaceChosenPill.hero-chosen {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 3px !important;
        padding: 5px 8px !important;
        max-width: min(280px, 100%) !important;
        min-width: 0 !important;
        width: auto !important;
      }
      #spaceChosenPill .hero-chosen__label {
        font-size: 10px !important;
        text-align: center !important;
        white-space: nowrap !important;
        order: -1 !important;
        margin-bottom: 1px !important;
      }
      #spaceChosenPill .hero-chosen__body {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        gap: 1px !important;
        width: 100% !important;
      }
      #spaceChosenPill .hero-chosen__line,
      #spaceChosenPill .hero-chosen__meta {
        display: flex !important;
        flex-direction: row !important;
        gap: 4px !important;
        align-items: baseline !important;
        justify-content: center !important;
        line-height: 1.15 !important;
        text-align: center !important;
        width: 100% !important;
      }
      #spaceChosenPill .hero-chosen__prefix,
      #spaceChosenPill .hero-chosen__meta-label {
        display: none !important;
      }
      #spaceChosenPill .hero-chosen__value,
      #spaceChosenPill .hero-chosen__tenant {
        font-size: 12px !important;
        text-align: center !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        max-width: 200px !important;
      }
      /* Move close button to top-right corner */
      #spaceChosenPill .spacePillButton {
        position: absolute !important;
        top: 2px !important;
        right: 4px !important;
        margin: 0 !important;
        font-size: 14px !important;
        line-height: 1 !important;
      }
      #spaceChosenPill {
        position: relative !important;
      }
      /* Tablet review nav: single row ← [Pending] → */
      .toolbar-group.toolbar-group--review-nav {
        /* JS toggles display:none/inline-flex. We style inline-flex. */
        justify-content: space-between !important;
        align-items: center !important;
        gap: 4px !important;
        padding: 3px !important;
        width: 100% !important;
      }
      .toolbar-group.toolbar-group--review-nav button {
        padding: 4px 8px !important;
        font-size: 11px !important;
        white-space: nowrap !important;
      }
      /* Side buttons: arrows only */
      #reviewNavPrev, #reviewNavNext {
        width: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
        padding: 0 !important;
        overflow: hidden !important;
        text-overflow: clip !important;
        font-size: 0 !important;
        line-height: 0 !important;
        color: transparent !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
      }
      #reviewNavPrev::before,
      #reviewNavNext::before {
        font-size: 16px;
        line-height: 1;
        color: #0f172a;
        -webkit-text-fill-color: #0f172a;
      }
      #reviewNavPrev::before { content: "←"; }
      #reviewNavNext::before { content: "→"; }
      /* Center button: main action */
      #reviewNavNextPending {
        flex: 1 !important;
        padding: 4px 10px !important;
        font-weight: 700 !important;
      }
      /* Controls row below hero — keep wrapping */
      .toolbar-group.toolbar-group--controls-left {
        flex-wrap: wrap;
      }
      .toolbar-group.toolbar-group--review-status-group {
        flex-wrap: wrap;
      }
    }
    .legend[hidden] {
      display: none;
    }
    .legend-stack {
      display: grid;
      gap: 0;
      flex: 0 0 auto;
    }
    .legend-note {
      color: rgba(15, 23, 42, 0.72);
      font-size: 10px;
    }
    
    /* Легенда карты */
    .legend {
      padding: 8px 10px;
      border-bottom: 1px solid rgba(120,120,120,.12);
      background: rgba(255,255,255,.17);
      -webkit-backdrop-filter: blur(8px) saturate(120%);
      backdrop-filter: blur(8px) saturate(120%);
      font-size: 11px;
    }
    .legend-items {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .legend-item.legend-item--note { display: none; }
    .legend-color {
      width: 16px;
      height: 16px;
      border-radius: 4px;
      border: 1px solid rgba(120,120,120,.3);
      flex-shrink: 0;
    }
    .legend-color.legend-vacant {
      background: #e5e7eb;
      border: 1px solid #94a3b8;
    }
    .legend-color.legend-unlinked {
      background:
        repeating-linear-gradient(
          45deg,
          rgba(148, 163, 184, 0.85) 0 2px,
          transparent 2px 6px
        );
      border: 1px solid #94a3b8;
    }
    .legend-color.legend-conflict {
      background:
        repeating-linear-gradient(
          0deg,
          rgba(220, 38, 38, 0.42) 0 2px,
          transparent 2px 7px
        ),
        repeating-linear-gradient(
          90deg,
          rgba(31, 41, 55, 0.16) 0 1px,
          transparent 1px 7px
        );
      border: 1px solid #b45309;
      box-shadow: inset 0 0 0 1px rgba(31, 41, 55, 0.06);
    }
    .legend-color.legend-fallback {
      background: #7dd3fc;
      border: 1px solid #0284c7;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.28),
        0 0 0 1px rgba(2, 132, 199, 0.10);
    }
    .legend-color.legend-combined-review {
      background:
        repeating-linear-gradient(
          0deg,
          rgba(220, 38, 38, 0.42) 0 2px,
          transparent 2px 7px
        ),
        repeating-linear-gradient(
          90deg,
          rgba(31, 41, 55, 0.16) 0 1px,
          transparent 1px 7px
        ),
        #7dd3fc;
      border: 2px solid #0284c7;
      box-shadow:
        inset 0 0 0 1px rgba(180, 83, 9, 0.85),
        inset 0 0 0 2px rgba(255,255,255,.18);
    }
    .legend-color.legend-rate-none {
      background: #cbd5e1;
      border: 1px solid #94a3b8;
    }
    .legend-label {
      white-space: nowrap;
    }
    @media (max-width: 900px) {
      .toolbar {
        padding: 12px 12px 10px;
      }
      .toolbar-group.toolbar-group--utility {
        margin-left: 0;
      }
    }
    .stage {
      height: auto;
      min-height: 0;
      flex: 1 1 auto;
      overflow: auto;
      background: rgba(120,120,120,.04);
    }
    .stage.grabbing { cursor: grabbing; }

    #viewerRoot {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
    }

    .canvasWrap {
      position: relative;
      width: max-content;
      margin: 0 auto;
    }

    canvas { display:block; background: #fff; }

    .shapesSvg{
      position:absolute;
      inset:0;
      pointer-events:none;
      z-index: 40;
      display: block;
      overflow: visible;
    }

    .overlay {
      position: absolute;
      inset: 0;
      cursor: crosshair;
      background: transparent;
      user-select: none;
      -webkit-user-select: none;
      z-index: 60;
    }
    .overlay.grabbing { cursor: grabbing; }

    .handlesLayer{
      position:absolute;
      inset:0;
      z-index: 80;
      pointer-events: none;
    }

    .handleDot{
      position:absolute;
      width: 12px;
      height: 12px;
      margin-left: -6px;
      margin-top: -6px;
      border-radius: 999px;
      border: 2px solid rgba(255,255,255,.92);
      background: rgba(0,163,255,.92);
      box-shadow: 0 6px 18px rgba(0,0,0,.22);
      cursor: grab;
      pointer-events: auto;
    }
    .handleDot:active{ cursor: grabbing; }

    .handleDot.active{
      background: rgba(255,170,0,.92);
    }

    .drawBox{
      position:absolute;
      border:2px dashed rgba(0,163,255,.95);
      background: rgba(0,163,255,.12);
      pointer-events:none;
      display:none;
      z-index: 50;
    }

    .iframe {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 0;
      background: #fff;
      display: block;
      flex: 1 1 auto;
    }

    .popover {
      position: fixed;
      z-index: 10000;
      min-width: 220px;
      max-width: min(360px, calc(100vw - 24px));
      max-height: calc(100vh - 24px);
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(20,20,20,.92);
      color: #fff;
      font-size: 12px;
      line-height: 1.35;
      box-shadow: 0 12px 34px rgba(0,0,0,.28);
      display: none;
      overflow: hidden;
    }
    .popover.show { display: block; }
    .popover__content {
      max-height: calc(100vh - 72px);
      overflow-y: auto;
      padding-right: 2px;
    }
    .popover .t { font-weight: 700; font-size: 12px; padding-right: 28px; }
    .popover .row { margin-top: 6px; opacity: .92; }
    .popover .row-label {
      color: rgba(255,255,255,.70);
    }
    .popover .row-value {
      color: #fff;
      font-weight: 600;
    }
    .popover .muted { opacity: .72; }
    .popover .row-meta {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid rgba(255,255,255,.10);
    }
    .popover .row-warning {
      margin-top: 10px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid rgba(56, 189, 248, 0.48);
      background: rgba(14, 165, 233, 0.16);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.04);
    }
    .popover .row-warning .row-value {
      color: #e0f2fe;
      font-weight: 700;
    }
    .popover .row-review-note {
      margin-top: 10px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid rgba(251, 191, 36, 0.48);
      background: rgba(251, 191, 36, 0.14);
      box-shadow: inset 0 0 0 1px rgba(255,255,255,.04);
    }
    .popover .row-review-note .row-value {
      color: #fef3c7;
      font-weight: 700;
    }

    .popover .xbtn {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 22px;
      height: 22px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.20);
      background: rgba(255,255,255,.06);
      color: #fff;
      -webkit-text-fill-color: #fff;
      cursor: pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size: 12px;
    }
    .popover .xbtn:hover { background: rgba(255,255,255,.12); }

    .popover .act{
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px solid rgba(255,255,255,.10);
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .popover .act button{
      border: 1px solid rgba(255,255,255,.20);
      background: rgba(255,255,255,.08);
      color:#fff;
      -webkit-text-fill-color: #fff;
      padding: 7px 10px;
      border-radius: 10px;
      cursor:pointer;
      font-size: 12px;
    }
    .popover .act button:hover{ background: rgba(255,255,255,.14); }
    .popover .act button:disabled{ opacity:.5; cursor:not-allowed; }
    .popover .act button[data-action="open-space"],
    .popover .act button[data-action="open-tenant"]{
      border-color: rgba(147, 197, 253, .42);
      background: linear-gradient(180deg, rgba(219, 234, 254, .24) 0%, rgba(191, 219, 254, .18) 100%);
      color:#dbeafe;
      -webkit-text-fill-color: #dbeafe;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    .popover .act button[data-action="open-space"]:hover,
    .popover .act button[data-action="open-tenant"]:hover{
      background: linear-gradient(180deg, rgba(219, 234, 254, .34) 0%, rgba(191, 219, 254, .26) 100%);
      border-color: rgba(191, 219, 254, .56);
    }

    .identity-fix-modal {
      position: fixed;
      inset: 0;
      z-index: 10020;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .identity-fix-modal.show {
      display: flex;
    }
    .identity-fix-modal__backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, 0.46);
      -webkit-backdrop-filter: blur(6px);
      backdrop-filter: blur(6px);
    }
    .identity-fix-modal__dialog {
      position: relative;
      width: min(540px, 100%);
      border-radius: 18px;
      border: 1px solid rgba(148, 163, 184, 0.28);
      background: rgba(255, 255, 255, 0.98);
      box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .identity-fix-modal__eyebrow {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #64748b;
    }
    .identity-fix-modal__title {
      margin: 0;
      font-size: 18px;
      line-height: 1.25;
      color: #0f172a;
    }
    .identity-fix-modal__description {
      margin: 0;
      font-size: 13px;
      line-height: 1.45;
      color: #475569;
    }
    .identity-fix-modal__label {
      font-size: 12px;
      font-weight: 600;
      color: #334155;
    }
    .identity-fix-modal__input {
      width: 100%;
      box-sizing: border-box;
      height: 40px;
      border: 1px solid rgba(148, 163, 184, 0.55);
      border-radius: 12px;
      padding: 0 12px;
      background: #fff;
      color: #0f172a;
      font-size: 14px;
      outline: none;
      box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .identity-fix-modal__input:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
    }
    .identity-fix-modal__actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }
    .identity-fix-modal__actions button {
      min-width: 104px;
    }
    .identity-fix-modal__cancel {
      background: rgba(148, 163, 184, 0.12);
      border-color: rgba(148, 163, 184, 0.35);
    }
    .identity-fix-modal__save {
      background: #1d4ed8;
      border-color: #1d4ed8;
      color: #fff;
      -webkit-text-fill-color: #fff;
    }
    .identity-fix-modal__save:hover {
      background: #1e40af;
    }
    .identity-fix-modal__close {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 28px;
      height: 28px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: rgba(148, 163, 184, 0.12);
      color: #334155;
    }

    .toast {
      position: fixed;
      right: 14px;
      bottom: 14px;
      z-index: 9999;
      max-width: min(560px, calc(100vw - 28px));
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(20,20,20,.86);
      color: #fff;
      font-size: 12px;
      line-height: 1.35;
      box-shadow: 0 10px 30px rgba(0,0,0,.25);
      opacity: 0;
      transform: translateY(6px);
      transition: opacity .12s ease, transform .12s ease;
      pointer-events: none;
    }
    .toast.show { opacity: 1; transform: translateY(0); }

    .empty {
      padding: 14px;
      border-radius: 14px;
      border: 1px solid rgba(120,120,120,.25);
      background: rgba(120,120,120,.06);
      margin-top: 14px;
      font-size: 14px;
    }

    input[type="number"]{
      appearance: textfield;
      -moz-appearance: textfield;
    }
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button{
      -webkit-appearance: none;
      margin: 0;
    }
  </style>
</head>

<body>
  <div class="wrap">

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('closeBtn');
        const returnUrl = @json($returnUrl ?? '');
        const settingsUrl = @json($settingsUrl ?? '');
        const fallbackUrl = returnUrl || settingsUrl || '';

        function isStandaloneApp() {
          try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
            if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
          } catch (e) {
            // ignore
          }

          return Boolean(window.navigator && 'standalone' in window.navigator && window.navigator.standalone);
        }

        btn?.addEventListener('click', function () {
          if (isStandaloneApp()) {
            if (window.history.length > 1) {
              window.history.back();
            } else if (fallbackUrl) {
              window.location.replace(fallbackUrl);
            }
            return;
          }

          try { window.close(); } catch (e) { /* ignore */ }

          // Если вкладку закрыть нельзя, возвращаем пользователя на исходную страницу.
          setTimeout(function () {
            if (document.visibilityState !== 'hidden' && fallbackUrl) {
              window.location.replace(fallbackUrl);
            }
          }, 150);
        });
      });
    </script>

    @if (! $hasMap)
      <div class="empty">
        <strong>Карта не загружена.</strong>
        <div style="margin-top:6px; opacity:.8;">
          Загрузите PDF-карту в настройках рынка (поле “Карта (PDF)”), нажмите “Сохранить”, затем откройте просмотр снова.
        </div>
      </div>
    @elseif ($marketSpaceNotLinked)
      <div class="empty">
        <strong>Торговое место не привязано к объектам карты.</strong>
        <div style="margin-top:6px; opacity:.8;">
          Привяжите место к полигону или прямоугольнику, чтобы открыть карту с фокусом.
        </div>
      </div>
    @else
      <div class="map-layout">
        <div class="viewer">
        <div class="toolbar">
          <div class="toolbar-row toolbar-row--hero">
            <!-- Left: Zoom, Controls & Search -->
            <div class="toolbar-group toolbar-group--hero-left">
              <div class="toolbar-group--hero-top">
                <div class="toolbar-group toolbar-group--accent">
                  <button id="zoomOut" type="button" title="Уменьшить масштаб карты" aria-label="Уменьшить масштаб карты">−</button>
                  <button id="zoomIn" type="button" title="Увеличить масштаб карты" aria-label="Увеличить масштаб карты">+</button>
                  <button id="fitWidth" type="button" title="Подогнать карту по ширине окна" aria-label="Подогнать карту по ширине окна">По ширине</button>
                  @if ($canOpenPdf)
                    <a class="pill" href="{{ $pdfUrl }}" target="_blank" rel="noopener noreferrer" title="Открыть исходный PDF-план в новой вкладке" aria-label="Открыть исходный PDF-план в новой вкладке">Открыть PDF</a>
                  @endif
                </div>

                @if ($canEdit)
                  <div class="toolbar-group toolbar-group--accent toolbar-group--control-segmented">
                    <button id="toggleEdit" type="button" title="Включить редактирование разметки карты" aria-label="Включить редактирование разметки карты">Редактирование</button>
                    <button id="toolSelect" type="button" style="display:none;" title="Редактировать существующую разметку" aria-label="Редактировать существующую разметку">Редактировать</button>
                    <button id="toolRect" type="button" style="display:none;" title="Нарисовать прямоугольную область" aria-label="Нарисовать прямоугольную область">Прямоугольник</button>
                    <button id="toolPoly" type="button" style="display:none;" title="Нарисовать полигон по точкам" aria-label="Нарисовать полигон по точкам">Полигон</button>
                  </div>
                @endif
              </div>

              @if ($canEdit)
                <div class="toolbar-group--hero-bottom">
                  <div class="spacePicker" id="spacePicker">
                    <svg class="spacePickerIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input
                      id="spaceSearch"
                      type="text"
                      class="spaceSearchInput"
                      placeholder="Номер / код / арендатор / ID"
                      autocomplete="off"
                      title="Поиск места по номеру, коду, арендатору или ID"
                      aria-label="Поиск места по номеру, коду, арендатору или ID"
                    >
                    <div id="spaceDropdown" class="spaceDropdown" role="listbox" aria-label="Результаты поиска"></div>
                  </div>
                </div>
              @endif
            </div>

            <!-- Center: Selection Info -->
            @if ($canEdit)
              <div class="toolbar-group toolbar-group--hero-center toolbar-group--hero-review">
                <span class="pill hero-chosen" id="spaceChosenPill" title="Выбранное место для ревизии"></span>
                <div class="toolbar-group toolbar-group--review-nav" id="reviewNavRow" style="display:none;">
                  <button id="reviewNavPrev" type="button" title="Перейти к предыдущему месту в очереди ревизии" aria-label="Перейти к предыдущему месту в очереди ревизии">← Предыдущее</button>
                  <button id="reviewNavNextPending" type="button" title="Перейти к следующему непройденному месту" aria-label="Перейти к следующему непройденному месту">Следующее непройденное →</button>
                  <button id="reviewNavNext" type="button" title="Перейти к следующему месту в очереди ревизии" aria-label="Перейти к следующему месту в очереди ревизии">Следующее →</button>
                </div>
              </div>
            @else
              <div class="toolbar-group toolbar-group--hero-center"></div>
            @endif

            <!-- Right: Mode & Layers -->
            <div class="toolbar-group toolbar-group--hero-actions">
              <div class="toolbar-group toolbar-group--hero-actions-main">
                <div class="toolbar-group toolbar-group--hero-stack">
                  @if ($canEdit)
                    <div class="toolbar-group toolbar-group--accent toolbar-group--segmented">
                      <span class="toolbar-label">Режим</span>
                      <button id="scenarioMap" type="button" class="button-toggle is-active" title="Обычный режим просмотра карты" aria-label="Обычный режим просмотра карты">Карта</button>
                      <button id="scenarioReview" type="button" class="button-toggle" title="Режим ревизии мест и разметки" aria-label="Режим ревизии мест и разметки">Ревизия</button>
                      <button id="closeBtn" type="button" class="button-toggle button-toggle--close" title="Закрыть карту и вернуться назад" aria-label="Закрыть карту и вернуться назад">×</button>
                    </div>
                  @endif

                  <div class="toolbar-group toolbar-group--accent toolbar-group--segmented">
                    <span class="toolbar-label" id="layerToolbarLabel">Слои</span>
                    <button
                      id="layerDebt"
                      type="button"
                      class="button-toggle is-active"
                      title="Слой показывает статус задолженности по занятым местам."
                      aria-label="Слой показывает статус задолженности по занятым местам."
                    >Задолженность</button>
                    <button
                      id="layerRent"
                      type="button"
                      class="button-toggle"
                      title="Слой показывает относительную ставку по занятым местам."
                      aria-label="Слой показывает относительную ставку по занятым местам."
                    >Арендная ставка</button>
                  </div>
                </div>

              </div>
            </div>
          </div>

          <div class="toolbar-row toolbar-row--controls">
            @if ($canEdit)
              <div class="toolbar-group toolbar-group--search-slot">
                <label class="pill" id="spaceNumberPill" style="display:none;" title="Введите номер места для поиска ID">
                  Номер:
                  <input
                    id="marketSpaceNumber"
                    type="text"
                    inputmode="text"
                    placeholder="например 45-4"
                    title="Номер места для поиска по карте"
                    aria-label="Номер места для поиска по карте"
                    style="width:120px; padding:6px 8px; border-radius:10px; border:1px solid rgba(120,120,120,.25); background:rgba(120,120,120,.06); color:inherit;"
                  >
                </label>

                <button id="findByNumber" type="button" style="display:none;" title="Найти место по введённому номеру" aria-label="Найти место по введённому номеру">Найти ID</button>

                <label class="pill" style="display:none;" title="Технический идентификатор места">
                  Место ID:
                  <input
                    id="marketSpaceId"
                    type="number"
                    min="1"
                    step="1"
                    inputmode="numeric"
                    placeholder="ID"
                    title="Технический идентификатор места"
                    aria-label="Технический идентификатор места"
                    style="width:92px; padding:6px 8px; border-radius:10px; border:1px solid rgba(120,120,120,.25); background:rgba(120,120,120,.06); color:inherit;"
                  >
                </label>

                <span class="pill" id="spaceIdState" style="display:none;" title="Текущий ID выбранного места">ID: —</span>
              </div>

              <div class="toolbar-group toolbar-group--utility">
                <span class="pill" id="scaleLabel" style="display:none;" title="Текущий масштаб карты">Масштаб: 100%</span>
              </div>
            @else
              <div class="toolbar-group toolbar-group--utility">
                <span class="pill" id="scaleLabel" style="display:none;" title="Текущий масштаб карты">Масштаб: 100%</span>
              </div>
            @endif
          </div>

          <div class="toolbar-row toolbar-row--edit-hint" id="editHintRow" aria-hidden="true">
            <span class="pill" id="editHint" style="display:none;" title="Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить">Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить</span>
          </div>

          @if ($canEdit)
            <div class="toolbar-row toolbar-row--review-status" id="reviewToolbarRow">
              <div class="toolbar-group toolbar-group--review-status-group">
                <span class="pill" id="reviewNavStatus">Места не загружены</span>
                <button id="reviewNotFound" type="button" style="display:none;" hidden title="Отметить, что выбранное место не найдено на карте" aria-label="Отметить, что выбранное место не найдено на карте">Не найдено на карте</button>
                <div class="review-progress" id="reviewProgress" aria-live="polite" title="Прогресс ревизии по местам">
                  <div class="review-progress__track">
                    <div class="review-progress__fill" id="reviewProgressFill"></div>
                  </div>
                  <span class="review-progress__text" id="reviewProgressText">0 / 0</span>
                </div>
                <div class="review-summary" id="reviewSummary"></div>
              </div>
            </div>
          @endif
        </div>

        <div class="map-load-progress" id="mapLoadProgress" aria-live="polite">
          <div class="map-load-progress__meta">
            <span class="map-load-progress__text" id="mapLoadProgressText">Загрузка карты…</span>
            <span class="map-load-progress__percent" id="mapLoadProgressPercent">0%</span>
          </div>
          <div class="map-load-progress__track">
            <div class="map-load-progress__fill" id="mapLoadProgressFill"></div>
          </div>
        </div>

        <div id="viewerRoot">
          <div class="stage" id="stage">
            <div class="canvasWrap" id="canvasWrap">
              <canvas id="canvas"></canvas>
              <svg id="shapesSvg" class="shapesSvg" aria-hidden="true"></svg>
              <div id="drawBox" class="drawBox" aria-hidden="true"></div>

              <div id="overlay" class="overlay" aria-label="map-overlay">
                <div id="handlesLayer" class="handlesLayer" aria-hidden="true"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

        <div class="legend-stack">
        <!-- Легенда карты -->
        <div class="legend" id="legendDebt">
          <div class="legend-items">
            <div class="legend-item">
              <span class="legend-color" style="background: #22c55e;"></span>
              <span class="legend-label">Нет просрочки</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #f59e0b;"></span>
              <span class="legend-label">
                @if (($debtYellowAfterDays ?? 1) <= 1)
                  Просрочка до {{ ($debtRedAfterDays ?? 30) - 1 }} дней
                @else
                  Просрочка {{ $debtYellowAfterDays ?? 1 }}-{{ ($debtRedAfterDays ?? 30) - 1 }} дней
                @endif
              </span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #b91c1c;"></span>
              <span class="legend-label">Просрочка по месту от {{ $debtRedAfterDays ?? 30 }} дней (точная связь)</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #ef4444;"></span>
              <span class="legend-label">Риск арендатора от {{ $debtRedAfterDays ?? 30 }} дней (tenant fallback)</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #94a3b8;"></span>
              <span class="legend-label">Нет данных 1С</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-vacant"></span>
              <span class="legend-label">Свободно</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-unlinked"></span>
              <span class="legend-label">Разметка без привязки</span>
            </div>
            <div class="legend-item legend-item--note">
              <span class="legend-note" id="debtLegendNote">Слой показывает статус задолженности по занятым местам и отдельно отмечает tenant fallback.</span>
            </div>
          </div>
        </div>
        <div class="legend" id="legendRent" hidden>
          <div class="legend-items">
            <div class="legend-item">
              <span class="legend-color" style="background: #fef3c7;"></span>
              <span class="legend-label" id="rentLegendLow">Низкая ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #fbbf24;"></span>
              <span class="legend-label" id="rentLegendMid">Средняя ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #f97316;"></span>
              <span class="legend-label" id="rentLegendHigh">Повышенная ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color" style="background: #dc2626;"></span>
              <span class="legend-label" id="rentLegendTop">Высокая ставка</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-rate-none"></span>
              <span class="legend-label">Ставка не задана</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-vacant"></span>
              <span class="legend-label">Свободно</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-unlinked"></span>
              <span class="legend-label">Разметка без привязки</span>
            </div>
            <div class="legend-item legend-item--note">
              <span class="legend-note" id="rentLegendNote">Слой показывает относительную ставку по занятым местам.</span>
            </div>
          </div>
        </div>
        <div class="legend" id="legendReview" hidden>
          <div class="legend-items">
            <div class="legend-item">
              <span class="legend-color legend-conflict"></span>
              <span class="legend-label" title="Привязанное место требует ручной проверки по ревизии">Ревизионный конфликт</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-fallback"></span>
              <span class="legend-label" title="У места есть арендатор, но точная связь с договорами и 1С не подтверждена">Связь с местом не подтверждена</span>
            </div>
            <div class="legend-item">
              <span class="legend-color legend-combined-review"></span>
              <span class="legend-label" title="Место одновременно помечено как конфликтное и не имеет подтвержденной per-space связи">Конфликт + связь не подтверждена</span>
            </div>
            <div class="legend-item legend-item--note">
              <span class="legend-note">Остальные места в режиме ревизии показываются нейтрально. Ревизионные маркеры не заменяют слой задолженности или ставки.</span>
            </div>
          </div>
        </div>
        </div>
      </div>

      <div id="popover" class="popover" role="dialog" aria-live="polite">
        <button id="popoverClose" class="xbtn" type="button" aria-label="Закрыть">×</button>
        <div id="popoverBody"></div>
      </div>

      <div id="identityFixModal" class="identity-fix-modal" hidden aria-hidden="true">
        <div class="identity-fix-modal__backdrop" data-action="close"></div>
        <div
          class="identity-fix-modal__dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="identityFixTitle"
          aria-describedby="identityFixDescription"
        >
          <button id="identityFixClose" class="identity-fix-modal__close" type="button" data-action="close" aria-label="Закрыть">×</button>
          <div class="identity-fix-modal__eyebrow">Уточнить данные</div>
          <h2 id="identityFixTitle" class="identity-fix-modal__title">Уточнить номер / название места</h2>
          <p id="identityFixDescription" class="identity-fix-modal__description">
            Введите, как это место обозначено на схеме, вывеске или на месте.
          </p>
          <label class="identity-fix-modal__label" for="identityFixInput">Номер или название места</label>
          <input
            id="identityFixInput"
            class="identity-fix-modal__input"
            type="text"
            autocomplete="off"
            spellcheck="false"
            inputmode="text"
          >
          <div class="identity-fix-modal__actions">
            <button type="button" class="identity-fix-modal__cancel" data-action="close">Отмена</button>
            <button type="button" class="identity-fix-modal__save" data-action="save">Сохранить</button>
          </div>
        </div>
      </div>

      <div id="toast" class="toast" role="status" aria-live="polite"></div>

      <script type="module">
        const PDF_URL    = @json($pdfUrl,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const HIT_URL    = @json($hitUrl,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SHAPES_URL = @json($shapesUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SPACE_URL  = @json($spaceUrl,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const SPACES_URL = @json($spacesUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const REVIEW_DECISION_URL = @json($reviewDecisionUrl ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        const CAN_EDIT  = @json((bool) $canEdit);
        const INITIAL_MAP_MODE = @json($mapMode ?? 'map');
        const MARKET_ID = @json((int) $marketId);
        const MAP_PAGE = @json((int) ($mapPage ?? 1));
        const MAP_VERSION = @json((int) ($mapVersion ?? 1));
        const FOCUS_SPACE_ID = @json(isset($marketSpaceId) ? (int) $marketSpaceId : null);
        const FOCUS_SHAPE = @json($focusShape ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        const INITIAL_REVIEW_PROGRESS = @json($reviewProgress ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') : '';

        const viewerRoot = document.getElementById('viewerRoot');

        const zoomInBtn = document.getElementById('zoomIn');
        const zoomOutBtn = document.getElementById('zoomOut');
        const zoomResetBtn = document.getElementById('zoomReset');
        const fitWidthBtn = document.getElementById('fitWidth');
        const scaleLabel = document.getElementById('scaleLabel');
        const layerDebtBtn = document.getElementById('layerDebt');
        const layerRentBtn = document.getElementById('layerRent');
        const legendDebt = document.getElementById('legendDebt');
        const legendRent = document.getElementById('legendRent');
        const legendReview = document.getElementById('legendReview');
        const rentLegendLow = document.getElementById('rentLegendLow');
        const rentLegendMid = document.getElementById('rentLegendMid');
        const rentLegendHigh = document.getElementById('rentLegendHigh');
        const rentLegendTop = document.getElementById('rentLegendTop');
        const rentLegendNote = document.getElementById('rentLegendNote');

        const popover = document.getElementById('popover');
        const popoverBody = document.getElementById('popoverBody');
        const popoverClose = document.getElementById('popoverClose');

        const toggleEditBtn = document.getElementById('toggleEdit');
        const editToolbarRow = toggleEditBtn?.closest('.toolbar-row') || null;
        const mapEditGroup = toggleEditBtn?.closest('.toolbar-group') || null;
        const toolSelectBtn = document.getElementById('toolSelect');
        const toolRectBtn = document.getElementById('toolRect');
        const toolPolyBtn = document.getElementById('toolPoly');
        const scenarioMapBtn = document.getElementById('scenarioMap');
        const scenarioReviewBtn = document.getElementById('scenarioReview');
        const layerGroup = layerDebtBtn?.closest('.toolbar-group') || null;
        const layerToolbarLabel = document.getElementById('layerToolbarLabel');
        const reviewToolbarRow = document.getElementById('reviewToolbarRow');
        const reviewNavRow = document.getElementById('reviewNavRow');
        const reviewNavPrevBtn = document.getElementById('reviewNavPrev');
        const reviewNavNextPendingBtn = document.getElementById('reviewNavNextPending');
        const reviewNavNextBtn = document.getElementById('reviewNavNext');
        const reviewNavStatus = document.getElementById('reviewNavStatus');
        const reviewNotFoundBtn = document.getElementById('reviewNotFound');
        const mapLoadProgress = document.getElementById('mapLoadProgress');
        const mapLoadProgressFill = document.getElementById('mapLoadProgressFill');
        const mapLoadProgressText = document.getElementById('mapLoadProgressText');
        const mapLoadProgressPercent = document.getElementById('mapLoadProgressPercent');
        const reviewProgress = document.getElementById('reviewProgress');
        const reviewProgressFill = document.getElementById('reviewProgressFill');
        const reviewProgressText = document.getElementById('reviewProgressText');
        const reviewSummary = document.getElementById('reviewSummary');

        const spacePicker = document.getElementById('spacePicker');
        const searchSlotGroup = spacePicker?.closest('.toolbar-group') || null;
        const spaceSearchInput = document.getElementById('spaceSearch');
        const spaceDropdown = document.getElementById('spaceDropdown');
        const spaceChosenPill = document.getElementById('spaceChosenPill');
        const spaceIdState = document.getElementById('spaceIdState');
        const identityFixModal = document.getElementById('identityFixModal');
        const identityFixInput = document.getElementById('identityFixInput');
        const identityFixClose = document.getElementById('identityFixClose');
        const utilityGroup = scaleLabel?.closest('.toolbar-group') || null;

        const editHint = document.getElementById('editHint');
        const editHintRow = document.getElementById('editHintRow');

        const LS_KEY_CHOSEN = 'mp.marketMap.market_' + String(MARKET_ID) + '.chosenSpace';
        const LS_KEY_LAYER = 'mp.marketMap.market_' + String(MARKET_ID) + '.layer';

        let chosenSpace = null;
        let isEditMode = false;
        let currentScenario = INITIAL_MAP_MODE === 'review' ? 'review' : 'map';
        let reviewProgressState = INITIAL_REVIEW_PROGRESS || {};
        let currentLayer = 'debt';
        let redrawShapesRef = null;
        let reviewNavItems = [];
        let updateReviewNavUi = () => {};
        let syncReviewNavFromShapes = () => {};
        let navigateReview = async () => {};
        let searchResults = [];
        let searchIndex = -1;
        let searchTimer = null;
        let searchController = null;
        let mapLoadProgressHideTimer = null;
        let mapLoadProgressState = 'idle';
        let identityFixContext = null;

        function escapeHtml(s) {
          return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
        }

        function disablePdfJsControls() {
          if (zoomInBtn) zoomInBtn.disabled = true;
          if (zoomOutBtn) zoomOutBtn.disabled = true;
          if (zoomResetBtn) zoomResetBtn.disabled = true;
          if (fitWidthBtn) fitWidthBtn.disabled = true;
          if (scaleLabel) scaleLabel.textContent = 'Масштаб: (встроенный просмотр)';
        }

        function nextUiFrame() {
          return new Promise((resolve) => {
            requestAnimationFrame(() => resolve());
          });
        }

        function syncEditToggleUi() {
          if (!toggleEditBtn) {
            return;
          }

          const nextActionText = 'Редактирование';
          const nextActionHint = isEditMode ? 'Выключить редактирование разметки карты' : 'Включить редактирование разметки карты';

          toggleEditBtn.textContent = nextActionText;
          toggleEditBtn.title = nextActionHint;
          toggleEditBtn.setAttribute('aria-label', nextActionHint);
          toggleEditBtn.classList.toggle('is-active', !!isEditMode);
        }

        function syncLayerButtonHelp() {
          const reviewMode = isReviewMode();
          const debtHelp = (debtLegendNote?.textContent || 'Слой показывает статус задолженности по занятым местам.').trim();
          const rentHelp = (rentLegendNote?.textContent || 'Слой показывает относительную ставку по занятым местам.').trim();
          if (layerDebtBtn && debtHelp) {
            const debtTitle = reviewMode ? ('Фон карты: ' + debtHelp) : debtHelp;
            layerDebtBtn.title = debtTitle;
            layerDebtBtn.setAttribute('aria-label', debtTitle);
          }
          if (layerRentBtn && rentHelp) {
            const rentTitle = reviewMode ? ('Фон карты: ' + rentHelp) : rentHelp;
            layerRentBtn.title = rentTitle;
            layerRentBtn.setAttribute('aria-label', rentTitle);
          }
        }

        function mapLoadStatePriority(state) {
          switch (state) {
            case 'loading':
              return 1;
            case 'rendering':
              return 2;
            case 'done':
              return 3;
            case 'fallback':
              return 4;
            default:
              return 0;
          }
        }

        function setMapLoadProgress(percent, text, state = 'loading') {
          if (!mapLoadProgress || !mapLoadProgressFill || !mapLoadProgressText) {
            return;
          }

          const nextPriority = mapLoadStatePriority(state);
          const currentPriority = mapLoadStatePriority(mapLoadProgressState);
          if (currentPriority >= 3 && nextPriority < currentPriority) {
            return;
          }
          if (nextPriority < currentPriority && currentPriority > 0) {
            return;
          }

          if (mapLoadProgressHideTimer) {
            clearTimeout(mapLoadProgressHideTimer);
            mapLoadProgressHideTimer = null;
          }

          const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
          const isFinalizing = state === 'rendering' || (state === 'loading' && safePercent >= 68);
          if (isFinalizing && state === 'loading') {
            text = 'PDF почти загружен, подготавливаем карту…';
          }
          mapLoadProgressState = state;
          mapLoadProgress.dataset.state = state;
          if (isFinalizing) {
            mapLoadProgress.dataset.phase = 'finalizing';
          } else {
            delete mapLoadProgress.dataset.phase;
          }
          mapLoadProgress.style.display = 'flex';
          mapLoadProgressFill.style.width = safePercent + '%';
          mapLoadProgressText.textContent = text;
          if (mapLoadProgressPercent) {
            mapLoadProgressPercent.textContent = isFinalizing
              ? 'Подготовка…'
              : safePercent + '%';
          }
        }

        function hideMapLoadProgress(resetState = false) {
          if (!mapLoadProgress) {
            return;
          }

          if (mapLoadProgressHideTimer) {
            clearTimeout(mapLoadProgressHideTimer);
            mapLoadProgressHideTimer = null;
          }

          mapLoadProgress.style.display = 'none';
          mapLoadProgressFill && (mapLoadProgressFill.style.width = '0%');
          if (mapLoadProgressPercent) {
            mapLoadProgressPercent.textContent = '0%';
          }
          if (resetState) {
            mapLoadProgressState = 'idle';
          }
          delete mapLoadProgress.dataset.state;
          delete mapLoadProgress.dataset.phase;
        }

        function completeMapLoadProgress(text = 'Карта загружена') {
          setMapLoadProgress(100, text, 'done');
          mapLoadProgressHideTimer = setTimeout(() => {
            hideMapLoadProgress();
          }, 1200);
        }

        function fallbackToIframe(reason) {
          console.warn('PDF.js fallback:', reason);
          setMapLoadProgress(100, 'Встроенный просмотр PDF', 'fallback');
          disablePdfJsControls();
          if (viewerRoot) {
            viewerRoot.innerHTML = '<iframe class="iframe" src="' + PDF_URL + '" loading="lazy"></iframe>';
          }
        }

        function toast(text) {
          const el = document.getElementById('toast');
          if (!el) return;

          el.textContent = text;
          el.classList.add('show');

          if (el._t) clearTimeout(el._t);
          el._t = setTimeout(() => el.classList.remove('show'), 1400);
        }

        function hidePopover() {
          if (!popover) return;
          popover.classList.remove('show');
        }

        function getIdentityFixPrefill(context = {}) {
          const sourceSpace = context.space || context.hit?.space || chosenSpace || null;
          const fallback = context.prefillValue
            ?? sourceSpace?.number
            ?? sourceSpace?.display_name
            ?? context.hit?.space?.number
            ?? context.hit?.space?.display_name
            ?? '';
          return String(fallback || '').trim();
        }

        function closeIdentityFixModal() {
          if (!identityFixModal) return;

          identityFixModal.classList.remove('show');
          identityFixModal.hidden = true;
          identityFixModal.setAttribute('aria-hidden', 'true');
          identityFixContext = null;
        }

        function openIdentityFixModal(context = {}) {
          if (!identityFixModal || !identityFixInput) return;

          identityFixContext = {
            ...context,
            prefillValue: getIdentityFixPrefill(context),
          };

          identityFixInput.value = identityFixContext.prefillValue || '';
          identityFixModal.hidden = false;
          identityFixModal.classList.add('show');
          identityFixModal.setAttribute('aria-hidden', 'false');

          requestAnimationFrame(() => {
            identityFixInput.focus({ preventScroll: true });
            identityFixInput.select();
          });
        }

        async function submitIdentityFixModal() {
          if (!identityFixInput) return;

          const value = String(identityFixInput.value || '').trim();
          if (!value) {
            toast('Нужен номер или название места');
            identityFixInput.focus({ preventScroll: true });
            return;
          }

          const context = identityFixContext || {};
          closeIdentityFixModal();
          await submitReviewDecision('fix_space_identity', {
            ...context,
            identityValue: value,
          });
        }

        function showPopoverAt(clientX, clientY, html) {
          if (!popover || !popoverBody) return;

          popoverBody.innerHTML = html;

          const pad = 10;
          popover.classList.add('show');

          popover.style.left = (clientX + 12) + 'px';
          popover.style.top  = (clientY + 12) + 'px';

          const r = popover.getBoundingClientRect();
          let left = r.left;
          let top = r.top;

          if (r.right > window.innerWidth - pad) {
            left = Math.max(pad, window.innerWidth - pad - r.width);
          }
          if (r.bottom > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - pad - r.height);
          }

          popover.style.left = left + 'px';
          popover.style.top = top + 'px';
        }

        popoverClose?.addEventListener('click', hidePopover);
        identityFixModal?.addEventListener('click', (e) => {
          const t = e.target;
          if (!(t instanceof HTMLElement)) return;

          const action = t.getAttribute('data-action');
          if (action === 'close') {
            e.preventDefault();
            closeIdentityFixModal();
            return;
          }

          if (action === 'save') {
            e.preventDefault();
            submitIdentityFixModal().catch((err) => {
              console.error(err);
              toast(String(err?.message || err));
            });
          }
        });
        identityFixInput?.addEventListener('keydown', (e) => {
          if (e.key !== 'Enter') return;
          e.preventDefault();
          submitIdentityFixModal().catch((err) => {
            console.error(err);
            toast(String(err?.message || err));
          });
        });
        identityFixClose?.addEventListener('click', closeIdentityFixModal);
        window.addEventListener('keydown', (e) => {
          if (identityFixModal?.classList.contains('show')) {
            if (e.key === 'Escape') {
              e.preventDefault();
              closeIdentityFixModal();
            }
            return;
          }

          if (e.key === 'Escape') hidePopover();
        });
        window.addEventListener('click', (e) => {
          if (!popover?.classList.contains('show')) return;
          if (popover.contains(e.target)) return;
          hidePopover();
        });

        async function apiFetch(url, options = {}) {
          const opts = { ...options };
          const method = String(opts.method || 'GET').toUpperCase();

          const headers = { ...(opts.headers || {}) };
          headers['Accept'] = headers['Accept'] || 'application/json';
          headers['X-Requested-With'] = headers['X-Requested-With'] || 'XMLHttpRequest';

          if (method !== 'GET' && method !== 'HEAD') {
            if (CSRF_TOKEN) headers['X-CSRF-TOKEN'] = CSRF_TOKEN;
          } else {
            headers['Cache-Control'] = headers['Cache-Control'] || 'no-cache, no-store, must-revalidate';
            headers['Pragma'] = headers['Pragma'] || 'no-cache';
          }

          opts.headers = headers;
          opts.credentials = 'same-origin';
          if (method === 'GET' || method === 'HEAD') {
            opts.cache = opts.cache || 'no-store';
          }

          return fetch(url, opts);
        }

        function getChosenSpaceId() {
          return chosenSpace?.id ?? null;
        }

        function normalizeChosenSpace(item) {
          const id = Number(item?.id);
          if (!Number.isFinite(id) || id <= 0) return null;
          return {
            id: Math.trunc(id),
            number: item?.number ? String(item.number) : '',
            displayName: item?.displayName ? String(item.displayName) : (item?.display_name ? String(item.display_name) : ''),
            code: item?.code ? String(item.code) : '',
            tenantName: item?.tenant?.name ? String(item.tenant.name) : (item?.tenantName ? String(item.tenantName) : null),
            reviewStatus: item?.review_status ? String(item.review_status) : '',
            reviewStatusLabel: item?.review_status_label ? String(item.review_status_label) : '',
          };
        }

        function formatSpaceLabel(space) {
          if (!space) return '—';
          const numberLabel = String(space.number ?? '').trim();
          const codeLabel = String(space.code ?? '').trim();
          const label = numberLabel || codeLabel || '—';
          return '№' + label;
        }

        function formatSpaceDropdownLabel(space) {
          const numberLabel = String(space.number ?? '').trim();
          const codeLabel = String(space.code ?? '').trim();
          const label = numberLabel || codeLabel || '—';
          const tenant = space.tenantName ? space.tenantName : '—';
          return '№' + label + ' — ' + tenant + ' (ID ' + String(space.id) + ')';
        }

        function formatMoneyRu(value) {
          const num = Number(value);
          if (!Number.isFinite(num)) return '';
          return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
          }).format(num) + ' ₽';
        }

        function buildPopoverRow(text, extraClass = '') {
          if (!text) return '';

          const rowClass = extraClass ? ('row ' + extraClass) : 'row';
          const separatorIndex = text.indexOf(':');

          if (separatorIndex === -1) {
            return '<div class="' + rowClass + '"><span class="row-value">' + text + '</span></div>';
          }

          const label = text.slice(0, separatorIndex + 1).trim();
          const value = text.slice(separatorIndex + 1).trim();

          if (!value) {
            return '<div class="' + rowClass + '"><span class="row-label">' + label + '</span></div>';
          }

          return '<div class="' + rowClass + '"><span class="row-label">' + label + '</span> <span class="row-value">' + value + '</span></div>';
        }

        function rentRateUnitLabel(unit) {
          const key = String(unit || '').trim();
          if (!key) return '';

          const map = {
            per_sqm_month: 'за м² в месяц',
            per_space_month: 'за место в месяц',
          };

          return map[key] || key;
        }

        function normalizeLayerMode(value) {
          return value === 'rent' ? 'rent' : 'debt';
        }

        function loadLayerModeFromLS() {
          try {
            currentLayer = normalizeLayerMode(localStorage.getItem(LS_KEY_LAYER));
          } catch {
            currentLayer = 'debt';
          }
        }

        function saveLayerModeToLS() {
          try {
            localStorage.setItem(LS_KEY_LAYER, currentLayer);
          } catch {}
        }

        function formatLegendRateValue(value) {
          const num = Number(value);
          if (!Number.isFinite(num)) return '—';
          return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
          }).format(num);
        }

        function buildRentLayerStats(items) {
          const rates = [];
          const units = new Set();

          for (const item of items) {
            const rate = Number(item?.space_rent_rate_value);
            const hasSpace = !!item?.market_space_id;
            const hasTenant = hasSpace && item?.space_tenant_id !== null && item?.space_tenant_id !== undefined;

            if (!hasTenant || !Number.isFinite(rate) || rate <= 0) continue;

            rates.push(rate);

            const unit = String(item?.space_rent_rate_unit || '').trim();
            if (unit) units.add(unit);
          }

          rates.sort((a, b) => a - b);

          if (!rates.length) {
            return null;
          }

          const quantile = (q) => {
            if (rates.length === 1) return rates[0];
            const pos = (rates.length - 1) * q;
            const base = Math.floor(pos);
            const rest = pos - base;
            const left = rates[base];
            const right = rates[Math.min(base + 1, rates.length - 1)];
            return left + ((right - left) * rest);
          };

          return {
            lowMax: quantile(0.25),
            midMax: quantile(0.5),
            highMax: quantile(0.75),
            unit: units.size === 1 ? Array.from(units)[0] : '',
            mixedUnits: units.size > 1,
          };
        }

        function getRentRateBand(rateValue, stats) {
          const rate = Number(rateValue);
          if (!Number.isFinite(rate) || rate <= 0 || !stats) return 'none';
          if (rate <= stats.lowMax) return 'low';
          if (rate <= stats.midMax) return 'mid';
          if (rate <= stats.highMax) return 'high';
          return 'top';
        }

        function updateLegendVisibility() {
          const reviewMode = isReviewMode();
          if (legendDebt) legendDebt.hidden = reviewMode || currentLayer !== 'debt';
          if (legendRent) legendRent.hidden = reviewMode || currentLayer !== 'rent';
          if (legendReview) legendReview.hidden = !reviewMode;
          layerDebtBtn?.classList.toggle('is-active', currentLayer === 'debt');
          layerRentBtn?.classList.toggle('is-active', currentLayer === 'rent');
          syncLayerButtonHelp();
        }

        function updateRentLegend(items) {
          const stats = buildRentLayerStats(items);
          const unitText = stats?.unit ? (' (' + rentRateUnitLabel(stats.unit) + ')') : '';

          if (rentLegendLow) {
            rentLegendLow.textContent = stats
              ? ('Низкая ставка до ' + formatLegendRateValue(stats.lowMax) + unitText)
              : 'Низкая ставка';
          }

          if (rentLegendMid) {
            rentLegendMid.textContent = stats
              ? ('Средняя ставка до ' + formatLegendRateValue(stats.midMax) + unitText)
              : 'Средняя ставка';
          }

          if (rentLegendHigh) {
            rentLegendHigh.textContent = stats
              ? ('Повышенная ставка до ' + formatLegendRateValue(stats.highMax) + unitText)
              : 'Повышенная ставка';
          }

          if (rentLegendTop) {
            rentLegendTop.textContent = stats
              ? ('Высокая ставка от ' + formatLegendRateValue(stats.highMax) + unitText)
              : 'Высокая ставка';
          }

          if (rentLegendNote) {
            if (!stats) {
              rentLegendNote.textContent = 'Нет данных по ставке для занятых мест.';
            } else if (stats.mixedUnits) {
              rentLegendNote.textContent = 'Слой показывает относительную ставку; единицы измерения различаются.';
            } else if (stats.unit) {
              rentLegendNote.textContent = 'Слой показывает относительную ставку по занятым местам (' + rentRateUnitLabel(stats.unit) + ').';
            } else {
              rentLegendNote.textContent = 'Слой показывает относительную ставку по занятым местам.';
            }
          }
          syncLayerButtonHelp();
        }

        function setLayerMode(mode) {
          currentLayer = normalizeLayerMode(mode);
          saveLayerModeToLS();
          updateLegendVisibility();
          if (typeof redrawShapesRef === 'function') {
            redrawShapesRef();
          }
        }


        function isReviewMode() {
          return currentScenario === 'review';
        }

        function isSelectionToolbarVisible() {
          return true;
        }

        function updateReviewProgress(nextProgress = null) {
          if (nextProgress && typeof nextProgress === 'object') {
            reviewProgressState = nextProgress;
          }

          if (!reviewProgress || !reviewProgressFill || !reviewProgressText || !reviewSummary) {
            return;
          }

          const total = Number(reviewProgressState?.total || 0);
          const reviewed = Number(reviewProgressState?.reviewed || 0);
          const percent = Number(reviewProgressState?.percent || 0);
          const counts = reviewProgressState?.counts || {};
          const labels = reviewProgressState?.labels || {};

          reviewProgress.style.display = isReviewMode() ? 'inline-flex' : 'none';
          reviewProgressFill.style.width = Math.max(0, Math.min(100, percent)) + '%';
          reviewProgressText.textContent = reviewed + ' / ' + total + ' / ' + percent + '%';

          if (!isReviewMode()) {
            reviewSummary.style.display = 'none';
            reviewSummary.innerHTML = '';
            return;
          }

          const statuses = Object.entries(counts);
          if (!statuses.length) {
            reviewSummary.style.display = 'none';
            reviewSummary.innerHTML = '';
            return;
          }

          reviewSummary.style.display = 'flex';
          reviewSummary.innerHTML = statuses
            .map(([status, count]) => '<span class="pill">' + escapeHtml(labels[status] || status) + ': ' + escapeHtml(String(count)) + '</span>')
            .join('');
        }

        function updateScenarioUi() {
          const reviewMode = isReviewMode();

          scenarioMapBtn?.classList.toggle('is-active', !reviewMode);
          scenarioReviewBtn?.classList.toggle('is-active', reviewMode);

          if (layerToolbarLabel) {
            layerToolbarLabel.textContent = reviewMode ? 'Фон' : 'Слои';
          }

          if (editToolbarRow) {
            editToolbarRow.style.display = 'flex';
          }

          if (mapEditGroup) {
            mapEditGroup.style.display = reviewMode ? 'none' : 'inline-flex';
          }

          if (reviewNavRow) {
            reviewNavRow.style.display = reviewMode ? 'inline-flex' : 'none';
          }

          if (reviewToolbarRow) {
            reviewToolbarRow.style.display = reviewMode ? 'flex' : 'none';
          }

          if (searchSlotGroup) {
            searchSlotGroup.style.display = isSelectionToolbarVisible() ? 'inline-flex' : 'none';
          }

          if (spacePicker) {
            spacePicker.style.display = isSelectionToolbarVisible() ? 'inline-flex' : 'none';
          }

          if (reviewNotFoundBtn) {
            reviewNotFoundBtn.style.display = 'none';
            reviewNotFoundBtn.disabled = !chosenSpace;
          }

          if (utilityGroup) {
            utilityGroup.style.display = 'none';
          }

          if (scaleLabel) {
            scaleLabel.style.display = 'none';
          }

          if (spaceIdState) {
            spaceIdState.style.display = 'none';
          }

          if (editHintRow) {
            editHintRow.style.display = isEditMode && !reviewMode ? 'flex' : 'none';
          }
          if (editHint) {
            editHint.style.display = isEditMode && !reviewMode ? 'inline-flex' : 'none';
          }

          if (reviewMode && isEditMode) {
            isEditMode = false;
          }

          updateChosenPill();
          updateReviewNavUi();
          updateReviewProgress();
        }

        function setScenario(mode) {
          currentScenario = mode === 'review' ? 'review' : 'map';
          hidePopover();
          updateScenarioUi();
          updateLegendVisibility();
          if (typeof redrawShapesRef === 'function') {
            redrawShapesRef();
          }

          const url = new URL(window.location.href);
          url.searchParams.set('mode', currentScenario);
          window.history.replaceState({}, '', url.toString());
        }

        function updateChosenPill() {
          if (!spaceChosenPill) return;

          if (!chosenSpace) {
            spaceChosenPill.style.display = 'inline-flex';
            spaceChosenPill.innerHTML =
              '<span class="hero-chosen__label">Выбрано:</span>' +
              '<div class="hero-chosen__body">' +
                '<div class="hero-chosen__empty">Выберите место на карте</div>' +
              '</div>';
            return;
          }

          const numberLabel = String(chosenSpace.number || chosenSpace.code || '—').trim() || '—';
          const tenantLabel = String(chosenSpace.tenantName || '—').trim() || '—';

          spaceChosenPill.style.display = 'inline-flex';
          spaceChosenPill.innerHTML =
            '<span class="hero-chosen__label">Выбрано:</span>' +
            '<div class="hero-chosen__body">' +
              '<div class="hero-chosen__line">' +
                '<span class="hero-chosen__prefix">Место №</span>' +
                '<span class="hero-chosen__value">' + escapeHtml(numberLabel) + '</span>' +
              '</div>' +
              '<div class="hero-chosen__meta">' +
                '<span class="hero-chosen__meta-label">Арендатор:</span>' +
                '<span class="hero-chosen__tenant">' + escapeHtml(tenantLabel) + '</span>' +
              '</div>' +
            '</div>' +
            '<button type="button" class="spacePillButton" data-action="clear-chosen" title="Сбросить выбранное место" aria-label="Сбросить выбранное место">×</button>';
        }

        function saveChosenSpaceToLS() {
          try {
            if (!chosenSpace) {
              localStorage.removeItem(LS_KEY_CHOSEN);
              return;
            }
            const payload = {
              id: chosenSpace.id,
              number: chosenSpace.number,
              code: chosenSpace.code,
              tenantName: chosenSpace.tenantName,
              reviewStatus: chosenSpace.reviewStatus,
              reviewStatusLabel: chosenSpace.reviewStatusLabel,
            };
            localStorage.setItem(LS_KEY_CHOSEN, JSON.stringify(payload));
          } catch {}
        }

        function setChosenSpace(space, opts = {}) {
          const next = space ? {
            id: space.id,
            number: space.number || '',
            code: space.code || '',
            tenantName: space.tenantName ?? null,
            reviewStatus: space.reviewStatus ?? '',
            reviewStatusLabel: space.reviewStatusLabel ?? '',
          } : null;
          chosenSpace = next;
          updateChosenPill();
          updateReviewNavUi();
          saveChosenSpaceToLS();
          if (spaceSearchInput && next) {
            spaceSearchInput.value = '';
          }
          if (reviewNotFoundBtn) {
            reviewNotFoundBtn.disabled = !next;
          }
          if (opts.announce && next) {
            toast('Выбрано место ' + formatSpaceLabel(next) + ' (ID ' + String(next.id) + ')');
          }
        }

        function closeSpaceDropdown() {
          if (!spaceDropdown) return;
          spaceDropdown.style.display = 'none';
          spaceDropdown.innerHTML = '';
          searchResults = [];
          searchIndex = -1;
        }

        function renderSpaceDropdown(items) {
          if (!spaceDropdown) return;
          spaceDropdown.innerHTML = '';
          searchResults = items;
          searchIndex = -1;

          if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'spaceDropdownEmpty';
            empty.textContent = 'Ничего не найдено';
            spaceDropdown.appendChild(empty);
            spaceDropdown.style.display = 'block';
            return;
          }

          items.forEach((item, idx) => {
            const row = document.createElement('div');
            row.className = 'spaceDropdownItem';
            row.setAttribute('role', 'option');
            row.dataset.index = String(idx);
            row.textContent = formatSpaceDropdownLabel(item);
            row.addEventListener('click', () => {
              setChosenSpace(item, { announce: true });
              closeSpaceDropdown();
            });
            spaceDropdown.appendChild(row);
          });
          spaceDropdown.style.display = 'block';
        }

        function updateDropdownActive() {
          if (!spaceDropdown) return;
          const items = Array.from(spaceDropdown.querySelectorAll('.spaceDropdownItem'));
          items.forEach((el, idx) => {
            if (idx === searchIndex) {
              el.classList.add('active');
              el.scrollIntoView({ block: 'nearest' });
            } else {
              el.classList.remove('active');
            }
          });
        }

        function scheduleSpaceSearch() {
          if (searchTimer) clearTimeout(searchTimer);
          searchTimer = setTimeout(() => runSpaceSearch(), 300);
        }

        async function runSpaceSearch() {
          if (!spaceSearchInput) return;
          const value = String(spaceSearchInput.value || '').trim();

          if (!value) {
            closeSpaceDropdown();
            return;
          }

          if (!SPACES_URL) {
            toast('Не задан SPACES_URL');
            closeSpaceDropdown();
            return;
          }

          if (searchController) searchController.abort();
          searchController = new AbortController();

          try {
            const url = new URL(SPACES_URL, window.location.origin);
            url.searchParams.set('q', value);
            url.searchParams.set('limit', '15');

            const res = await apiFetch(url.toString(), {
              headers: { 'Accept': 'application/json' },
              signal: searchController.signal,
            });
            const json = await res.json();

            if (!res.ok || !json || json.ok !== true) {
              toast('Ошибка поиска');
              closeSpaceDropdown();
              return;
            }

            const items = Array.isArray(json.items) ? json.items : [];
            const normalized = items.map((item) => normalizeChosenSpace(item)).filter(Boolean);
            renderSpaceDropdown(normalized);
          } catch (e) {
            if (e?.name === 'AbortError') return;
            console.error(e);
            toast('Ошибка поиска');
            closeSpaceDropdown();
          }
        }

        async function refreshChosenSpaceFromServer() {
          if (!chosenSpace || !SPACE_URL) return;
          try {
            const url = new URL(SPACE_URL, window.location.origin);
            url.searchParams.set('id', String(chosenSpace.id));
            const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            if (!res.ok || !json || json.ok !== true || !json.found) {
              setChosenSpace(null, { announce: false });
              return;
            }
            const normalized = normalizeChosenSpace(json.item || null);
            if (!normalized) {
              setChosenSpace(null, { announce: false });
              return;
            }
            setChosenSpace(normalized, { announce: false });
          } catch (e) {
            console.error(e);
            setChosenSpace(null, { announce: false });
          }
        }

        if (CAN_EDIT) {
          try {
            const saved = localStorage.getItem(LS_KEY_CHOSEN);
            if (saved) {
              const parsed = JSON.parse(saved);
              const restored = normalizeChosenSpace(parsed);
              if (restored) setChosenSpace(restored, { announce: false });
            }
          } catch {}
        }

        loadLayerModeFromLS();
        updateLegendVisibility();

        function startPdfJs(pdfjsLib, workerSrc) {
          const stage = document.getElementById('stage');
          const canvas = document.getElementById('canvas');
          const overlay = document.getElementById('overlay');
          const shapesSvg = document.getElementById('shapesSvg');
          const drawBox = document.getElementById('drawBox');
          const handlesLayer = document.getElementById('handlesLayer');

          if (!stage || !canvas || !overlay) {
            fallbackToIframe('missing DOM nodes');
            return;
          }

          const ctx = canvas.getContext('2d');
          if (!ctx) {
            fallbackToIframe('no 2d context');
            return;
          }

          pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

          let page = null;
          let scale = 1.0;
          let currentViewport = null;
          let shapes = [];
          let lastHit = null;

          let editMode = false;
          let tool = 'select';

          let selectedShapeId = null;
          let activeVertexIndex = null;
          let draggingVertex = null;
          let draggingVertexMoved = false;

          let polyDrawing = false;
          let polyDraft = [];
          let bootstrappingMap = true;

          const SHAPES_BASE = String(SHAPES_URL || '').replace(/\/$/, '');

          function buildReviewNavItemsFromShapes() {
            const seen = new Set();
            const items = [];

            for (const shape of Array.isArray(shapes) ? shapes : []) {
              const marketSpaceId = Number(shape?.market_space_id || shape?.space_id || 0);
              if (!Number.isFinite(marketSpaceId) || marketSpaceId <= 0) continue;

              const bbox = resolveShapeBbox(shape);
              if (!bbox) continue;

              if (seen.has(marketSpaceId)) continue;
              seen.add(marketSpaceId);

              const normalized = normalizeChosenSpace({
                id: marketSpaceId,
                number: shape?.space_number || '',
                code: shape?.space_code || '',
                tenantName: shape?.space_tenant_name || shape?.tenant_name || null,
                review_status: shape?.space_review_status || '',
                review_status_label: shape?.space_review_status_label || '',
              });

              if (!normalized) continue;

              items.push({
                ...normalized,
                shapeId: Number(shape?.id || 0) || null,
                bbox,
              });
            }

            items.sort(compareReviewNavItems);

            return items;
          }

          function getReviewNavSortLabel(item) {
            const numberLabel = String(item?.number ?? '').trim();
            const displayNameLabel = String(item?.displayName ?? '').trim();
            const codeLabel = String(item?.code ?? '').trim();
            return numberLabel || displayNameLabel || codeLabel || '#' + String(item?.id ?? '');
          }

          function compareReviewNavItems(left, right) {
            const leftLabel = getReviewNavSortLabel(left);
            const rightLabel = getReviewNavSortLabel(right);
            const labelCompare = leftLabel.localeCompare(rightLabel, 'ru', { numeric: true, sensitivity: 'base' });

            if (labelCompare !== 0) {
              return labelCompare;
            }

            return Number(left?.id || 0) - Number(right?.id || 0);
          }

          function getReviewCurrentIndex() {
            if (!chosenSpace || !reviewNavItems.length) return -1;
            return reviewNavItems.findIndex((item) => Number(item.id) === Number(chosenSpace.id));
          }

          function isPendingReviewNavItem(item) {
            return !String(item?.reviewStatus || '').trim();
          }

          function syncReviewNavItemFromSpace(space) {
            const spaceId = Number(space?.id || 0);
            if (!Number.isFinite(spaceId) || spaceId <= 0) {
              return null;
            }

            const reviewIndex = reviewNavItems.findIndex((entry) => Number(entry.id) === spaceId);
            if (reviewIndex < 0) {
              return null;
            }

            const currentItem = reviewNavItems[reviewIndex];
            const nextItem = {
              ...currentItem,
              number: space?.number ?? currentItem.number ?? '',
              displayName: space?.displayName ?? currentItem.displayName ?? '',
              code: space?.code ?? currentItem.code ?? '',
              tenantName: space?.tenantName ?? currentItem.tenantName ?? null,
              reviewStatus: space?.reviewStatus ?? '',
              reviewStatusLabel: space?.reviewStatusLabel ?? '',
            };

            reviewNavItems[reviewIndex] = nextItem;
            return nextItem;
          }

          function getPendingReviewNavCount() {
            return reviewNavItems.reduce((count, item) => count + (isPendingReviewNavItem(item) ? 1 : 0), 0);
          }

          function findNextPendingIndex(currentIndex) {
            if (!reviewNavItems.length) return -1;

            const total = reviewNavItems.length;
            const start = currentIndex >= 0 ? currentIndex + 1 : 0;

            for (let step = 0; step < total; step++) {
              const index = (start + step) % total;
              const item = reviewNavItems[index];
              if (isPendingReviewNavItem(item)) {
                return index;
              }
            }

            return -1;
          }

          async function fetchReviewNavSpaceById(spaceId) {
            if (!SPACE_URL) {
              return null;
            }

            const normalizedSpaceId = Number(spaceId || 0);
            if (!Number.isFinite(normalizedSpaceId) || normalizedSpaceId <= 0) {
              return null;
            }

            try {
              const url = new URL(SPACE_URL, window.location.origin);
              url.searchParams.set('id', String(Math.trunc(normalizedSpaceId)));

              const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
              const json = await res.json();
              if (!res.ok || !json || json.ok !== true || !json.found) {
                return null;
              }

              return normalizeChosenSpace(json.item || null);
            } catch (error) {
              console.error(error);
              return null;
            }
          }

          async function resolveNextPendingReviewTarget(currentIndex, resolveSpace = fetchReviewNavSpaceById) {
            if (!reviewNavItems.length) {
              return null;
            }

            const total = reviewNavItems.length;
            const start = currentIndex >= 0 ? currentIndex + 1 : 0;
            const canValidateAgainstServer = typeof resolveSpace === 'function' && Boolean(SPACE_URL);

            for (let step = 0; step < total; step++) {
              const index = (start + step) % total;
              const item = reviewNavItems[index];
              if (!isPendingReviewNavItem(item)) {
                continue;
              }

              let nextItem = item;

              if (canValidateAgainstServer) {
                const freshSpace = await resolveSpace(Number(item.id || 0));
                if (freshSpace) {
                  nextItem = syncReviewNavItemFromSpace(freshSpace) || {
                    ...item,
                    ...freshSpace,
                  };
                }
              }

              if (isPendingReviewNavItem(nextItem)) {
                return {
                  index,
                  item: nextItem,
                };
              }
            }

            return null;
          }

          updateReviewNavUi = function () {
            const total = reviewNavItems.length;
            const currentIndex = getReviewCurrentIndex();
            const pendingCount = getPendingReviewNavCount();

            if (reviewNavStatus) {
              if (!total) {
                reviewNavStatus.textContent = 'Места не загружены';
              } else if (pendingCount === 0) {
                reviewNavStatus.textContent = 'Непройденных мест не осталось';
              } else if (currentIndex >= 0) {
                reviewNavStatus.textContent = 'Место ' + String(currentIndex + 1) + ' из ' + String(total) + ' · осталось ' + String(pendingCount);
              } else {
                reviewNavStatus.textContent = 'Место — из ' + String(total) + ' · осталось ' + String(pendingCount);
              }
            }

            if (reviewNavPrevBtn) {
              reviewNavPrevBtn.disabled = !total || currentIndex <= 0;
            }

            if (reviewNavNextBtn) {
              reviewNavNextBtn.disabled = !total || (currentIndex >= total - 1 && currentIndex !== -1);
            }

            if (reviewNavNextPendingBtn) {
              reviewNavNextPendingBtn.disabled = findNextPendingIndex(currentIndex) === -1;
            }
          };

          syncReviewNavFromShapes = function () {
            reviewNavItems = buildReviewNavItemsFromShapes();

            if (chosenSpace) {
              const currentReviewItem = reviewNavItems.find((item) => Number(item.id) === Number(chosenSpace.id));
              if (currentReviewItem) {
                setChosenSpace({
                  ...chosenSpace,
                  reviewStatus: currentReviewItem.reviewStatus || '',
                  reviewStatusLabel: currentReviewItem.reviewStatusLabel || '',
                }, { announce: false });
              }
            }

            updateReviewNavUi();
          };

          navigateReview = async function (kind) {
            if (!reviewNavItems.length) {
              updateReviewNavUi();
              return;
            }

            const currentIndex = getReviewCurrentIndex();
            let targetIndex = -1;

            if (kind === 'prev') {
              if (currentIndex > 0) targetIndex = currentIndex - 1;
            } else if (kind === 'next') {
              if (currentIndex === -1) {
                targetIndex = 0;
              } else if (currentIndex < reviewNavItems.length - 1) {
                targetIndex = currentIndex + 1;
              }
            } else if (kind === 'next-pending') {
              const nextPendingTarget = await resolveNextPendingReviewTarget(currentIndex);
              targetIndex = nextPendingTarget ? nextPendingTarget.index : -1;
            }

            if (targetIndex < 0 || targetIndex >= reviewNavItems.length) {
              updateReviewNavUi();
              return;
            }

            const target = reviewNavItems[targetIndex];
            setChosenSpace(target, { announce: false });
            await refreshChosenSpaceFromServer();
            updateReviewNavUi();

            if (!target?.bbox) return;

            await centerOnBbox(target.bbox, { zoomFactor: 1.2 });
            await nextUiFrame();
            await nextUiFrame();

            if (!currentViewport || !canvas || !overlay) return;

            const centerX = (target.bbox.x1 + target.bbox.x2) / 2;
            const centerY = (target.bbox.y1 + target.bbox.y2) / 2;
            const viewportPoint = currentViewport.convertToViewportPoint(centerX, centerY);
            if (!Array.isArray(viewportPoint)) return;

            const rect = canvas.getBoundingClientRect();
            const clientX = rect.left + Number(viewportPoint[0]);
            const clientY = rect.top + Number(viewportPoint[1]);

            overlay.dispatchEvent(new MouseEvent('click', {
              bubbles: true,
              cancelable: true,
              view: window,
              clientX,
              clientY,
            }));
          };

          function normalizeBbox(raw) {
            if (!raw) return null;
            const x1 = Number(raw.x1 ?? raw.bbox_x1 ?? raw.bboxX1);
            const y1 = Number(raw.y1 ?? raw.bbox_y1 ?? raw.bboxY1);
            const x2 = Number(raw.x2 ?? raw.bbox_x2 ?? raw.bboxX2);
            const y2 = Number(raw.y2 ?? raw.bbox_y2 ?? raw.bboxY2);
            if (![x1, y1, x2, y2].every((v) => Number.isFinite(v))) return null;
            return { x1, y1, x2, y2 };
          }

          function isConflictReviewStatus(value) {
            return String(value || '').trim() === 'conflict';
          }

          function bboxFromPolygon(poly) {
            if (!Array.isArray(poly) || poly.length < 3) return null;
            let minX = Infinity;
            let minY = Infinity;
            let maxX = -Infinity;
            let maxY = -Infinity;
            for (const p of poly) {
              const x = Number((p && (p.x ?? p[0])) ?? NaN);
              const y = Number((p && (p.y ?? p[1])) ?? NaN);
              if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
              minX = Math.min(minX, x);
              minY = Math.min(minY, y);
              maxX = Math.max(maxX, x);
              maxY = Math.max(maxY, y);
            }
            if (![minX, minY, maxX, maxY].every((v) => Number.isFinite(v))) return null;
            return { x1: minX, y1: minY, x2: maxX, y2: maxY };
          }

          function resolveShapeBbox(shape) {
            if (!shape) return null;
            const bbox = normalizeBbox(shape);
            if (bbox) return bbox;
            return bboxFromPolygon(shape.polygon);
          }

          function setScaleLabel() {
            if (scaleLabel) scaleLabel.textContent = 'Масштаб: ' + Math.round(scale * 100) + '%';
          }

          function approximateTextWidth(text, fontSize) {
            return String(text || '').length * fontSize * 0.58;
          }

          function splitLabelToTwoLines(text) {
            const value = String(text || '').trim();
            if (!value) return [value];

            const words = value.split(/\s+/).filter(Boolean);
            if (words.length < 2) return [value];

            let bestLines = [value];
            let bestScore = Infinity;

            for (let i = 1; i < words.length; i++) {
              const line1 = words.slice(0, i).join(' ');
              const line2 = words.slice(i).join(' ');
              const score = Math.abs(line1.length - line2.length);

              if (score < bestScore) {
                bestScore = score;
                bestLines = [line1, line2];
              }
            }

            return bestLines;
          }

          function resolveShapeLabelSpec(shape, boxW, boxH) {
            const rawCandidates = [
              shape?.space_display_name,
              shape?.space_number,
              shape?.space_code,
            ];

            const seen = new Set();
            const candidates = rawCandidates
              .map((value) => String(value || '').trim())
              .filter((value) => {
                if (!value || seen.has(value)) return false;
                seen.add(value);
                return true;
              });

            if (!candidates.length) return null;

            const fontSizes = [11, 10, 9, 8];
            const pad = 6;

            for (const candidate of candidates) {
              const lineOptions = [[candidate]];
              const splitLines = splitLabelToTwoLines(candidate);
              if (splitLines.length > 1) {
                lineOptions.push(splitLines);
              }

              for (const lines of lineOptions) {
                for (const fontSize of fontSizes) {
                  const lineHeight = Math.ceil(fontSize * 1.18);
                  const maxLineWidth = Math.max(...lines.map((line) => approximateTextWidth(line, fontSize)));
                  const totalHeight = lines.length * lineHeight;

                  if (boxW >= maxLineWidth + pad * 2 && boxH >= totalHeight + pad * 2) {
                    return { lines, fontSize, lineHeight };
                  }
                }
              }
            }

            return null;
          }

          function setHint(text) {
            if (!editHint) return;
            editHint.textContent = text;
          }

          function clearHandles() {
            if (!handlesLayer) return;
            handlesLayer.innerHTML = '';
            activeVertexIndex = null;
          }

          function findShapeById(id) {
            const n = Number(id);
            if (!Number.isFinite(n) || n <= 0) return null;
            return shapes.find(s => Number(s.id) === n) || null;
          }

          function setSelectedShape(id) {
            const n = id ? Number(id) : 0;
            selectedShapeId = (Number.isFinite(n) && n > 0) ? Math.trunc(n) : null;
            activeVertexIndex = null;
            redrawShapes();
            renderHandles();
          }

          function setTool(next) {
            tool = next;

            if (toolSelectBtn) toolSelectBtn.classList.toggle('is-active', tool === 'select');
            if (toolRectBtn) toolRectBtn.classList.toggle('is-active', tool === 'rect');
            if (toolPolyBtn) toolPolyBtn.classList.toggle('is-active', tool === 'poly');

            if (tool !== 'select') {
              setSelectedShape(null);
              hidePopover();
            }

            if (tool === 'poly') {
              moved = false;
              isDown = false;
              overlay.classList.remove('grabbing');
              stage.classList.remove('grabbing');

              polyDrawing = true;
              polyDraft = [];
              setHint('Полигон: клик — точка • Enter/клик по первой — сохранить • Backspace — назад • Esc — отмена');
              toast('Полигон: добавляй точки кликом');
            } else {
              polyDrawing = false;
              polyDraft = [];
              if (tool === 'rect') {
                setHint('Прямоугольник: Shift+drag — создать • клик — карточка');
              } else {
                setHint('Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить');
              }
              redrawShapes();
            }
          }

          async function loadShapes() {
            try {
              const url = new URL(SHAPES_URL, window.location.origin);
              url.searchParams.set('page', String(MAP_PAGE || 1));
              url.searchParams.set('version', String(MAP_VERSION || 1));

              const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
              const json = await res.json();

              shapes = (json && json.ok === true && Array.isArray(json.items)) ? json.items : [];
              syncReviewNavFromShapes();
              updateRentLegend(shapes);
            } catch (e) {
              console.error(e);
              shapes = [];
              syncReviewNavFromShapes();
              updateRentLegend([]);
            }
          }

          function redrawShapes() {
            if (!shapesSvg || !currentViewport) return;

            shapesSvg.setAttribute('width', String(canvas.width));
            shapesSvg.setAttribute('height', String(canvas.height));
            shapesSvg.setAttribute('viewBox', '0 0 ' + canvas.width + ' ' + canvas.height);

            const parts = [];
            parts.push(
              '<defs>' +
              '<pattern id="unlinkedHatch" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)">' +
              '<line x1="0" y1="0" x2="0" y2="8" stroke="#94a3b8" stroke-width="2" stroke-opacity="0.85"></line>' +
              '</pattern>' +
              '<pattern id="conflictHatch" patternUnits="userSpaceOnUse" width="10" height="10">' +
              '<path d="M 0 0 L 0 10" stroke="#dc2626" stroke-width="1.8" stroke-opacity="0.45"></path>' +
              '<path d="M 5 0 L 5 10" stroke="#1f2937" stroke-width="1.2" stroke-opacity="0.18"></path>' +
              '<path d="M 0 0 L 10 0" stroke="#dc2626" stroke-width="1.2" stroke-opacity="0.18"></path>' +
              '</pattern>' +
              '</defs>'
            );
            const BORDER_COLOR = '#064e3b';
            const BORDER_WIDTH_BASE = 2.5;
            const rentLayerStats = buildRentLayerStats(shapes);
            const rentRateColors = {
              low: '#fef3c7',
              mid: '#fbbf24',
              high: '#f97316',
              top: '#dc2626',
              none: '#cbd5e1',
            };

            const selected = selectedShapeId ? findShapeById(selectedShapeId) : null;

            for (const s of shapes) {
              const poly = Array.isArray(s.polygon) ? s.polygon : [];
              if (poly.length < 3) continue;

              const viewportPoints = poly.map((p) => {
                const x = (p && (p.x ?? p[0])) ?? null;
                const y = (p && (p.y ?? p[1])) ?? null;
                if (x === null || y === null) return null;

                const v = currentViewport.convertToViewportPoint(Number(x), Number(y));
                const vx = Array.isArray(v) ? v[0] : null;
                const vy = Array.isArray(v) ? v[1] : null;

                if (vx === null || vy === null) return null;
                return { x: Number(vx), y: Number(vy) };
              }).filter(Boolean);

              const pts = viewportPoints
                .map((p) => Number(p.x).toFixed(2) + ',' + Number(p.y).toFixed(2))
                .join(' ');

              if (!pts) continue;

              const metaValue = s.meta ?? null;
              let meta = null;
              if (metaValue && typeof metaValue === 'object') {
                meta = metaValue;
              } else if (typeof metaValue === 'string') {
                try {
                  meta = JSON.parse(metaValue);
                } catch (e) {
                  meta = null;
                }
              }

              const isLinked = !!s.market_space_id;
              const isImportedOverlay = Boolean(meta && meta.import_source);
              const isNormalLinked = isLinked && !isImportedOverlay;

              // Проверяем состояние места
              const hasSpace = isLinked;
              const hasTenant = hasSpace && (s.space_tenant_id !== null && s.space_tenant_id !== undefined);
              const debtStatus = typeof s.debt_status === 'string' ? s.debt_status : null;
              const debtScope = typeof s.debt_status_scope === 'string' ? s.debt_status_scope : 'none';
              const reviewStatus = typeof s.space_review_status === 'string' ? s.space_review_status : '';
              const showReviewMarkers = currentScenario === 'review';
              const isConflictReview = showReviewMarkers && hasSpace && isConflictReviewStatus(reviewStatus);
              const isTenantFallbackScope = showReviewMarkers && hasSpace && debtScope === 'tenant_fallback';
              const isCombinedReviewMarker = isConflictReview && isTenantFallbackScope;
              const rentRateBand = getRentRateBand(s.space_rent_rate_value, rentLayerStats);
              
              // Цвета для debt status
              const debtColors = {
                green: '#22c55e',
                pending: '#22c55e',
                orange: '#f59e0b',
                red: {
                  space: '#b91c1c',
                  tenant_fallback: '#ef4444',
                  default: '#dc2626',
                },
                gray: '#94a3b8',
              };

              // Определяем тип отрисовки
              let fillStyle = 'normal'; // normal, debt, rent, rent-missing, vacant, unlinked
              let debtFill = null;
              let rentFill = null;
              
              if (!hasSpace) {
                // Shape без market_space_id — разметка без привязки
                fillStyle = 'unlinked';
              } else if (!hasTenant) {
                // Место есть, но арендатора нет — свободно
                fillStyle = 'vacant';
              } else if (currentLayer === 'rent') {
                rentFill = rentRateColors[rentRateBand] || rentRateColors.none;
                fillStyle = rentRateBand === 'none' ? 'rent-missing' : 'rent';
              } else if (debtStatus && debtColors[debtStatus]) {
                // Есть арендатор и debt_status — используем debt цвет
                if (debtStatus === 'red') {
                  const redPalette = debtColors.red;
                  debtFill = redPalette[debtScope] || redPalette.default;
                } else {
                  debtFill = debtColors[debtStatus];
                }
                fillStyle = 'debt';
              } else {
                // Место с арендатором, но нет debt_status — normal
                fillStyle = 'normal';
              }
              
              // Применяем стили
              let fill = null;
              let stroke = BORDER_COLOR;
              let strokeDasharray = null;
              let fo = 0.12;
              
              if (fillStyle === 'unlinked') {
                // Разметка без привязки: штриховка 45 градусов и нейтральная обводка
                fill = 'url(#unlinkedHatch)';
                stroke = '#94a3b8';
                fo = 1;
              } else if (fillStyle === 'vacant') {
                // Свободно: плотная светло-серая заливка, чтобы не просвечивала подложка
                fill = '#e5e7eb';
                stroke = '#94a3b8';
                fo = 0.92;
              } else if (fillStyle === 'debt') {
                // Debt status: закрашиваем цветом долга
                fill = debtFill;
                stroke = BORDER_COLOR;
                fo = 1;
              } else if (fillStyle === 'rent' || fillStyle === 'rent-missing') {
                // Слой ставок: чем выше ставка, тем теплее цвет
                fill = rentFill;
                stroke = BORDER_COLOR;
                fo = 0.96;
              } else {
                // Normal: обычная заливка
                fill = s.fill_color || '#00A3FF';
                stroke = BORDER_COLOR;
                fo = typeof s.fill_opacity === 'number' ? s.fill_opacity : 0.12;
              }

              if (showReviewMarkers) {
                fill = '#dfe7ef';
                stroke = '#94a3b8';
                fo = 1;
              }
              
              const sw = BORDER_WIDTH_BASE;

              const isSel = selected && Number(selected.id) === Number(s.id);

              const strokeDashAttr = strokeDasharray ? (' stroke-dasharray="' + strokeDasharray + '"') : '';

              parts.push(
                '<polygon points="' + pts +
                '" fill="' + fill +
                '" fill-opacity="' + (isSel ? Math.min(1, fo + 0.08) : fo) +
                '" stroke="' + stroke +
                '" stroke-opacity="1"' +
                strokeDashAttr +
                ' stroke-width="' + (isSel ? (sw + 1.0) : sw) +
                '"></polygon>'
              );

              if (isCombinedReviewMarker) {
                parts.push(
                  '<polygon points="' + pts +
                  '" fill="#7dd3fc" fill-opacity="1"' +
                  '" stroke="#0284c7" stroke-opacity="' + (isSel ? '1' : '0.94') +
                  '" stroke-width="' + (isSel ? (sw + 1.6) : 2.8) +
                  '"></polygon>'
                );
                parts.push(
                  '<polygon points="' + pts +
                  '" fill="url(#conflictHatch)" fill-opacity="1"' +
                  '" stroke="#b45309" stroke-opacity="' + (isSel ? '1' : '0.96') +
                  '" stroke-width="' + (isSel ? (sw + 0.5) : 1.4) +
                  '"></polygon>'
                );
              } else if (isConflictReview) {
                parts.push(
                  '<polygon points="' + pts +
                  '" fill="url(#conflictHatch)" fill-opacity="1"' +
                  '" stroke="#b45309" stroke-opacity="' + (isSel ? '0.98' : '0.9') +
                  '" stroke-width="' + (isSel ? (sw + 1.1) : 2.1) +
                  '"></polygon>'
                );
              } else if (isTenantFallbackScope) {
                parts.push(
                  '<polygon points="' + pts +
                  '" fill="#7dd3fc" fill-opacity="1"' +
                  '" stroke="#0284c7" stroke-opacity="' + (isSel ? '0.96' : '0.88') +
                  '" stroke-width="' + (isSel ? (sw + 1.0) : 2.0) +
                  '"></polygon>'
                );
              }

              if (viewportPoints.length >= 3) {
                let minX = Infinity;
                let minY = Infinity;
                let maxX = -Infinity;
                let maxY = -Infinity;

                for (const p of viewportPoints) {
                  minX = Math.min(minX, p.x);
                  minY = Math.min(minY, p.y);
                  maxX = Math.max(maxX, p.x);
                  maxY = Math.max(maxY, p.y);
                }

                const boxW = maxX - minX;
                const boxH = maxY - minY;
                const labelSpec = resolveShapeLabelSpec(s, boxW, boxH);

                if (labelSpec) {
                  const cx = (minX + maxX) / 2;
                  const cy = (minY + maxY) / 2;
                  const offsetBase = ((labelSpec.lines.length - 1) * labelSpec.lineHeight) / 2;

                  labelSpec.lines.forEach((line, index) => {
                    const y = cy - offsetBase + (index * labelSpec.lineHeight);
                    parts.push(
                      '<text x="' + cx.toFixed(2) +
                      '" y="' + y.toFixed(2) +
                      '" text-anchor="middle" dominant-baseline="middle"' +
                      ' font-size="' + labelSpec.fontSize +
                      '" fill="#0f172a" opacity="0.9">' +
                      escapeHtml(line) +
                      '</text>'
                    );
                  });
                }
              }
            }

            if (polyDrawing && Array.isArray(polyDraft) && polyDraft.length > 0) {
              const pts = polyDraft.map((p) => {
                const v = currentViewport.convertToViewportPoint(Number(p.x), Number(p.y));
                if (!Array.isArray(v)) return null;
                return Number(v[0]).toFixed(2) + ',' + Number(v[1]).toFixed(2);
              }).filter(Boolean).join(' ');

              if (pts) {
                parts.push(
                  '<polyline points="' + pts + '" fill="none" stroke="#00A3FF" stroke-width="2" stroke-dasharray="6 6" opacity="0.95"></polyline>'
                );
                for (const p of polyDraft) {
                  const v = currentViewport.convertToViewportPoint(Number(p.x), Number(p.y));
                  if (!Array.isArray(v)) continue;
                  const cx = Number(v[0]).toFixed(2);
                  const cy = Number(v[1]).toFixed(2);
                  parts.push('<circle cx="' + cx + '" cy="' + cy + '" r="4" fill="#00A3FF" fill-opacity="0.95" stroke="#fff" stroke-width="2"></circle>');
                }
              }
            }

            shapesSvg.innerHTML = parts.join('');
          }

          redrawShapesRef = redrawShapes;

          function getCanvasPointFromClient(clientX, clientY) {
            const rect = canvas.getBoundingClientRect();
            return { x: clientX - rect.left, y: clientY - rect.top };
          }

          function renderHandles() {
            if (!handlesLayer) return;
            handlesLayer.innerHTML = '';

            if (!editMode || tool !== 'select') return;
            if (!selectedShapeId) return;
            if (!currentViewport) return;

            const shape = findShapeById(selectedShapeId);
            if (!shape) return;

            const poly = Array.isArray(shape.polygon) ? shape.polygon : [];
            if (poly.length < 3) return;

            for (let i = 0; i < poly.length; i++) {
              const p = poly[i];
              const x = (p && (p.x ?? p[0])) ?? null;
              const y = (p && (p.y ?? p[1])) ?? null;
              if (x === null || y === null) continue;

              const v = currentViewport.convertToViewportPoint(Number(x), Number(y));
              if (!Array.isArray(v)) continue;

              const el = document.createElement('div');
              el.className = 'handleDot' + (activeVertexIndex === i ? ' active' : '');
              el.style.left = Number(v[0]).toFixed(2) + 'px';
              el.style.top = Number(v[1]).toFixed(2) + 'px';
              el.dataset.shapeId = String(shape.id);
              el.dataset.index = String(i);

              el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const sid = Number(el.dataset.shapeId || 0);
                const idx = Number(el.dataset.index || -1);
                if (!Number.isFinite(sid) || sid <= 0) return;
                if (!Number.isFinite(idx) || idx < 0) return;

                activeVertexIndex = idx;
                draggingVertex = { shapeId: sid, index: idx };
                draggingVertexMoved = false;

                el.classList.add('active');
              });

              handlesLayer.appendChild(el);
            }
          }

          async function centerOnBbox(bbox, opts = {}) {
            if (!bbox || !page || !currentViewport || !stage) return;

            const padding = Number(opts.padding ?? 40);
            const zoomFactor = Number(opts.zoomFactor ?? 1.15);

            const w = Math.max(1, Math.abs(bbox.x2 - bbox.x1));
            const h = Math.max(1, Math.abs(bbox.y2 - bbox.y1));

            const availableW = Math.max(200, stage.clientWidth - padding);
            const availableH = Math.max(200, stage.clientHeight - padding);

            let nextScale = Math.min(availableW / w, availableH / h) * zoomFactor;
            if (!Number.isFinite(nextScale) || nextScale <= 0) {
              nextScale = 1.0;
            }

            nextScale = Math.max(0.2, Math.min(7, nextScale));

            scale = nextScale;
            await render();

            const centerX = (bbox.x1 + bbox.x2) / 2;
            const centerY = (bbox.y1 + bbox.y2) / 2;

            const v = currentViewport.convertToViewportPoint(centerX, centerY);
            if (Array.isArray(v)) {
              stage.scrollLeft = Math.max(0, v[0] - stage.clientWidth / 2);
              stage.scrollTop = Math.max(0, v[1] - stage.clientHeight / 2);
            }
          }

          async function applyInitialFocus() {
            if (!FOCUS_SPACE_ID && !FOCUS_SHAPE) return;

            let targetShape = null;
            let bbox = null;

            if (FOCUS_SHAPE) {
              bbox = normalizeBbox(FOCUS_SHAPE.bbox || FOCUS_SHAPE);
              if (FOCUS_SHAPE.id) {
                targetShape = findShapeById(FOCUS_SHAPE.id);
              }
            }

            if (!bbox && FOCUS_SPACE_ID) {
              targetShape = shapes.find(s => Number(s.market_space_id) === Number(FOCUS_SPACE_ID)) || null;
              bbox = resolveShapeBbox(targetShape);
            }

            if (!bbox) {
              toast('Не удалось найти место на карте');
              return;
            }

            if (targetShape?.id) {
              setSelectedShape(targetShape.id);
            }

            await centerOnBbox(bbox, { zoomFactor: 1.2 });
          }

          async function render() {
            if (!page) return;

            if (bootstrappingMap) {
              setMapLoadProgress(92, 'Отрисовка карты…', 'rendering');
              await nextUiFrame();
            }

            const centerX = stage.scrollLeft + stage.clientWidth / 2;
            const centerY = stage.scrollTop + stage.clientHeight / 2;

            const prevW = canvas.width || 1;
            const prevH = canvas.height || 1;
            const relX = centerX / prevW;
            const relY = centerY / prevH;

            const viewport = page.getViewport({ scale });
            currentViewport = viewport;

            const viewportWidth = Math.floor(viewport.width);
            const viewportHeight = Math.floor(viewport.height);

            canvas.width = viewportWidth;
            canvas.height = viewportHeight;
            canvas.style.width = viewportWidth + 'px';
            canvas.style.height = viewportHeight + 'px';

            if (canvasWrap) {
              canvasWrap.style.width = viewportWidth + 'px';
              canvasWrap.style.height = viewportHeight + 'px';
            }

            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.restore();

            await page.render({ canvasContext: ctx, viewport }).promise;

            stage.scrollLeft = Math.max(0, relX * canvas.width - stage.clientWidth / 2);
            stage.scrollTop  = Math.max(0, relY * canvas.height - stage.clientHeight / 2);

            setScaleLabel();
            redrawShapes();
            renderHandles();

            if (bootstrappingMap) {
              setMapLoadProgress(96, 'Подготовка слоя мест…', 'rendering');
              await nextUiFrame();
            }
          }

          async function fitWidth() {
            if (!page) return;
            const viewport = page.getViewport({ scale: 1.0 });
            const padding = 24;
            const available = Math.max(200, stage.clientWidth - padding);
            scale = available / viewport.width;
            await render();
          }

          async function createShape(pdfPolygon) {
            const msId = getChosenSpaceId();
            const hasTyped = spaceSearchInput && String(spaceSearchInput.value || '').trim().length > 0;
            if (!msId && hasTyped) {
              toast('Выбери место из списка');
            }

            const payload = {
              page: MAP_PAGE || 1,
              version: MAP_VERSION || 1,
              polygon: pdfPolygon,
            };
            if (msId) payload.market_space_id = msId;

            const res = await apiFetch(SHAPES_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });

            let json = null;
            try { json = await res.json(); } catch { json = null; }

            if (!res.ok || !json || json.ok !== true) {
              const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
              throw new Error(msg);
            }

            await loadShapes();
            redrawShapes();
            renderHandles();
            toast(msId ? ('Разметка сохранена (ID ' + String(msId) + ')') : 'Разметка сохранена без привязки — выбери место и привяжи');
          }

          async function patchShape(shapeId, payload) {
            const id = Number(shapeId);
            if (!Number.isFinite(id) || id <= 0) throw new Error('Bad shape id');

            const url = String(SHAPES_BASE + '/' + String(Math.trunc(id)));

            const res = await apiFetch(url, {
              method: 'PATCH',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });

            let json = null;
            try { json = await res.json(); } catch { json = null; }

            if (!res.ok || !json || json.ok !== true) {
              const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
              throw new Error(msg);
            }

            return json.item || null;
          }

          async function deleteShape(shapeId) {
            const id = Number(shapeId);
            if (!Number.isFinite(id) || id <= 0) return;

            const ok = confirm('Удалить этот полигон?');
            if (!ok) return;

            const url = String(SHAPES_BASE + '/' + String(Math.trunc(id)));
            const res = await apiFetch(url, { method: 'DELETE' });

            let json = null;
            try { json = await res.json(); } catch { json = null; }

            if (!res.ok || !json || json.ok !== true) {
              const msg = escapeHtml(json?.message || ('HTTP ' + res.status));
              throw new Error(msg);
            }

            if (selectedShapeId === Math.trunc(id)) {
              setSelectedShape(null);
            }

            await loadShapes();
            redrawShapes();
            renderHandles();
            toast('Полигон удалён');
          }

          function distanceSq(ax, ay, bx, by) {
            const dx = ax - bx;
            const dy = ay - by;
            return dx * dx + dy * dy;
          }

          function distPointToSegSq(px, py, ax, ay, bx, by) {
            const abx = bx - ax;
            const aby = by - ay;
            const apx = px - ax;
            const apy = py - ay;
            const abLenSq = abx*abx + aby*aby;
            if (abLenSq <= 1e-12) return distanceSq(px, py, ax, ay);
            let t = (apx*abx + apy*aby) / abLenSq;
            t = Math.max(0, Math.min(1, t));
            const cx = ax + t * abx;
            const cy = ay + t * aby;
            return distanceSq(px, py, cx, cy);
          }

          async function insertVertexAtClick(xPdf, yPdf) {
            if (!selectedShapeId) return;
            const shape = findShapeById(selectedShapeId);
            if (!shape) return;

            const poly = Array.isArray(shape.polygon) ? [...shape.polygon] : [];
            if (poly.length < 3) return;

            let bestI = -1;
            let bestD = Infinity;

            for (let i = 0; i < poly.length; i++) {
              const a = poly[i];
              const b = poly[(i + 1) % poly.length];

              const ax = Number((a && (a.x ?? a[0])) ?? NaN);
              const ay = Number((a && (a.y ?? a[1])) ?? NaN);
              const bx = Number((b && (b.x ?? b[0])) ?? NaN);
              const by = Number((b && (b.y ?? b[1])) ?? NaN);

              if (!Number.isFinite(ax) || !Number.isFinite(ay) || !Number.isFinite(bx) || !Number.isFinite(by)) continue;

              const d = distPointToSegSq(xPdf, yPdf, ax, ay, bx, by);
              if (d < bestD) {
                bestD = d;
                bestI = i;
              }
            }

            if (bestI < 0) return;

            poly.splice(bestI + 1, 0, { x: Number(xPdf), y: Number(yPdf) });

            shape.polygon = poly;
            redrawShapes();
            renderHandles();

            try {
              await patchShape(shape.id, { polygon: poly });
              await loadShapes();
              redrawShapes();
              renderHandles();
              toast('Точка добавлена');
            } catch (e) {
              console.error(e);
              toast('Ошибка добавления точки: ' + String(e?.message || e));
              await loadShapes();
              redrawShapes();
              renderHandles();
            }
          }

          async function finishPolygon() {
            if (!polyDrawing) return;

            if (!Array.isArray(polyDraft) || polyDraft.length < 3) {
              toast('Нужно минимум 3 точки');
              return;
            }

            const poly = polyDraft.map(p => ({ x: Number(p.x), y: Number(p.y) }));

            polyDrawing = false;
            polyDraft = [];
            redrawShapes();
            renderHandles();

            createShape(poly).catch((err) => {
              console.error(err);
              toast('Ошибка сохранения: ' + String(err?.message || err));
            });
          }

          function cancelPolygon() {
            polyDrawing = false;
            polyDraft = [];
            redrawShapes();
            renderHandles();
            toast('Полигон отменён');
          }


          function syncChosenSpaceReview(item) {
            if (!item) {
              return;
            }

            const reviewedSpaceId = Number(item.market_space_id || 0);
            if (!Number.isFinite(reviewedSpaceId) || reviewedSpaceId <= 0) {
              return;
            }

            if (chosenSpace && reviewedSpaceId === Number(chosenSpace.id)) {
              setChosenSpace({
                ...chosenSpace,
                reviewStatus: item.review_status || '',
                reviewStatusLabel: item.review_status_label || '',
              }, { announce: false });
            }

            const reviewIndex = reviewNavItems.findIndex((entry) => Number(entry.id) === reviewedSpaceId);
            if (reviewIndex >= 0) {
              reviewNavItems[reviewIndex] = {
                ...reviewNavItems[reviewIndex],
                reviewStatus: item.review_status || '',
                reviewStatusLabel: item.review_status_label || '',
              };
            }

            updateReviewNavUi();
          }


          function reviewDecisionSuccessMessage(decision) {
            switch (decision) {
              case 'matched':
                return 'Ревизия: совпадение отмечено';
              case 'mark_space_free':
                return 'Ревизия: место отмечено как свободное';
              case 'mark_space_service':
                return 'Ревизия: место отмечено как служебное';
              case 'fix_space_identity':
                return 'Ревизия: данные места уточнены';
              case 'occupancy_conflict':
                return 'Ревизия: конфликт отправлен в observed';
              case 'tenant_changed_on_site':
                return 'Ревизия: смена арендатора зафиксирована';
              case 'shape_not_found':
                return 'Ревизия: место отмечено как не найденное';
              case 'bind_shape_to_space':
                return 'Ревизия: фигура привязана к месту';
              case 'unbind_shape_from_space':
                return 'Ревизия: фигура отвязана от места';
              default:
                return 'Ревизия обновлена';
            }
          }

          async function submitReviewDecision(decision, options = {}) {
            if (!REVIEW_DECISION_URL) {
              toast('Нет endpoint для ревизии');
              return;
            }

            const sourceHit = options.hit || lastHit || null;
            const sourceSpace = options.space || sourceHit?.space || chosenSpace || null;
            const marketSpaceId = Number(
              options.marketSpaceId
              || sourceSpace?.id
              || sourceHit?.market_space_id
              || chosenSpace?.id
              || 0
            );

            if (!Number.isFinite(marketSpaceId) || marketSpaceId <= 0) {
              toast('Сначала выбери место для ревизии');
              return;
            }

            const payload = {
              decision,
              market_space_id: Math.trunc(marketSpaceId),
            };

            const shapeId = Number(options.shapeId || sourceHit?.shape_id || 0);
            if (Number.isFinite(shapeId) && shapeId > 0 && ['bind_shape_to_space', 'unbind_shape_from_space'].includes(decision)) {
              payload.shape_id = Math.trunc(shapeId);
            }

            if (decision === 'occupancy_conflict' || decision === 'shape_not_found') {
              const reason = window.prompt('Комментарий для ревизии', '');
              if (!reason || !String(reason).trim()) {
                return;
              }
              payload.reason = String(reason).trim();
            }

            if (decision === 'tenant_changed_on_site') {
              const observedTenant = window.prompt('Фактический арендатор', options.observedTenantName || '');
              if (!observedTenant || !String(observedTenant).trim()) {
                return;
              }

              const reason = window.prompt('Комментарий для ревизии', '');
              if (!reason || !String(reason).trim()) {
                return;
              }

              payload.observed_tenant_name = String(observedTenant).trim();
              payload.reason = String(reason).trim();
            }

            if (decision === 'fix_space_identity') {
              const identityValue = typeof options.identityValue === 'string'
                ? String(options.identityValue).trim()
                : '';

              if (identityValue) {
                payload.number = identityValue;
                payload.display_name = identityValue;
              } else {
                const currentNumber = sourceSpace?.number ? String(sourceSpace.number) : '';
                const currentDisplayName = sourceSpace?.display_name ? String(sourceSpace.display_name) : '';
                const nextNumber = window.prompt('Номер места', currentNumber);
                const nextDisplayName = window.prompt('Название места', currentDisplayName);

                if ((!nextNumber || !String(nextNumber).trim()) && (!nextDisplayName || !String(nextDisplayName).trim())) {
                  toast('Нужен номер или название места');
                  return;
                }

                if (nextNumber && String(nextNumber).trim()) {
                  payload.number = String(nextNumber).trim();
                }

                if (nextDisplayName && String(nextDisplayName).trim()) {
                  payload.display_name = String(nextDisplayName).trim();
                }
              }
            }

            const res = await apiFetch(REVIEW_DECISION_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });

            let json = null;
            try {
              json = await res.json();
            } catch (error) {
              json = null;
            }

            if (!res.ok || !json || json.ok !== true) {
              toast(String(json?.message || 'Не удалось сохранить ревизию'));
              return;
            }

            if (json.progress) {
              updateReviewProgress(json.progress);
            }

            if (json.item) {
              syncChosenSpaceReview(json.item);
            }

            await loadShapes();
            redrawShapes();
            renderHandles();

            hidePopover();
            toast(reviewDecisionSuccessMessage(decision));
          }

          async function init() {

            const loadingTask = pdfjsLib.getDocument(PDF_URL);
            setMapLoadProgress(8, 'Загрузка карты…', 'loading');
            loadingTask.onProgress = (progressData) => {
              const loaded = Number(progressData?.loaded || 0);
              const total = Number(progressData?.total || 0);
              if (Number.isFinite(total) && total > 0) {
                const ratio = Math.max(0, Math.min(1, loaded / total));
                const percent = Math.round(8 + ratio * 60);
                setMapLoadProgress(percent, 'Загрузка PDF: ' + percent + '%', 'loading');
              } else {
                setMapLoadProgress(24, 'Загрузка карты…', 'loading');
              }
            };
            const shapesPromise = loadShapes()
              .then(() => {
                redrawShapes();
              })
              .catch((err) => {
                console.error(err);
              });
            const pdfDoc = await loadingTask.promise;
            setMapLoadProgress(84, 'PDF загружен, подготовка страницы…', 'rendering');
            await nextUiFrame();
            const requestedPage = MAP_PAGE || 1;
            try {
              page = await pdfDoc.getPage(requestedPage);
            } catch (e) {
              console.error(e);
              page = await pdfDoc.getPage(1);
              if (requestedPage !== 1) {
                toast('Страница карты не найдена, показана первая');
              }
            }

            await fitWidth();
            bootstrappingMap = false;
            completeMapLoadProgress();

            const postBootstrapTasks = [];

            if (FOCUS_SPACE_ID || FOCUS_SHAPE) {
              postBootstrapTasks.push((async () => {
                await shapesPromise;
                await applyInitialFocus();
              })());
            }

            if (CAN_EDIT) {
              postBootstrapTasks.push(refreshChosenSpaceFromServer());
            }

            if (postBootstrapTasks.length) {
              Promise.allSettled(postBootstrapTasks).catch((err) => {
                console.error(err);
              });
            }

            toast('Клик по месту откроет карточку.');
          }

          zoomInBtn?.addEventListener('click', async () => { scale = Math.min(7, scale * 1.2); await render(); });
          zoomOutBtn?.addEventListener('click', async () => { scale = Math.max(0.2, scale / 1.2); await render(); });
          zoomResetBtn?.addEventListener('click', async () => { scale = 1.0; await render(); });
          fitWidthBtn?.addEventListener('click', async () => { await fitWidth(); });
          layerDebtBtn?.addEventListener('click', () => setLayerMode('debt'));
          layerRentBtn?.addEventListener('click', () => setLayerMode('rent'));
          scenarioMapBtn?.addEventListener('click', () => setScenario('map'));
          scenarioReviewBtn?.addEventListener('click', () => setScenario('review'));

          if (CAN_EDIT && toggleEditBtn) {
            toggleEditBtn.addEventListener('click', async () => {
              if (isReviewMode()) {
                return;
              }

              editMode = !editMode;
              isEditMode = editMode;

              syncEditToggleUi();

              if (toolSelectBtn) toolSelectBtn.style.display = editMode ? 'inline-flex' : 'none';
              if (toolRectBtn) toolRectBtn.style.display = editMode ? 'inline-flex' : 'none';
              if (toolPolyBtn) toolPolyBtn.style.display = editMode ? 'inline-flex' : 'none';

              updateScenarioUi();

              if (editMode) {
                setTool('select');
                setHint('Редактировать: клик — выбрать • тащи точки • Alt+клик — вставить вершину • Delete — удалить');
                toast('Разметка включена');
              } else {
                cancelPolygon();
                setSelectedShape(null);
                clearHandles();
                hidePopover();
                toast('Разметка выключена');
              }
            });
          }

          syncEditToggleUi();
          updateScenarioUi();

          if (CAN_EDIT && toolSelectBtn) toolSelectBtn.addEventListener('click', () => { if (editMode) setTool('select'); });
          if (CAN_EDIT && toolRectBtn) toolRectBtn.addEventListener('click', () => { if (editMode) setTool('rect'); });
          if (CAN_EDIT && toolPolyBtn) toolPolyBtn.addEventListener('click', () => { if (editMode) setTool('poly'); });

          if (CAN_EDIT && spaceSearchInput) {
            spaceSearchInput.addEventListener('input', () => {
              const currentValue = String(spaceSearchInput.value || '').trim();
              if (currentValue && chosenSpace) {
                setChosenSpace(null, { announce: false });
              }
              scheduleSpaceSearch();
            });
            spaceSearchInput.addEventListener('keydown', (e) => {
              if (e.key === 'Escape') {
                closeSpaceDropdown();
                return;
              }
              if (!spaceDropdown || spaceDropdown.style.display !== 'block') return;
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                searchIndex = Math.min(searchResults.length - 1, searchIndex + 1);
                updateDropdownActive();
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                searchIndex = Math.max(0, searchIndex - 1);
                updateDropdownActive();
              } else if (e.key === 'Enter') {
                e.preventDefault();
                const item = searchResults[searchIndex];
                if (item) {
                  setChosenSpace(item, { announce: true });
                  closeSpaceDropdown();
                } else if (String(spaceSearchInput.value || '').trim()) {
                  toast('Выбери место из списка');
                }
              }
            });
          }

          spaceChosenPill?.addEventListener('click', (e) => {
            const target = e.target;
            if (!(target instanceof HTMLElement)) return;
            if (target.getAttribute('data-action') === 'clear-chosen') {
              setChosenSpace(null, { announce: false });
              if (spaceSearchInput) spaceSearchInput.value = '';
              closeSpaceDropdown();
              toast('Выбор сброшен');
            }
          });

          reviewNavPrevBtn?.addEventListener('click', () => {
            navigateReview('prev').catch((err) => {
              console.error(err);
              toast('Не удалось перейти к предыдущему месту');
            });
          });

          reviewNavNextPendingBtn?.addEventListener('click', () => {
            navigateReview('next-pending').catch((err) => {
              console.error(err);
              toast('Не удалось перейти к следующему непройденному месту');
            });
          });

          reviewNavNextBtn?.addEventListener('click', () => {
            navigateReview('next').catch((err) => {
              console.error(err);
              toast('Не удалось перейти к следующему месту');
            });
          });

          document.addEventListener('click', (e) => {
            if (!spacePicker || !spaceDropdown) return;
            if (spacePicker.contains(e.target)) return;
            closeSpaceDropdown();
          });

          reviewNotFoundBtn?.addEventListener('click', () => {
            if (!chosenSpace) {
              toast('??????? ?????? ????? ??? ???????');
              return;
            }

            submitReviewDecision('shape_not_found', {
              marketSpaceId: chosenSpace.id,
              space: chosenSpace,
            }).catch((err) => {
              console.error(err);
              toast(String(err?.message || err));
            });
          });

          let isDown = false;
          let moved = false;
          let startX = 0, startY = 0, startLeft = 0, startTop = 0;
          const MOVE_THRESHOLD = 6;

          let drawingRect = false;
          let drawStart = null;

          function showDrawBox(x1, y1, x2, y2) {
            if (!drawBox) return;
            const left = Math.min(x1, x2);
            const top = Math.min(y1, y2);
            const w = Math.abs(x2 - x1);
            const h = Math.abs(y2 - y1);

            drawBox.style.left = left.toFixed(1) + 'px';
            drawBox.style.top = top.toFixed(1) + 'px';
            drawBox.style.width = w.toFixed(1) + 'px';
            drawBox.style.height = h.toFixed(1) + 'px';
            drawBox.style.display = 'block';
          }

          function hideDrawBox() {
            if (!drawBox) return;
            drawBox.style.display = 'none';
          }

          function onDown(e) {
            if (draggingVertex) return;

            if (CAN_EDIT && editMode && tool === 'rect' && e.shiftKey) {
              drawingRect = true;
              drawStart = getCanvasPointFromClient(e.clientX, e.clientY);
              showDrawBox(drawStart.x, drawStart.y, drawStart.x, drawStart.y);
              e.preventDefault();
              return;
            }

            if (CAN_EDIT && editMode && tool === 'poly') return;

            isDown = true;
            moved = false;

            overlay.classList.add('grabbing');
            stage.classList.add('grabbing');

            startX = e.clientX;
            startY = e.clientY;
            startLeft = stage.scrollLeft;
            startTop = stage.scrollTop;

            e.preventDefault();
          }

          function onUp(e) {
            if (drawingRect) {
              drawingRect = false;

              const end = getCanvasPointFromClient(e.clientX, e.clientY);
              const s = drawStart;
              drawStart = null;

              hideDrawBox();

              if (!page || !currentViewport || !s) return;

              const left = Math.min(s.x, end.x);
              const top = Math.min(s.y, end.y);
              const right = Math.max(s.x, end.x);
              const bottom = Math.max(s.y, end.y);

              const w = right - left;
              const h = bottom - top;

              if (w < 10 || h < 10) {
                toast('Слишком маленькая область');
                return;
              }

              const p1 = currentViewport.convertToPdfPoint(left, top);
              const p2 = currentViewport.convertToPdfPoint(right, top);
              const p3 = currentViewport.convertToPdfPoint(right, bottom);
              const p4 = currentViewport.convertToPdfPoint(left, bottom);

              const poly = [
                { x: Number(p1[0]), y: Number(p1[1]) },
                { x: Number(p2[0]), y: Number(p2[1]) },
                { x: Number(p3[0]), y: Number(p3[1]) },
                { x: Number(p4[0]), y: Number(p4[1]) },
              ];

              createShape(poly).catch((err) => {
                console.error(err);
                toast('Ошибка сохранения: ' + String(err?.message || err));
              });

              return;
            }

            isDown = false;
            overlay.classList.remove('grabbing');
            stage.classList.remove('grabbing');
          }

          function onMove(e) {
            if (draggingVertex && currentViewport) {
              draggingVertexMoved = true;

              const sid = draggingVertex.shapeId;
              const idx = draggingVertex.index;

              const shape = findShapeById(sid);
              if (!shape) return;

              const poly = Array.isArray(shape.polygon) ? [...shape.polygon] : [];
              if (poly.length < 3) return;
              if (idx < 0 || idx >= poly.length) return;

              const pCanvas = getCanvasPointFromClient(e.clientX, e.clientY);
              const pPdf = currentViewport.convertToPdfPoint(pCanvas.x, pCanvas.y);

              const nx = Number(pPdf[0]);
              const ny = Number(pPdf[1]);

              if (!Number.isFinite(nx) || !Number.isFinite(ny)) return;

              poly[idx] = { x: nx, y: ny };

              shape.polygon = poly;
              redrawShapes();
              renderHandles();

              return;
            }

            if (drawingRect) {
              const s = drawStart;
              if (!s) return;
              const p = getCanvasPointFromClient(e.clientX, e.clientY);
              showDrawBox(s.x, s.y, p.x, p.y);
              return;
            }

            if (!isDown) return;

            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            if (!moved && (Math.abs(dx) > MOVE_THRESHOLD || Math.abs(dy) > MOVE_THRESHOLD)) {
              moved = true;
            }

            stage.scrollLeft = startLeft - dx;
            stage.scrollTop = startTop - dy;
          }

          async function onGlobalUp(e) {
            if (!draggingVertex) return;

            const { shapeId } = draggingVertex;
            draggingVertex = null;

            if (!draggingVertexMoved) {
              draggingVertexMoved = false;
              renderHandles();
              return;
            }

            draggingVertexMoved = false;

            const shape = findShapeById(shapeId);
            if (!shape) {
              renderHandles();
              return;
            }

            const poly = Array.isArray(shape.polygon) ? shape.polygon : [];
            if (poly.length < 3) {
              renderHandles();
              return;
            }

            try {
              await patchShape(shape.id, { polygon: poly });
              await loadShapes();
              redrawShapes();
              renderHandles();
              toast('Полигон обновлён');
            } catch (e2) {
              console.error(e2);
              toast('Ошибка обновления: ' + String(e2?.message || e2));
              await loadShapes();
              redrawShapes();
              renderHandles();
            }
          }

          async function onClick(e) {
            e.stopPropagation();

            if (drawingRect) return;
            if (!page || !currentViewport) return;

            const pCanvas = getCanvasPointFromClient(e.clientX, e.clientY);
            const pPdfArr = currentViewport.convertToPdfPoint(pCanvas.x, pCanvas.y);
            const xPdf = Array.isArray(pPdfArr) ? Number(pPdfArr[0]) : 0;
            const yPdf = Array.isArray(pPdfArr) ? Number(pPdfArr[1]) : 0;

            if (CAN_EDIT && editMode && tool === 'poly' && polyDrawing) {
              if (polyDraft.length >= 3) {
                const first = polyDraft[0];
                const v0 = currentViewport.convertToViewportPoint(Number(first.x), Number(first.y));
                if (Array.isArray(v0)) {
                  const d2 = distanceSq(Number(v0[0]), Number(v0[1]), pCanvas.x, pCanvas.y);
                  if (d2 <= 12 * 12) {
                    await finishPolygon();
                    return;
                  }
                }
              }

              polyDraft.push({ x: xPdf, y: yPdf });
              redrawShapes();
              toast('Точка: ' + String(polyDraft.length));
              return;
            }

            if (moved) return;

            if (CAN_EDIT && editMode && tool === 'select' && e.altKey && selectedShapeId) {
              await insertVertexAtClick(xPdf, yPdf);
              return;
            }

            showPopoverAt(
              e.clientX, e.clientY,
              '<div class="t">Поиск…</div><div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>'
            );

            try {
              const url = new URL(HIT_URL, window.location.origin);
              url.searchParams.set('x', String(xPdf));
              url.searchParams.set('y', String(yPdf));
              url.searchParams.set('page', String(MAP_PAGE || 1));
              url.searchParams.set('version', String(MAP_VERSION || 1));

              const res = await apiFetch(url.toString(), { headers: { 'Accept': 'application/json' } });
              const json = await res.json();

              if (!json || json.ok !== true) {
                const msg = escapeHtml(json?.message || 'Ошибка hit-test');
                showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">' + msg + '</div>');
                return;
              }

              if (!json.hit) {
                lastHit = null;
                if (CAN_EDIT && editMode && tool === 'select') {
                  setSelectedShape(null);
                  clearHandles();
                }

                const msg = escapeHtml(json?.message || 'Ничего не найдено');
                showPopoverAt(
                  e.clientX, e.clientY,
                  '<div class="t">Нет попадания</div>' +
                  '<div class="row muted">' + msg + '</div>' +
                  '<div class="row muted">x=' + xPdf.toFixed(1) + ', y=' + yPdf.toFixed(1) + '</div>'
                );
                return;
              }

              const hit = json.hit;
              lastHit = hit;
              const space = hit.space || null;
              const tenant = hit.tenant || null;

              const nextChosen = (space && Number(hit.market_space_id || space.id || 0) > 0)
                ? normalizeChosenSpace({
                    id: Number(hit.market_space_id || space.id || 0),
                    number: space.number || hit.space_number || '',
                    code: space.code || hit.space_code || '',
                    tenantName: tenant?.name || hit.space_tenant_name || null,
                    review_status: space.review_status || hit.space_review_status || '',
                    review_status_label: space.review_status_label || hit.space_review_status_label || '',
                  })
                : null;

              if (nextChosen) {
                setChosenSpace(nextChosen, { announce: false });
              }

              if (CAN_EDIT && editMode && tool === 'select' && hit.shape_id) {
                setSelectedShape(hit.shape_id);
              }

              let title = 'Торговое место';
              let line1 = '';
              let line2 = '';
              let line3 = '';
              let line4 = '';
              let line5 = '';
              let line6 = '';
              let line7 = '';

              if (space) {
                const label = (space.number && String(space.number).trim()) ? String(space.number) : (space.code || '');
                title = label ? ('Место: ' + escapeHtml(label)) : 'Торговое место';
                const metaParts = [];
                if (space.location_name) {
                  metaParts.push('Локация: ' + escapeHtml(space.location_name));
                }
                if (space.area_sqm) {
                  metaParts.push('Площадь: ' + escapeHtml(space.area_sqm) + ' м²');
                }
                line1 = metaParts.join(' • ');

                // Проверяем наличие арендатора
                const hasTenant = hit.space_tenant_id !== null && hit.space_tenant_id !== undefined;
                const storefront = space.display_name ? String(space.display_name).trim() : '';
                const activityType = space.activity_type ? String(space.activity_type).trim() : '';
                const storefrontLabel = storefront || activityType;
                const rentRateValue = space.rent_rate_value !== null && space.rent_rate_value !== undefined ? Number(space.rent_rate_value) : null;
                const rentRateUnit = rentRateUnitLabel(space.rent_rate_unit || '');
                const currentAccrualTotal = space.current_accrual_total !== null && space.current_accrual_total !== undefined ? Number(space.current_accrual_total) : null;
                const currentAccrualPeriod = space.current_accrual_period ? String(space.current_accrual_period) : '';
                const currentAccrualMode = space.current_accrual_mode ? String(space.current_accrual_mode) : '';

                if (!hasTenant) {
                  line2 = 'Свободно';
                  line3 = storefrontLabel ? ('Отдел / вывеска: ' + escapeHtml(storefrontLabel)) : '';
                  line4 = '';
                  if (rentRateValue !== null && Number.isFinite(rentRateValue)) {
                    line5 = 'Ставка аренды: ' + formatMoneyRu(rentRateValue) + (rentRateUnit ? ' ' + escapeHtml(rentRateUnit) : '');
                  }
                } else {
                  line2 = tenant?.name ? ('Арендатор: ' + escapeHtml(tenant.name)) : 'Арендатор: —';
                  line3 = storefrontLabel ? ('Отдел / вывеска: ' + escapeHtml(storefrontLabel)) : '';

                  // Информация о задолженности
                  const debtStatus = hit.debt_status || null;
                  const debtLabel = hit.debt_status_label || '';
                  const debtMode = hit.debt_status_mode || 'auto';
                  const debtScope = hit.debt_status_scope || 'none';
                  const overdueDays = hit.debt_overdue_days !== null && hit.debt_overdue_days !== undefined ? Number(hit.debt_overdue_days) : null;
                  const overdueDaysLabel = overdueDays !== null && Number.isFinite(overdueDays)
                    ? String(Math.max(0, Math.round(overdueDays)))
                    : null;
                  const debtSource = hit.debt_status_source || '';

                  // Объяснение режима (отдельная строка, не затирается)
                  let scopeExplanation = '';

                  if (debtScope === 'space') {
                    // Точный статус по месту
                    if (debtStatus === 'green') {
                      line4 = 'Статус по месту: Нет задолженности';
                      scopeExplanation = debtMode === 'manual' ? 'Статус задан вручную' : 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'pending') {
                      line4 = 'Статус по месту: Срок не нарушен';
                      scopeExplanation = 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'orange' || debtStatus === 'red') {
                      line4 = debtMode === 'manual'
                        ? ('Статус по месту: ' + escapeHtml(debtLabel))
                        : ('Просрочка по месту: ' + (overdueDaysLabel !== null ? overdueDaysLabel + ' дн.' : (debtStatus === 'red' ? 'длительная' : 'есть')));
                      scopeExplanation = debtMode === 'manual' ? 'Статус задан вручную' : 'Связь с местом подтверждена в 1С';
                    } else if (debtStatus === 'gray') {
                      line4 = 'Статус по месту: Нет данных 1С';
                      scopeExplanation = debtSource ? ('Причина: ' + escapeHtml(debtSource)) : '';
                    } else {
                      line4 = debtLabel ? ('Задолженность по месту: ' + escapeHtml(debtLabel)) : 'Задолженность по месту: —';
                      scopeExplanation = '';
                    }
                  } else if (debtScope === 'tenant_fallback') {
                    // Статус арендатора (нет точной связи с местом)
                    if (debtStatus === 'green') {
                      line4 = 'Статус арендатора: Нет задолженности';
                      scopeExplanation = 'Точная связь с местом не подтверждена';
                    } else if (debtStatus === 'pending') {
                      line4 = 'Статус арендатора: Срок не нарушен';
                      scopeExplanation = 'Точная связь с местом не подтверждена';
                    } else if (debtStatus === 'orange' || debtStatus === 'red') {
                      line4 = debtMode === 'manual'
                        ? ('Статус арендатора: ' + escapeHtml(debtLabel))
                        : ('Просрочка арендатора: ' + (overdueDaysLabel !== null ? overdueDaysLabel + ' дн.' : (debtStatus === 'red' ? 'длительная' : 'есть')));
                      scopeExplanation = 'Точная связь с местом не подтверждена';
                    } else if (debtStatus === 'gray') {
                      line4 = 'Статус арендатора: Нет данных 1С';
                      scopeExplanation = 'Точная связь с местом не подтверждена';
                    } else {
                      line4 = debtLabel ? ('Задолженность арендатора: ' + escapeHtml(debtLabel)) : 'Задолженность арендатора: —';
                      scopeExplanation = 'Точная связь с местом не подтверждена';
                    }
                  } else {
                    // scope=none или неизвестный
                    if (debtStatus === 'gray') {
                      line4 = 'Статус: Нет данных';
                      scopeExplanation = debtSource ? ('Причина: ' + escapeHtml(debtSource)) : '';
                    } else {
                      line4 = debtLabel ? ('Статус: ' + escapeHtml(debtLabel)) : 'Статус: —';
                      scopeExplanation = '';
                    }
                  }

                  // line5 — объяснение режима (если есть)
                  line5 = scopeExplanation;

                  // line6 — ставка аренды (если есть)
                  if (rentRateValue !== null && Number.isFinite(rentRateValue)) {
                    line6 = 'Ставка аренды: ' + formatMoneyRu(rentRateValue) + (rentRateUnit ? ' ' + escapeHtml(rentRateUnit) : '');
                  } else {
                    line6 = '';
                  }

                  // line7 — начисления (если есть)
                  if (currentAccrualTotal !== null && Number.isFinite(currentAccrualTotal)) {
                    const accrualSuffix = currentAccrualMode === 'latest' ? 'последний период' : currentAccrualPeriod;
                    line7 = 'Начислено' + (accrualSuffix ? ' (' + escapeHtml(accrualSuffix) + ')' : '') + ': ' + formatMoneyRu(currentAccrualTotal);
                  } else {
                    line7 = '';
                  }
                }
              } else {
                title = 'Разметка';
                line1 = 'Место не привязано (разметка)';
                line2 = '';
                line3 = '';
                line4 = '';
                line5 = '';
                line6 = '';
              }

              let actions = '';
              const btns = [];
              const shapeId = hit.shape_id ? Number(hit.shape_id) : null;
              const hitSpaceId = hit.market_space_id ? Number(hit.market_space_id) : null;
              const hitTenantId = hit?.tenant?.id ? Number(hit.tenant.id) : (hit?.tenant_id ? Number(hit.tenant_id) : null);
              const hitHasTenant = hit.space_tenant_id !== null && hit.space_tenant_id !== undefined;
              const isTenantFallback = (hit.debt_status_scope || 'none') === 'tenant_fallback';
              const hitReviewStatus = String(hit.review_status || hit.space_review_status || hit?.space?.review_status || hit?.space?.map_review_status || '').trim();
              const hitReviewStatusLabel = String(hit.review_status_label || hit.space_review_status_label || hit?.space?.review_status_label || '').trim();
              const hitReviewStatusText = hitReviewStatusLabel || hitReviewStatus;
              const hasReviewMark = hitReviewStatus !== '';
              const hasConflictReviewMark = hitReviewStatus === 'conflict';
              const chosenId = getChosenSpaceId();
              const chosenLabel = chosenSpace ? (formatSpaceLabel(chosenSpace) + ' (ID ' + String(chosenSpace.id) + ')') : '—';
              let reviewNotice = '';

              function shouldShowMatchedReviewDecision(hasTenant, isTenantFallback) {
                return hasTenant && !isTenantFallback;
              }

              if (hitSpaceId && Number.isFinite(hitSpaceId) && hitSpaceId > 0) {
                btns.push('<button type="button" data-action="open-space" data-space-id="' + String(hitSpaceId) + '" title="Открыть карточку торгового места в новой вкладке" aria-label="Открыть карточку торгового места в новой вкладке">Открыть место</button>');
              }

              if (hitTenantId && Number.isFinite(hitTenantId) && hitTenantId > 0) {
                btns.push('<button type="button" data-action="open-tenant" data-tenant-id="' + String(hitTenantId) + '" title="Открыть карточку арендатора в новой вкладке" aria-label="Открыть карточку арендатора в новой вкладке">Открыть арендатора</button>');
              }

              if (isReviewMode()) {
                if (hitSpaceId && Number.isFinite(hitSpaceId) && hitSpaceId > 0) {
                  if (hasReviewMark) {
                    reviewNotice = '<div class="row row-review-note"><span class="row-label">Ревизия: </span><span class="row-value">Уже отмечено' + (hitReviewStatusText ? ': ' + escapeHtml(hitReviewStatusText) : '') + '</span></div>';
                  }

                  btns.push('<button type="button" data-action="review-decision" data-decision="occupancy_conflict" data-space-id="' + String(hitSpaceId) + '" title="Зафиксировать конфликт по месту" aria-label="Зафиксировать конфликт по месту">\u041a\u043e\u043d\u0444\u043b\u0438\u043a\u0442</button>');
                  if (hasConflictReviewMark) {
                    btns.push('<button type="button" disabled title="Это место уже отмечено как требующее проверки" aria-label="Это место уже отмечено как требующее проверки">Уже отмечено</button>');
                  } else {
                    btns.push('<button type="button" data-action="review-decision" data-decision="space_identity_needs_clarification" data-space-id="' + String(hitSpaceId) + '" title="Зафиксировать, что место требует уточнения" aria-label="Зафиксировать, что место требует уточнения">Требует уточнения</button>');
                  }
                  if (shouldShowMatchedReviewDecision(hitHasTenant, isTenantFallback)) {
                    btns.push('<button type="button" data-action="review-decision" data-decision="matched" data-space-id="' + String(hitSpaceId) + '" title="Используйте, если место занято и соответствует данным системы" aria-label="Используйте, если место занято и соответствует данным системы">\u0421\u043e\u0432\u043f\u0430\u043b\u043e</button>');
                  }
                  if (!isTenantFallback) {
                    btns.push('<button type="button" data-action="review-decision" data-decision="mark_space_free" data-space-id="' + String(hitSpaceId) + '" title="Используйте, если место фактически пустое" aria-label="Используйте, если место фактически пустое">\u0421\u0432\u043e\u0431\u043e\u0434\u043d\u043e</button>');
                    btns.push('<button type="button" data-action="review-decision" data-decision="mark_space_service" data-space-id="' + String(hitSpaceId) + '" title="Отметить место как служебное" aria-label="Отметить место как служебное">\u0421\u043b\u0443\u0436\u0435\u0431\u043d\u043e\u0435</button>');
                    btns.push('<button type="button" data-action="review-decision" data-decision="tenant_changed_on_site" data-space-id="' + String(hitSpaceId) + '" title="Отметить, что на месте другой арендатор" aria-label="Отметить, что на месте другой арендатор">\u0421\u043c\u0435\u043d\u0438\u043b\u0441\u044f \u0430\u0440\u0435\u043d\u0434\u0430\u0442\u043e\u0440</button>');
                  }
                }

                if ((!hitSpaceId || hitSpaceId <= 0) && chosenId && Number.isFinite(chosenId) && chosenId > 0 && shapeId && Number.isFinite(shapeId) && shapeId > 0) {
                  btns.push('<button type="button" data-action="review-decision" data-decision="bind_shape_to_space" data-space-id="' + String(chosenId) + '" data-shape-id="' + String(shapeId) + '">\u041f\u0440\u0438\u0432\u044f\u0437\u0430\u0442\u044c \u043a \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u043e\u043c\u0443 \u043c\u0435\u0441\u0442\u0443</button>');
                }
              }

              if (CAN_EDIT && editMode && shapeId && Number.isFinite(shapeId) && shapeId > 0) {
                if (hit.market_space_id) {
                  btns.push('<button type="button" data-action="set-chosen-space" data-space-id="' + String(hit.market_space_id) + '">Выбрать это место</button>');
                }

                btns.push('<button type="button" data-action="delete-shape" data-shape-id="' + String(shapeId) + '">Удалить разметку</button>');
              }
              if (btns.length) {
                actions = '<div class="act">' + btns.join('') + '</div>';
              }

              showPopoverAt(
                e.clientX, e.clientY,
                '<div class="popover__content">' +
                  '<div class="t">' + title + '</div>' +
                  buildPopoverRow(line2) +
                  buildPopoverRow(line3) +
                  buildPopoverRow(line4) +
                  buildPopoverRow(line5, isTenantFallback ? 'row-warning' : '') +
                  buildPopoverRow(line6) +
                  buildPopoverRow(line7) +
                  (line1 ? '<div class="row row-meta muted">' + escapeHtml(line1) + '</div>' : '') +
                  reviewNotice +
                  actions +
                '</div>'
              );
            } catch (err) {
              console.error(err);
              showPopoverAt(e.clientX, e.clientY, '<div class="t">Ошибка</div><div class="row">Не удалось выполнить запрос hit-test.</div>');
            }
          }

          popover?.addEventListener('click', (e) => {
            const t = e.target;
            if (!(t instanceof HTMLElement)) return;

            const action = t.getAttribute('data-action');

            if (action === 'review-decision') {
              const decision = String(t.getAttribute('data-decision') || '');
              const marketSpaceId = Number(t.getAttribute('data-space-id') || 0);
              const shapeId = Number(t.getAttribute('data-shape-id') || 0);
              if (decision === 'fix_space_identity') {
                hidePopover();
                openIdentityFixModal({
                  decision,
                  marketSpaceId: Number.isFinite(marketSpaceId) && marketSpaceId > 0 ? marketSpaceId : null,
                  shapeId: Number.isFinite(shapeId) && shapeId > 0 ? shapeId : null,
                  hit: lastHit,
                  space: lastHit?.space || chosenSpace || null,
                  observedTenantName: lastHit?.tenant?.name || '',
                });
                return;
              }
              submitReviewDecision(decision, {
                marketSpaceId: Number.isFinite(marketSpaceId) && marketSpaceId > 0 ? marketSpaceId : null,
                shapeId: Number.isFinite(shapeId) && shapeId > 0 ? shapeId : null,
                hit: lastHit,
                space: lastHit?.space || chosenSpace || null,
                observedTenantName: lastHit?.tenant?.name || '',
              }).catch((err) => {
                console.error(err);
                toast(String(err?.message || err));
              });
              return;
            }

            if (action === 'open-space') {
              const id = Number(t.getAttribute('data-space-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;
              window.open('/admin/market-spaces/' + String(Math.trunc(id)) + '/edit', '_blank', 'noopener');
              return;
            }

            if (action === 'open-tenant') {
              const id = Number(t.getAttribute('data-tenant-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;
              window.open('/admin/tenants/' + String(Math.trunc(id)) + '/edit', '_blank', 'noopener');
              return;
            }

            if (action === 'delete-shape') {
              const id = t.getAttribute('data-shape-id');
              deleteShape(id).then(() => hidePopover()).catch((err) => {
                console.error(err);
                toast('Ошибка удаления: ' + String(err?.message || err));
              });
              return;
            }

            if (action === 'set-chosen-space') {
              const id = Number(t.getAttribute('data-space-id') || 0);
              if (!Number.isFinite(id) || id <= 0) return;

              const spaceLabel = lastHit?.space || null;
              const hitTenant = lastHit?.tenant || null;
              const next = normalizeChosenSpace({
                id,
                number: spaceLabel?.number ?? '',
                code: spaceLabel?.code ?? '',
                tenantName: hitTenant?.name ?? null,
              });

              if (next) {
                setChosenSpace(next, { announce: true });
                hidePopover();
              }
              return;
            }
          });

          window.addEventListener('keydown', async (e) => {
            if (!CAN_EDIT || !editMode) return;

            if (tool === 'poly' && polyDrawing) {
              if (e.key === 'Enter') {
                e.preventDefault();
                await finishPolygon();
                return;
              }
              if (e.key === 'Escape') {
                e.preventDefault();
                cancelPolygon();
                return;
              }
              if (e.key === 'Backspace') {
                e.preventDefault();
                if (polyDraft.length > 0) {
                  polyDraft.pop();
                  redrawShapes();
                  toast('Точка удалена');
                }
                return;
              }
            }

            if (tool === 'select' && selectedShapeId && (e.key === 'Delete')) {
              e.preventDefault();
              deleteShape(selectedShapeId).catch((err) => {
                console.error(err);
                toast('Ошибка удаления: ' + String(err?.message || err));
              });
              return;
            }
          });

          overlay.addEventListener('mousedown', onDown);
          overlay.addEventListener('click', onClick);

          window.addEventListener('mouseup', onUp);
          window.addEventListener('mouseup', onGlobalUp);
          window.addEventListener('mousemove', onMove);

          handlesLayer?.addEventListener('mousedown', (e) => e.stopPropagation());

          init().catch((err) => {
            console.error(err);
            fallbackToIframe(err?.message || 'init failed');
          });
        }

        async function importWithTimeout(url, timeoutMs = 3500) {
          return await Promise.race([
            import(url),
            new Promise((_, reject) => setTimeout(() => reject(new Error('import timeout')), timeoutMs)),
          ]);
        }

        async function tryImportDirect(pdfUrl, workerUrl) {
          try {
            const mod = await importWithTimeout(pdfUrl);
            const pdfjsLib = mod?.default ?? mod;
            if (!pdfjsLib || typeof pdfjsLib.getDocument !== 'function') return null;
            return { pdfjsLib, workerSrc: workerUrl };
          } catch {
            return null;
          }
        }

        async function tryImportBlob(pdfUrl, workerUrl) {
          let blobUrl = null;
          let workerBlobUrl = null;

          try {
            const response = await fetch(pdfUrl, {
              method: 'GET',
              credentials: 'same-origin',
            });

            if (!response.ok) {
              return null;
            }

            const source = await response.text();
            if (!source) {
              return null;
            }

            blobUrl = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));

            const mod = await import(blobUrl);
            const pdfjsLib = mod?.default ?? mod;
            if (!pdfjsLib || typeof pdfjsLib.getDocument !== 'function') return null;

            // Загружаем worker тоже через blob, чтобы избежать MIME-проблем
            const workerResponse = await fetch(workerUrl, {
              method: 'GET',
              credentials: 'same-origin',
            });

            if (workerResponse.ok) {
              const workerSource = await workerResponse.text();
              if (workerSource) {
                workerBlobUrl = URL.createObjectURL(new Blob([workerSource], { type: 'text/javascript' }));
                return { pdfjsLib, workerSrc: workerBlobUrl };
              }
            }

            // Fallback к прямому URL worker (на случай если worker загружается нормально)
            return { pdfjsLib, workerSrc: workerUrl };
          } catch {
            return null;
          }
          // Не отзываем blobUrl — worker нужен позже для загрузки
        }

        async function tryLoadScript(pdfUrl, workerUrl) {
          try {
            await new Promise((resolve, reject) => {
              const existing = document.querySelector('script[data-pdfjs-loader="' + pdfUrl + '"]');
              if (existing) {
                if (window.pdfjsLib && typeof window.pdfjsLib.getDocument === 'function') {
                  resolve();
                  return;
                }
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('script load failed')), { once: true });
                return;
              }

              const script = document.createElement('script');
              script.src = pdfUrl;
              script.async = true;
              script.dataset.pdfjsLoader = pdfUrl;
              script.onload = () => resolve();
              script.onerror = () => reject(new Error('script load failed'));
              document.head.appendChild(script);
            });

            const pdfjsLib = window.pdfjsLib;
            if (!pdfjsLib || typeof pdfjsLib.getDocument !== 'function') return null;
            return { pdfjsLib, workerSrc: workerUrl };
          } catch {
            return null;
          }
        }

        async function loadPdfJs() {
          const localMjsBlob = await tryImportBlob('/vendor/pdfjs/pdf.min.mjs', '/vendor/pdfjs/pdf.worker.min.mjs');
          if (localMjsBlob) return localMjsBlob;

          const cdn = await tryImportDirect(
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.min.mjs',
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.worker.min.mjs'
          );
          if (cdn) return cdn;

          const cdnBlob = await tryImportBlob(
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.min.mjs',
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.530/build/pdf.worker.min.mjs'
          );
          if (cdnBlob) return cdnBlob;

          return null;
        }

        setMapLoadProgress(4, 'Загрузка карты…', 'loading');
        const loaded = await loadPdfJs();
        if (!loaded) {
          fallbackToIframe('cannot import pdfjs from local and CDN');
        } else {
          startPdfJs(loaded.pdfjsLib, loaded.workerSrc);
        }
      </script>
    @endif
  </div>
</body>
</html>
