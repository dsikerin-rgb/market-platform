@php
    $fullCalendarCssPath = public_path('vendor/saade/filament-fullcalendar/filament-fullcalendar.css');
@endphp

@if (is_file($fullCalendarCssPath))
    <link rel="stylesheet" href="{{ asset('vendor/saade/filament-fullcalendar/filament-fullcalendar.css') }}">
@endif

<style>
/* === Маркер: можно выключить, поставив data-admin-overrides="0" на <html> === */
html:not([data-admin-overrides="0"])::before{
  content:"admin-overrides-css ✅";
  position:fixed;
  right:12px;
  bottom:12px;
  z-index:999999;
  padding:4px 8px;
  font:12px/1.2 ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial;
  background:#111;
  color:#fff;
  border-radius:8px;
  opacity:.85;
  pointer-events:none;
}

/* ====================================================================== */
/* === Dashboard: фикс фильтра "Период (месяц)" (вертикальные цифры)     === */
/* ====================================================================== */
/* Причина эффекта: контрол/контейнер слишком узкий + где-то включён break-all/word-wrap.
   Фикс: даём нормальную минимальную ширину и запрещаем перенос по символам. */
html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-fo-field-wrp,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-fo-field-wrp-content{
  min-width: 0 !important;
}

/* Пытаемся попасть максимально надёжно: по data-атрибутам поля "month" (если есть) */
html:not([data-admin-overrides="0"]) .fi-dashboard-page [data-field*="month"],
html:not([data-admin-overrides="0"]) .fi-dashboard-page [data-field-wrapper*="month"]{
  min-width: 16rem;
}

/* Нативный select (native(true)) */
html:not([data-admin-overrides="0"]) .fi-dashboard-page select[name="month"],
html:not([data-admin-overrides="0"]) .fi-dashboard-page select[id$="month"],
html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-fo-field-wrp select{
  width: 100% !important;
  min-width: 16rem;
  white-space: nowrap;
  word-break: normal;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Если всё-таки используется кастомный select (tom-select / choices) — страхуем */
html:not([data-admin-overrides="0"]) .fi-dashboard-page .ts-wrapper,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .ts-control,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .choices,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .choices__inner,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-select,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-input-wrp{
  width: 100% !important;
  min-width: 16rem;
}

html:not([data-admin-overrides="0"]) .fi-dashboard-page .ts-control,
html:not([data-admin-overrides="0"]) .fi-dashboard-page .choices__inner{
  white-space: nowrap;
}

/* На десктопе можно чуть шире, чтобы выглядело аккуратнее */
@media (min-width: 1024px){
  html:not([data-admin-overrides="0"]) .fi-dashboard-page select[name="month"],
  html:not([data-admin-overrides="0"]) .fi-dashboard-page select[id$="month"],
  html:not([data-admin-overrides="0"]) .fi-dashboard-page .fi-fo-field-wrp select,
  html:not([data-admin-overrides="0"]) .fi-dashboard-page .ts-wrapper,
  html:not([data-admin-overrides="0"]) .fi-dashboard-page .choices{
    min-width: 18rem;
  }
}

/* ====================================================================== */
/* === Task "Сводка": выравниваем значения строго в одну колонку         === */
/* ====================================================================== */
@media (min-width: 1024px){

  .task-summary-compact{
    --task-inline-label-width: 14rem;
  }

  /* 1) Убираем половинные спаны ТОЛЬКО в "Сводке" */
  .task-summary-compact .fi-grid-col{
    --col-span-default: 1 / -1 !important;
    --col-span-sm:      1 / -1 !important;
    --col-span-md:      1 / -1 !important;
    --col-span-lg:      1 / -1 !important;
    --col-span-xl:      1 / -1 !important;
    --col-span-2xl:     1 / -1 !important;
  }

  /* 2) Infolist (readonly / Placeholder) */
  .task-summary-compact .fi-in-entry.has-inline-label{
    display:grid !important;
    grid-template-columns: var(--task-inline-label-width) minmax(0, 1fr) !important;
    column-gap: 1.25rem !important;
    align-items:start !important;
  }

  .task-summary-compact .fi-in-entry-label-col{
    max-width: var(--task-inline-label-width) !important;
    white-space: normal !important;
    margin:0 !important;
  }

  .task-summary-compact .fi-in-entry-content-col{
    min-width:0 !important;
    text-align:left !important;
  }

  /* твой кейс: dd = flex -> фиксируем прижим влево */
  .task-summary-compact .fi-in-entry-content-ctn{
    justify-content:flex-start !important;
    align-items:flex-start !important;
    width:100% !important;
  }

  /* и сам текст тоже не центрируем/не растягиваем странно */
  .task-summary-compact .fi-in-text-item{
    text-align:left !important;
    justify-self:start !important;
  }

  /* 3) Forms (select/datetime/text) */
  .task-summary-compact .fi-fo-field-wrp.fi-inline-label{
    --inline-label-width: var(--task-inline-label-width);
    display:grid !important;
    grid-template-columns: var(--task-inline-label-width) minmax(0, 1fr) !important;
    column-gap: 1.25rem !important;
    align-items:start !important;
  }

  .task-summary-compact .fi-fo-field-wrp-label{
    max-width: var(--task-inline-label-width) !important;
    white-space: normal !important;
    margin:0 !important;
  }

  .task-summary-compact .fi-fo-field-wrp-content{
    margin:0 !important;
    min-width:0 !important;
    text-align:left !important;
  }

  /* чтобы контролы не "уезжали" и занимали нормальную ширину в колонке */
  .task-summary-compact .fi-fo-field-wrp-content > *{
    justify-self:start !important;
  }

  .task-summary-compact .fi-fo-field-wrp-content .fi-input-wrp,
  .task-summary-compact .fi-fo-field-wrp-content .fi-select,
  .task-summary-compact .fi-fo-field-wrp-content .fi-fo-select,
  .task-summary-compact .fi-fo-field-wrp-content .fi-fo-date-time-picker{
    width: 100% !important;
  }
}

/* ====================================================================== */
/* === Sticky form actions: semi-transparent bar behind Save/Cancel     === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-sc-actions.fi-sticky .fi-ac{
  background: rgba(255, 255, 255, 0.78) !important;
  -webkit-backdrop-filter: blur(8px);
  backdrop-filter: blur(8px);
  border: 1px solid rgba(17, 24, 39, 0.08);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12) !important;
}

html:not([data-admin-overrides="0"]).dark .fi-sc-actions.fi-sticky .fi-ac,
html:not([data-admin-overrides="0"]) .dark .fi-sc-actions.fi-sticky .fi-ac{
  background: rgba(17, 24, 39, 0.74) !important;
  border-color: rgba(255, 255, 255, 0.14);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.34) !important;
}

/* ====================================================================== */
/* === Form helper question icons: use neutral (non-accent) color       === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-fo-field-label-ctn .fi-sc-icon.fi-color{
  color: rgba(107, 114, 128, 0.62) !important; /* neutral + semi-transparent */
}

html:not([data-admin-overrides="0"]).dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color,
html:not([data-admin-overrides="0"]) .dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color{
  color: rgba(156, 163, 175, 0.68) !important; /* neutral + semi-transparent */
}

html:not([data-admin-overrides="0"]) .fi-fo-field-label-ctn .fi-sc-icon.fi-color:hover,
html:not([data-admin-overrides="0"]) .fi-fo-field-label-ctn .fi-sc-icon.fi-color:focus{
  color: var(--gray-600) !important;
}

html:not([data-admin-overrides="0"]).dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color:hover,
html:not([data-admin-overrides="0"]).dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color:focus,
html:not([data-admin-overrides="0"]) .dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color:hover,
html:not([data-admin-overrides="0"]) .dark .fi-fo-field-label-ctn .fi-sc-icon.fi-color:focus{
  color: var(--gray-300) !important;
}

/* ====================================================================== */
/* === Sidebar: visual spacing between flat navigation clusters          === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-sidebar a[href$="/admin/market-holidays"],
html:not([data-admin-overrides="0"]) .fi-sidebar a[href$="/admin/settings"],
html:not([data-admin-overrides="0"]) .fi-sidebar a[href$="/admin/markets"]{
  margin-top: .85rem;
}

/* ====================================================================== */
/* === Tenant accrual edit: calm, card-based layout                      === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page{
  --accrual-surface: #ffffff;
  --accrual-surface-muted: #f8fafc;
  --accrual-border: #dbe4f0;
  --accrual-border-strong: #c5d4e8;
  --accrual-heading: #0f172a;
  --accrual-text: #334155;
  --accrual-label: #64748b;
  --accrual-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-header{
  background: linear-gradient(125deg, #f3f7ff 0%, #e5eefc 100%);
  border: 1px solid var(--accrual-border-strong);
  border-radius: 1rem;
  padding: 1rem 1.25rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-header .fi-header-heading{
  color: var(--accrual-heading);
  letter-spacing: -0.01em;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-header .fi-header-subheading{
  color: var(--accrual-text);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section{
  background: var(--accrual-surface);
  border: 1px solid var(--accrual-border);
  border-radius: 1rem;
  box-shadow: var(--accrual-shadow);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section-header{
  background: linear-gradient(180deg, #f8fbff 0%, #f2f7ff 100%);
  border-bottom: 1px solid #e4ebf7;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section-header-heading{
  color: var(--accrual-heading);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section-header-description{
  color: var(--accrual-text);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section-content{
  background: var(--accrual-surface-muted);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-fo-field-wrp-label{
  color: var(--accrual-label);
  font-weight: 600;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-input-wrp{
  border-color: #cfd9e8;
  background: #ffffff;
  transition: border-color .16s ease, box-shadow .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-input-wrp:focus-within{
  border-color: #5f8fdc;
  box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page input[readonly],
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page textarea[readonly]{
  background: #f2f6fc !important;
  color: #475569 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-badge{
  border: 1px solid #cfdcf0;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-sc-actions.fi-sticky{
  margin-top: .5rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-sc-actions.fi-sticky .fi-ac{
  border-radius: .95rem;
  border-color: rgba(15, 23, 42, 0.12);
}

@media (max-width: 1024px){
  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-header{
    padding: .85rem 1rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section{
    border-radius: .85rem;
  }
}
</style>
