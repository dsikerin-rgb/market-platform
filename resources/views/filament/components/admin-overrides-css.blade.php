@php
    $fullCalendarCssPath = public_path('vendor/saade/filament-fullcalendar/filament-fullcalendar.css');
@endphp

@if (is_file($fullCalendarCssPath))
    <link rel="stylesheet" href="{{ asset('vendor/saade/filament-fullcalendar/filament-fullcalendar.css') }}">
@endif

@include('filament.partials.admin-workspace-styles')

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

html:not([data-admin-overrides="0"]) .task-summary-compact .fi-fo-field-wrp-helper-text,
html:not([data-admin-overrides="0"]) .task-participants-compact .fi-fo-field-wrp-helper-text{
  display: none !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-workspace{
  align-items: flex-start !important;
  width: 100%;
  min-width: 0;
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) clamp(22.5rem, 27vw, 26.25rem); /* 360px..420px */
  gap: 1.25rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-main{
  display: grid;
  gap: .52rem;
  width: 100%;
  min-width: 0;
}

/* Fallback: если классы страницы отличаются, всё равно уплотняем поток */
html:not([data-admin-overrides="0"]) .task-edit-main{
  display: grid;
  gap: .52rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-form{
  display: grid;
  gap: .52rem;
  width: 100%;
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .task-edit-form{
  display: grid;
  gap: .52rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero{
  border: 1px solid #dbe5f1;
  border-radius: 1.15rem;
  background:
    radial-gradient(circle at top right, rgba(72, 115, 191, 0.16), transparent 42%),
    linear-gradient(180deg, #fbfdff 0%, #f3f8ff 100%);
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
  padding: 1rem 1.2rem .85rem;
  width: 100%;
  max-width: none;
  margin: 0 0 .3rem;
}

html:not([data-admin-overrides="0"]) .task-edit-hero{
  margin: 0 0 .3rem;
  padding-bottom: .85rem;
}

/* ====================================================================== */
/* === Tasks edit page: reduce Filament header/content vertical gap       === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-header,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page-header{
  margin-bottom: .5rem !important;
  padding-bottom: 0 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-header + *,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page-header + *{
  margin-top: 0 !important;
}

/* If the page uses a stack/gap utility, tighten it on this page only */
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page{
  row-gap: .75rem !important;
}

/* Last resort: kill the gap right after hero and in page content wrapper */
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero + *{
  margin-top: 0 !important;
  padding-top: 0 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page-content,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page-content-ctn,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-main-ctn{
  padding-top: 0 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .fi-page-content > .grid{
  row-gap: .5rem !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__breadcrumbs{
  margin-bottom: .7rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__top{
  display: flex;
  align-items: flex-start;
  justify-content: flex-start;
  gap: .85rem;
  margin-bottom: 1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__main{
  min-width: 0;
  flex: 1 1 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading{
  margin: 0;
  font-size: clamp(0.826rem, 0.714rem + .385vw, 1.085rem) !important;
  line-height: 1.12;
  letter-spacing: -.02em;
  font-weight: 700;
  color: #0f172a;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__subheading{
  margin: .45rem 0 0;
  font-size: 1.06rem;
  color: #475569;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__value-button,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip--action{
  appearance: none;
  border: 0;
  background: transparent;
  padding: 0;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button{
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box;
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-text,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-text{
  width: 100% !important;
  max-width: 100% !important;
  display: block;
  box-sizing: border-box;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button{
  display: flex;
  align-items: flex-start;
  padding: .58rem .88rem .68rem;
  border: 1px solid #bed0e5;
  border-radius: 1rem;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(241, 247, 255, 1) 100%);
  box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button .task-edit-hero__heading-text,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-text{
  display: block;
  font-size: clamp(1.12rem, 0.896rem + 0.7vw, 1.505rem);
  line-height: 1.03;
  font-weight: 620;
  letter-spacing: -.015em;
  color: #0b1630;
  white-space: normal;
  overflow: visible !important;
  text-overflow: clip !important;
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box;
  word-break: break-word;
}


html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button:focus-visible{
  border-color: #9ebae0;
  box-shadow: 0 14px 30px rgba(15, 23, 42, 0.1);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-text {
  font-size: .93rem;
  line-height: 1.45;
  font-weight: 400;
  color: #10213a;
  text-align: left;
  white-space: pre-wrap;
  tab-size: 4;
  overflow-wrap: anywhere;
  word-break: normal;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description{
  display: grid;
  gap: .18rem;
  margin-top: .6rem;
  width: 100%;
  max-width: 100%;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button{
  display: grid;
  gap: .18rem;
  width: 100% !important;
  max-width: 100% !important;
  margin-top: .1rem;
  padding: .8rem .95rem .85rem;
  border: 1px solid #d2def0;
  border-radius: 1rem;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.9) 0%, rgba(245, 250, 255, 0.96) 100%);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button{
  font-size: .93rem;
  line-height: 1.45;
  color: #10213a;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-button:focus-visible{
  border-color: #95b3df;
  box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
  transform: translateY(-1px);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-label{
  font-size: .74rem;
  font-weight: 700;
  letter-spacing: .02em;
  text-transform: uppercase;
  color: #6980a3;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description-text{
  font-size: .93rem;
  line-height: 1.45;
  font-weight: 400;
  color: #10213a;
  text-align: left;
  width: 100% !important;
  white-space: pre-wrap;
  tab-size: 4;
  overflow-wrap: anywhere;
  word-break: normal;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chips{
  display: flex;
  flex-wrap: wrap;
  gap: .45rem;
  margin-top: .95rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip{
  display: inline-flex;
  align-items: center;
  min-height: 1.7rem;
  padding: .22rem .62rem;
  border: 1px solid #c9d7ea;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.84);
  font-size: .76rem;
  font-weight: 600;
  color: #33517e;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip--action{
  transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip--action:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip--action:focus-visible{
  transform: translateY(-1px);
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__chip--status{
  background: rgba(233, 242, 255, 0.98);
  border-color: #abc2e8;
  color: #1d4f91;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions{
  flex: 0 0 auto;
  align-self: flex-start;
  margin-left: auto;
  width: min(100%, 17.2rem);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-ac{
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .55rem;
  width: 100%;
  justify-content: stretch;
  align-items: stretch;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-btn{
  width: 100%;
  min-width: 0;
  min-height: 2.9rem;
  border-radius: 1rem;
  border: 1px solid #d8e2f0 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  padding-block: .46rem !important;
  padding-inline: .68rem !important;
  font-size: .88rem;
  font-weight: 640;
  line-height: 1.15;
  letter-spacing: -.01em;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
  justify-content: center;
  gap: .45rem;
  text-align: center;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-btn:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-btn:focus-visible{
  border-color: #c8d6e8 !important;
  transform: translateY(-1px);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-btn .fi-btn-icon{
  color: inherit !important;
  width: 1.72rem;
  height: 1.72rem;
  margin: 0;
  border-radius: .72rem;
  background: rgba(217, 229, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(163, 187, 228, 0.42);
  flex: 0 0 1.72rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-btn .fi-btn-label{
  text-align: left;
}

@media (max-width: 1140px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions{
    width: min(100%, 17rem);
  }
}

@media (max-width: 860px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions{
    width: 100%;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-ac{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 620px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-ac{
    grid-template-columns: 1fr;
  }
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--accept.fi-btn,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--resume.fi-btn{
  border-color: #bfd4f3 !important;
  background: linear-gradient(180deg, #fbfdff 0%, #edf4ff 100%) !important;
  color: #1f4b95 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--pause.fi-btn{
  border-color: #ead9a8 !important;
  background: linear-gradient(180deg, #fffdf8 0%, #fff5df 100%) !important;
  color: #8a5b08 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--complete.fi-btn{
  border-color: #d5dce7 !important;
  background: linear-gradient(180deg, #fcfdff 0%, #f1f5fb 100%) !important;
  color: #39465d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--cancel.fi-btn,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--delete.fi-btn{
  border-color: #f0c2c7 !important;
  background: linear-gradient(180deg, #fffdfd 0%, #fff0f2 100%) !important;
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--accept.fi-btn .fi-btn-icon,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--resume.fi-btn .fi-btn-icon{
  background: rgba(214, 229, 255, 0.98);
  box-shadow: inset 0 0 0 1px rgba(146, 177, 232, 0.42);
  color: #1f4b95 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--pause.fi-btn .fi-btn-icon{
  background: rgba(255, 239, 200, 0.98);
  box-shadow: inset 0 0 0 1px rgba(220, 194, 133, 0.45);
  color: #8a5b08 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--complete.fi-btn .fi-btn-icon{
  background: rgba(227, 233, 241, 0.98);
  box-shadow: inset 0 0 0 1px rgba(173, 188, 207, 0.45);
  color: #39465d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--cancel.fi-btn .fi-btn-icon,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .task-hero-action--delete.fi-btn .fi-btn-icon{
  background: rgba(255, 223, 226, 0.98);
  box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__grid{
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .9rem 1rem;
  margin: 0;
  padding-top: .55rem;
  border-top: 1px solid rgba(198, 212, 231, 0.86);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__item{
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__item--actionable dd{
  display: flex;
  align-items: flex-start;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__value-button{
  display: inline-flex;
  align-items: flex-start;
  padding: 0;
  border-bottom: 1px dashed rgba(61, 101, 161, 0.34);
  color: #0f172a;
  transition: color .16s ease, border-color .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__value-button:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__value-button:focus-visible{
  color: #1f4b95;
  border-bottom-color: rgba(31, 75, 149, 0.8);
  transform: translateY(-1px);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__item dt{
  margin: 0 0 .24rem;
  font-size: .74rem;
  font-weight: 600;
  color: #64748b;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__item dd{
  margin: 0;
  font-size: .92rem;
  font-weight: 600;
  line-height: 1.35;
  color: #0f172a;
  word-break: break-word;
}

/* ====================================================================== */
/* === Modals: общий стиль Filament + task-исключения                    === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-modal{
  --modal-overlay-rgb: 15 23 42;
}

html:not([data-admin-overrides="0"]) .fi-modal-close-overlay{
  background: rgba(15, 23, 42, 0.48);
  -webkit-backdrop-filter: blur(5px);
  backdrop-filter: blur(5px);
}

html:not([data-admin-overrides="0"]) .fi-modal-window{
  border: 1px solid #c9d7ea;
  border-radius: 1.35rem;
  background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
  box-shadow: 0 24px 56px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}

html:not([data-admin-overrides="0"]) .fi-modal-header{
  padding: 1.05rem 1.3rem .95rem;
  background:
    radial-gradient(circle at top right, rgba(72, 115, 191, 0.14), transparent 46%),
    linear-gradient(180deg, #edf4ff 0%, #dfeaff 100%);
  border-bottom: 1px solid #c7d6eb;
}

html:not([data-admin-overrides="0"]) .fi-modal-header .fi-modal-heading{
  font-size: 1.08rem;
  font-weight: 720;
  letter-spacing: -.02em;
  color: #0f172a;
}

html:not([data-admin-overrides="0"]) .fi-modal-header .fi-modal-description{
  margin-top: .34rem;
  font-size: .93rem;
  line-height: 1.45;
  color: #5b6f89;
}

html:not([data-admin-overrides="0"]) .fi-modal-content{
  padding: 1.15rem 1.3rem 1.25rem;
}

html:not([data-admin-overrides="0"]) .fi-modal-footer{
  padding: 1rem 1.3rem 1.15rem;
  border-top: 1px solid #e1e8f2;
  background: linear-gradient(180deg, #fbfdff 0%, #f6f9ff 100%);
}

html:not([data-admin-overrides="0"]) .fi-modal-footer-actions{
  gap: .55rem;
}

html:not([data-admin-overrides="0"]) .fi-modal-window .fi-modal-close-btn{
  color: #64748b;
}

html:not([data-admin-overrides="0"]) .fi-modal-window .fi-modal-close-btn:hover,
html:not([data-admin-overrides="0"]) .fi-modal-window .fi-modal-close-btn:focus-visible{
  color: #0f172a;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal{
  border-color: #bfd0ea;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--wide{
  max-width: min(92vw, 52rem);
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--compact{
  max-width: min(92vw, 36rem);
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--confirm{
  max-width: min(92vw, 34rem);
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--wide .fi-modal-content{
  padding: 1.25rem 1.45rem 1.4rem;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--compact .fi-modal-content{
  padding: 1.05rem 1.2rem 1.15rem;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--confirm .fi-modal-header{
  background:
    radial-gradient(circle at top right, rgba(219, 115, 129, 0.16), transparent 44%),
    linear-gradient(180deg, #fff6f7 0%, #ffecef 100%);
  border-bottom-color: #f2c8d0;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--confirm .fi-modal-header .fi-modal-heading{
  color: #8f2534;
}

html:not([data-admin-overrides="0"]) .fi-modal-window.task-modal--confirm .fi-modal-content{
  padding-top: 1.1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section{
  border: 1px solid #c7d6eb !important;
  border-radius: 1.3rem;
  background: #ffffff;
  box-shadow: 0 16px 36px rgba(15, 23, 42, 0.07);
  overflow: hidden;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section > .fi-section-header{
  position: relative;
  padding: 1.1rem 1.3rem 1rem;
  background:
    radial-gradient(circle at top right, rgba(72, 115, 191, 0.24), transparent 44%),
    linear-gradient(180deg, #edf4ff 0%, #dfeaff 100%) !important;
  border-bottom: 1px solid #bfd0ea !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section > .fi-section-header::before{
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  background: linear-gradient(90deg, rgba(72, 115, 191, 0.55), rgba(164, 191, 244, 0.12));
  pointer-events: none;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-section-header-heading{
  font-size: 1.08rem;
  letter-spacing: -.02em;
  font-weight: 720;
  color: #0e1c35;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section.fi-section-has-header:not(.fi-collapsed) > .fi-section-content-ctn{
  border-top: 1px solid #e1e8f2;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section > .fi-section-content-ctn{
  padding: 1.2rem 1.3rem 1.25rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-section-content{
  row-gap: 1.05rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-field-wrp{
  border: 1px solid #d2deef !important;
  border-radius: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%) !important;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
  padding: .95rem 1rem 1rem;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-field-wrp:focus-within{
  border-color: #93b5e5 !important;
  box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.12), 0 12px 28px rgba(15, 23, 42, 0.06);
  transform: translateY(-1px);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-field-wrp-label{
  font-size: .8rem;
  line-height: 1.25;
  font-weight: 700;
  letter-spacing: .01em;
  color: #4e627d;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-participants-compact .fi-fo-field-label-col{
  align-items: flex-start;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-participants-compact .fi-fo-field-label-ctn{
  display: inline-flex !important;
  align-items: center !important;
  justify-content: flex-start !important;
  gap: .34rem !important;
  width: fit-content !important;
  max-width: 100% !important;
  white-space: normal !important;
  flex: 0 0 auto !important;
  align-self: flex-start !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-participants-compact .fi-fo-field-label-ctn > *{
  flex: 0 0 auto !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-field-wrp-content{
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-input-wrp,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-select,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-select,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-date-time-picker{
  border-radius: .95rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-input-wrp,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-select{
  border-color: #cfdaec;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-input-wrp:focus-within,
html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-select:focus-within{
  border-color: #5f8fdc;
  box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.12);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-section .fi-fo-field-wrp-helper-text{
  margin-top: .5rem;
  font-size: .78rem;
  line-height: 1.35;
  color: #5c6a80;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar{
  position: sticky;
  top: 1rem;
  width: 100%;
  min-width: 0;
  max-width: clamp(22.5rem, 27vw, 26.25rem); /* 360px..420px */
  align-self: flex-start;
  height: fit-content;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-tabs{
  display: inline-flex;
  gap: .32rem;
  align-items: center;
  padding: .28rem;
  border: 1px solid #dbe5f1;
  border-radius: 1rem;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
  width: fit-content;
  max-width: 100%;
  position: static;
  margin: .95rem 0 0 1rem;
  z-index: 5;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-tabs-item{
  border-radius: .8rem;
  min-height: 2.3rem;
  padding: 0 .9rem;
  color: #475569;
  font-weight: 600;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-tabs-item.fi-active{
  background: linear-gradient(180deg, #fefefe 0%, #f6f9ff 100%);
  color: #c25b08;
  box-shadow: inset 0 0 0 1px #e8eef8;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-resource-relation-manager + .fi-resource-relation-manager{
  margin-top: .72rem;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-header-heading,
html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-header-description{
  display: none;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-header{
  display: none !important;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-header .fi-ta-actions{
  display: none !important;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-content{
  border-top: none !important;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar{
  padding-top: 0;
}

html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-empty-state .fi-btn,
html:not([data-admin-overrides="0"]) .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-actions .fi-btn-color-warning{
  background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%) !important;
  border-color: #93c5fd !important;
  color: #1d4ed8 !important;
  box-shadow: 0 12px 28px rgba(14, 116, 144, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-header{
  box-shadow: none;
  border-radius: 1.05rem 1.05rem 0 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-resource-relation-manager:first-of-type .fi-ta-header{
  border-radius: 1.05rem 1.05rem 0 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-content{
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
  max-height: min(66vh, 42rem);
  min-height: 16rem;
  overflow: auto;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-header-heading{
  font-size: .95rem;
  font-weight: 700;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-header-description{
  display: none;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-empty-state{
  background: linear-gradient(180deg, #fbfdff 0%, #f4f8ff 100%);
  min-height: 14rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-empty-state-heading{
  color: #0f172a;
  font-size: 1rem;
  font-weight: 700;
}

html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar .fi-ta-empty-state-description{
  color: #64748b;
}

@media (max-width: 1279px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-workspace{
    display: grid !important;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-sidebar{
    position: static;
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__top{
    flex-direction: column;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions{
    width: 100%;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-ac{
    justify-content: flex-start;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__grid{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero{
    padding: .95rem 1rem 1rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading{
    font-size: 1.24rem !important;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__grid{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__actions .fi-ac{
    grid-template-columns: 1fr;
  }
}

@media (max-width: 520px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__grid{
    grid-template-columns: 1fr;
  }
}

@media (min-width: 1280px) and (max-width: 1540px){
  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__heading-button{
    max-width: none;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tasks.fi-resource-edit-record-page .task-edit-hero__description{
    max-width: none;
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
/* === Admin tables: readability-first styling                           === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-ta{
  --admin-table-surface: #ffffff;
  --admin-table-surface-muted: #f8fbff;
  --admin-table-surface-hover: #f3f8ff;
  --admin-table-border: #dbe5f1;
  --admin-table-border-strong: #c7d6e8;
  --admin-table-text: #0f172a;
  --admin-table-text-muted: #64748b;
  --admin-table-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header{
  background: linear-gradient(180deg, #fbfdff 0%, #f3f8ff 100%);
  border: 1px solid var(--admin-table-border);
  border-radius: 1rem;
  box-shadow: var(--admin-table-shadow);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-heading{
  color: var(--admin-table-text);
  letter-spacing: -0.01em;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-description{
  color: var(--admin-table-text-muted);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-content{
  background: var(--admin-table-surface);
  border: 1px solid var(--admin-table-border);
  border-radius: 1rem;
  box-shadow: var(--admin-table-shadow);
  overflow: hidden;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-content-ctn{
  border-radius: 1rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-summary-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-group-header-cell{
  background: linear-gradient(180deg, #f9fbff 0%, #f2f7ff 100%);
  color: var(--admin-table-text-muted);
  font-size: .74rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  border-bottom: 1px solid var(--admin-table-border-strong);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-cell-sort-btn{
  color: inherit;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-row{
  transition: background-color .14s ease;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-row:not(.fi-ta-summary-header-row):not(.fi-ta-group-header-row):hover{
  background: var(--admin-table-surface-hover);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-cell{
  border-bottom: 1px solid rgba(219, 229, 241, 0.78);
  vertical-align: top;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-summary-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-group-header-cell{
  padding-top: .27rem;
  padding-bottom: .27rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-summary-header-cell,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-group-header-cell{
  padding-top: .62rem;
  padding-bottom: .62rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-col,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text{
  color: var(--admin-table-text);
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text:not(.fi-inline){
  padding-top: .52rem;
  padding-bottom: .52rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text.fi-ta-text-has-descriptions:not(.fi-ta-text-has-badges){
  gap: .14rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text-item{
  color: var(--admin-table-text);
  line-height: 1.14;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text.fi-ta-text-has-descriptions:not(.fi-ta-text-has-badges) > .fi-ta-text-item:first-child{
  font-weight: 600;
  letter-spacing: -.01em;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text-description,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-placeholder{
  color: var(--admin-table-text-muted);
  line-height: 1.26;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-text-description{
  margin-top: 0;
  font-size: .75rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-align-end .fi-ta-text-item,
html:not([data-admin-overrides="0"]) .fi-ta .fi-align-end .fi-ta-text-description,
html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-summary-row .fi-ta-text-item{
  font-variant-numeric: tabular-nums;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-badge{
  border: 1px solid rgba(191, 206, 226, 0.88);
  box-shadow: none;
  min-height: 1.4rem;
  padding-inline: .42rem;
  font-size: .74rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-actions .fi-icon-btn{
  border-radius: .75rem;
}

html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-empty-state{
  background: linear-gradient(180deg, #fbfdff 0%, #f6faff 100%);
}

html:not([data-admin-overrides="0"]).dark .fi-ta,
html:not([data-admin-overrides="0"]) .dark .fi-ta{
  --admin-table-surface: #0f172a;
  --admin-table-surface-muted: #111c31;
  --admin-table-surface-hover: #13213b;
  --admin-table-border: rgba(148, 163, 184, 0.24);
  --admin-table-border-strong: rgba(148, 163, 184, 0.34);
  --admin-table-text: #e5eef9;
  --admin-table-text-muted: #9fb0c8;
  --admin-table-shadow: 0 10px 30px rgba(2, 6, 23, 0.3);
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

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-back-action{
  border-radius: .9rem;
  border: 1px solid #b9cbea !important;
  background: linear-gradient(180deg, #f9fbff 0%, #eef4ff 100%) !important;
  color: #1d4f91 !important;
  box-shadow: 0 8px 20px rgba(70, 111, 176, 0.12);
  transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-back-action:hover,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-back-action:focus-visible{
  border-color: #8eafe0 !important;
  background: linear-gradient(180deg, #f3f7ff 0%, #e6efff 100%) !important;
  color: #143e77 !important;
  box-shadow: 0 10px 24px rgba(70, 111, 176, 0.16);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-back-action svg{
  color: currentColor !important;
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

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-in-entry{
  gap: .35rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-in-entry-label{
  color: var(--accrual-label);
  font-weight: 600;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-in-entry-content{
  color: var(--accrual-heading);
  font-weight: 600;
  line-height: 1.45;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-summary-section .fi-in-entry{
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-summary-section .fi-in-entry-content{
  font-size: 1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-summary-section [wire\\:key*="summary_total_with_vat"] .fi-in-entry-content,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-summary-section [wire\\:key*="summary_total_with_vat"] .fi-in-entry-content-ctn{
  font-size: 1.2rem;
  font-weight: 700;
  letter-spacing: -0.02em;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-in-entry,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-in-entry{
  padding: .2rem 0 .85rem;
  border-bottom: 1px solid rgba(197, 212, 232, 0.58);
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-in-entry-content,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-in-entry-content{
  min-height: 1.45rem;
  font-size: 1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-in-entry-content-ctn,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-in-entry-content-ctn{
  padding: 0;
  background: transparent;
  border: 0;
  box-shadow: none;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-in-entry-label,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-in-entry-label{
  font-size: .78rem;
  letter-spacing: .01em;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-in-entry:last-child,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-in-entry:last-child{
  border-bottom: 0;
  padding-bottom: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-section-content,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-finance-section .fi-section-content{
  row-gap: 1.1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section textarea,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-fo-textarea,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .accrual-context-section .fi-fo-textarea-wrp,
html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section textarea{
  border-radius: .9rem;
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

/* ====================================================================== */
/* === Tenant edit page: card-based layout aligned with project pages   === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page{
  --tenant-edit-surface: #ffffff;
  --tenant-edit-surface-muted: #f8fafc;
  --tenant-edit-border: #dbe4f0;
  --tenant-edit-border-strong: #c5d4e8;
  --tenant-edit-heading: #0f172a;
  --tenant-edit-text: #334155;
  --tenant-edit-label: #64748b;
  --tenant-edit-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-page{
  row-gap: .85rem !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header{
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem 1.2rem;
  flex-wrap: wrap;
  background:
    radial-gradient(circle at top left, rgba(59, 130, 246, 0.13), transparent 24%),
    linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
  border: 1px solid var(--tenant-edit-border-strong);
  border-radius: 1.25rem;
  padding: 1rem 1.15rem 1.05rem 1.15rem;
  box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-header-actions{
  flex: 1 1 52rem;
  width: auto;
  min-width: min(100%, 52rem);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac{
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .6rem;
  width: 100%;
  align-items: stretch;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-header-heading{
  color: var(--tenant-edit-heading);
  letter-spacing: -0.01em;
  font-size: clamp(1.08rem, 0.92rem + 0.8vw, 1.6rem);
  line-height: 1.02;
  max-width: 13ch;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-header-subheading{
  color: var(--tenant-edit-text);
  font-size: .92rem;
  line-height: 1.45;
  max-width: 36rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs{
  margin-top: .1rem;
  margin-bottom: .65rem;
  justify-self: start;
  width: 100%;
  max-width: 100%;
  padding: .2rem;
  border-radius: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(255, 255, 255, 0.76);
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: thin;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs .fi-tabs{
  margin-inline: 0;
  width: max-content;
  max-width: none;
  flex-wrap: nowrap;
  gap: .2rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs [role="tab"]{
  border-radius: .85rem;
  padding: .42rem .72rem;
  color: #475569;
  white-space: nowrap;
  font-weight: 600;
  font-size: .92rem;
  line-height: 1.2;
  transition: background-color .16s ease, color .16s ease, box-shadow .16s ease, transform .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs [role="tab"]:hover{
  color: #0f172a;
  transform: translateY(-1px);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs [role="tab"][aria-selected="true"]{
  background: #2563eb;
  color: #ffffff;
  box-shadow: 0 10px 20px rgba(37, 99, 235, 0.22);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs + .fi-section{
  margin-top: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-form > .grid{
  grid-template-columns: minmax(0, 1fr) !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-form > .grid > *{
  grid-column: 1 / -1 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section{
  background: var(--tenant-edit-surface);
  border: 1px solid var(--tenant-edit-border);
  border-radius: 1rem;
  box-shadow: var(--tenant-edit-shadow);
  overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section-header{
  background: linear-gradient(180deg, #f8fbff 0%, #f2f7ff 100%);
  border-bottom: 1px solid #e4ebf7;
  padding: 1rem 1.1rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section-header-heading{
  color: var(--tenant-edit-heading);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section-header-description{
  color: var(--tenant-edit-text);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section-content{
  background: var(--tenant-edit-surface-muted);
  padding: 1.1rem;
  overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-cabinet-access{
  border-color: #bfd2ef;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-cabinet-access .fi-section-header{
  background: linear-gradient(180deg, #f8fbff 0%, #f2f7ff 100%);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-cabinet-access .fi-section-content{
  background: #f8fbff;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-ta .fi-ta-content{
  overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-ta .fi-ta-content-ctn{
  overflow-x: auto;
  overflow-y: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-fo-field-wrp-label{
  color: var(--tenant-edit-label);
  font-weight: 600;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-fo-field-wrp-helper-text{
  color: #64748b;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-in-entry{
  gap: .35rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-in-entry-label{
  color: var(--tenant-edit-label);
  font-weight: 600;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-in-entry-content{
  color: var(--tenant-edit-heading);
  font-weight: 600;
  line-height: 1.45;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-input-wrp{
  border-color: #cfd9e8;
  background: #ffffff;
  transition: border-color .16s ease, box-shadow .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-input-wrp:focus-within{
  border-color: #5f8fdc;
  box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"]{
  margin-top: .15rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] > .fi-fo-repeater{
  gap: .85rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-repeater-header{
  padding: .9rem 1rem;
  border: 1px solid #d8e3f1;
  border-radius: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-repeater-items{
  gap: .85rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-repeater-item{
  border: 1px solid #d8e3f1;
  border-radius: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
  overflow: hidden;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-repeater-item-header{
  padding: .8rem .95rem;
  background: linear-gradient(180deg, #fdfefe 0%, #f3f8ff 100%);
  border-bottom: 1px solid #e4ebf7;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-repeater-item-content{
  padding: .95rem;
  background: #f8fbff;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-field-wrp{
  border-radius: .95rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page [data-contact-staff-editor="1"] .fi-fo-field-wrp-helper-text{
  font-size: .78rem;
  line-height: 1.35;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-actions.fi-sticky{
  margin-top: .5rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-actions.fi-sticky .fi-ac{
  border-radius: .95rem;
  border-color: rgba(15, 23, 42, 0.12);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn{
  position: relative;
  display: grid;
  grid-template-columns: 2.35rem minmax(0, 1fr);
  grid-template-rows: auto auto;
  grid-template-areas:
    "icon title"
    "icon subtitle";
  align-items: start;
  justify-items: start;
  gap: .08rem .7rem;
  width: 100%;
  min-height: 4.35rem;
  padding: .68rem .8rem .72rem .7rem !important;
  border-radius: .92rem;
  border: 1px solid #d8e3f1 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  text-align: left;
  white-space: normal;
  overflow: hidden;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn:focus-visible{
  border-color: #c6d6e7 !important;
  transform: translateY(-1px);
  box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn > .fi-icon{
  grid-area: icon;
  width: 2.25rem;
  height: 2.25rem;
  margin: 0;
  align-self: start;
  justify-self: start;
  border-radius: .8rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn > .fi-btn-label{
  grid-area: title;
  margin-top: .02rem;
  color: #0f172a;
  font-size: .9rem;
  font-weight: 700;
  line-height: 1.08;
  white-space: normal;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn::after{
  content: attr(data-subtitle);
  grid-area: subtitle;
  align-self: start;
  color: #475569;
  font-size: .74rem;
  line-height: 1.22;
  white-space: normal;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action--secondary.fi-btn > .fi-icon{
  background: rgba(215, 227, 255, 0.95);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action--primary.fi-btn > .fi-icon{
  background: rgba(214, 229, 255, 0.95);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action--danger.fi-btn{
  border-color: #f0c2c7 !important;
  background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%) !important;
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action--danger.fi-btn > .fi-icon{
  background: rgba(255, 223, 226, 0.98);
  box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action--danger.fi-btn::after{
  color: #9f1239;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card{
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .9rem;
  width: 100%;
  min-height: 4.35rem;
  padding: .72rem .8rem .72rem .85rem;
  border: 1px solid #bfd2ef;
  border-radius: .95rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  color: #1f3251;
  text-align: left;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
  cursor: pointer;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card:hover,
html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card:focus-visible{
  border-color: #a9c5ee;
  transform: translateY(-1px);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-active{
  border-color: #bfd4f3;
  background: linear-gradient(180deg, #fbfdff 0%, #eef4ff 100%);
  color: #1f4b95;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-inactive{
  border-color: #f0c2c7;
  background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%);
  color: #b4323d;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-copy{
  display: grid;
  gap: .2rem;
  min-width: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-title{
  font-size: .92rem;
  font-weight: 700;
  line-height: 1.1;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-subtitle{
  font-size: .75rem;
  line-height: 1.25;
  opacity: .84;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-switch{
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  flex-shrink: 0;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-switch__track{
  position: relative;
  display: inline-flex;
  align-items: center;
  width: 3.2rem;
  height: 1.9rem;
  padding: .18rem;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.22);
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.18);
  transition: background-color .16s ease, box-shadow .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-active .tenant-hero-state-switch__track{
  background: rgba(37, 99, 235, 0.16);
  box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.18);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-inactive .tenant-hero-state-switch__track{
  background: rgba(244, 63, 94, 0.16);
  box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.16);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-switch__thumb{
  width: 1.45rem;
  height: 1.45rem;
  border-radius: 999px;
  background: #ffffff;
  box-shadow: 0 8px 14px rgba(15, 23, 42, 0.12);
  transform: translateX(0);
  transition: transform .18s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-active .tenant-hero-state-switch__thumb{
  transform: translateX(1.28rem);
}

html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card.is-inactive .tenant-hero-state-switch__thumb{
  transform: translateX(0);
}

/* ====================================================================== */
/* === Staff edit page: card-based actions aligned with tenant pattern  === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header{
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem 1.2rem;
  flex-wrap: wrap;
  background:
    radial-gradient(circle at top left, rgba(59, 130, 246, 0.13), transparent 24%),
    linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
  border: 1px solid #c5d4e8;
  border-radius: 1.25rem;
  padding: 1rem 1.15rem 1.05rem 1.15rem;
  box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-header-actions{
  flex: 1 1 52rem;
  width: auto;
  min-width: min(100%, 52rem);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac{
  display: flex;
  flex-wrap: nowrap;
  gap: .5rem;
  width: 100%;
  align-items: stretch;
  justify-content: flex-start;
  overflow-x: auto;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-header-heading{
  color: #0f172a;
  letter-spacing: -0.01em;
  font-size: clamp(1.08rem, 0.92rem + 0.8vw, 1.6rem);
  line-height: 1.02;
  max-width: 13ch;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-header-subheading{
  color: #334155;
  font-size: .92rem;
  line-height: 1.45;
  max-width: 36rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn{
  flex: 0 0 auto;
  min-width: 9rem;
  max-width: 11rem;
  position: relative;
  display: grid;
  grid-template-columns: 1.5rem minmax(0, 1fr);
  grid-template-rows: auto;
  grid-template-areas:
    "icon title";
  align-items: center;
  justify-items: center;
  gap: 0 .5rem;
  min-height: 3.5rem;
  padding: .5rem .6rem !important;
  border-radius: .75rem;
  border: 1px solid #d8e3f1 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
  text-align: center;
  white-space: normal;
  overflow: visible;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn:hover,
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn:focus-visible{
  border-color: #c6d6e7 !important;
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn > .fi-icon{
  grid-area: icon;
  width: 1.5rem;
  height: 1.5rem;
  margin: 0;
  align-self: center;
  justify-self: center;
  border-radius: .5rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn > .fi-btn-label{
  grid-area: title;
  margin-top: 0;
  color: #0f172a;
  font-size: .8rem;
  font-weight: 600;
  line-height: 1.2;
  white-space: normal;
  word-wrap: break-word;
  overflow: visible;
  text-align: center;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action--secondary.fi-btn > .fi-icon{
  background: rgba(215, 227, 255, 0.95);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action--primary.fi-btn > .fi-icon{
  background: rgba(214, 229, 255, 0.95);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action--danger.fi-btn{
  border-color: #f0c2c7 !important;
  background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%) !important;
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action--danger.fi-btn > .fi-icon{
  background: rgba(255, 223, 226, 0.98);
  box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-btn > .fi-icon{
  width: 1.5rem;
  height: 1.5rem;
  border-radius: .5rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-btn.fi-color-danger > .fi-icon,
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action--danger.fi-btn > .fi-icon{
  width: 1.5rem;
  height: 1.5rem;
  border-radius: .5rem;
  background: rgba(255, 223, 226, 0.98);
  box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item{
  flex: 1 1 10rem;
  min-width: 10rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn{
  min-height: 4.35rem;
  padding: .68rem .8rem .72rem .7rem !important;
  border-radius: .92rem;
  border: 1px solid #d8e3f1 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  text-align: left;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn:hover,
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn:focus-visible{
  border-color: #c6d6e7 !important;
  transform: translateY(-1px);
  box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn > .fi-icon{
  width: 2.25rem;
  height: 2.25rem;
  border-radius: .8rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn.fi-color-danger > .fi-icon{
  background: rgba(255, 223, 226, 0.98);
  box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
  color: #b4323d !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn > .fi-btn-label{
  color: #0f172a;
  font-size: .9rem;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-btn > .fi-btn-sub{
  color: #475569;
  font-size: .74rem;
  line-height: 1.22;
  display: block;
  margin-top: .02rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown{
  min-height: 4.35rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn{
  min-height: 4.35rem;
  padding: .68rem .8rem .72rem .7rem !important;
  border-radius: .92rem;
  border: 1px solid #d8e3f1 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  text-align: left;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn:hover,
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn:focus-visible{
  border-color: #c6d6e7 !important;
  transform: translateY(-1px);
  box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn > .fi-icon{
  width: 2.25rem;
  height: 2.25rem;
  border-radius: .8rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn > .fi-btn-label{
  color: #0f172a;
  font-size: .9rem;
  font-weight: 700;
  line-height: 1.08;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown > .fi-btn::after{
  content: attr(data-subtitle);
  color: #475569;
  font-size: .74rem;
  line-height: 1.22;
  display: block;
  margin-top: .02rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-dropdown.fi-color-gray > .fi-btn > .fi-icon{
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group{
  display: flex;
  align-items: center;
  gap: .7rem;
  min-height: 4.35rem;
  padding: .68rem .8rem .72rem .7rem !important;
  border-radius: .92rem;
  border: 1px solid #d8e3f1 !important;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
  color: #1f3251 !important;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group:hover,
html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group:focus-visible{
  border-color: #c6d6e7 !important;
  transform: translateY(-1px);
  box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group .fi-icon{
  width: 2.25rem;
  height: 2.25rem;
  border-radius: .8rem;
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group .fi-btn-label{
  color: #0f172a;
  font-size: .9rem;
  font-weight: 700;
  line-height: 1.08;
  white-space: nowrap;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group::after{
  content: attr(data-subtitle);
  color: #475569;
  font-size: .74rem;
  line-height: 1.22;
  display: block;
  margin-top: .02rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .fi-ac-item .fi-ac-icon-btn-group.staff-card-action--secondary .fi-icon{
  background: rgba(215, 227, 255, 0.95);
  box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
  color: #1d4ed8 !important;
}

@media (max-width: 1180px){
  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs .fi-tabs{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn{
    flex: 1 1 calc(50% - .3rem);
    min-width: calc(50% - .3rem);
    max-width: calc(50% - .3rem);
  }
}

@media (max-width: 780px){
  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-sc-tabs .fi-tabs{
    grid-template-columns: 1fr;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header{
    flex-wrap: wrap;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-header-actions{
    flex-basis: 100%;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac{
    grid-template-columns: 1fr;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header .fi-ac .tenant-card-action.fi-btn{
    min-height: 0;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .tenant-hero-state-card{
    min-height: 0;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header{
    flex-wrap: wrap;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-header-actions{
    flex-basis: 100%;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac{
    flex-wrap: wrap;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-staff-edit-page .fi-header .fi-ac .staff-card-action.fi-btn{
    flex: 1 1 calc(50% - .3rem);
    min-width: calc(50% - .3rem);
    max-width: calc(50% - .3rem);
  }
}

@media (max-width: 1024px){
  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-header{
    padding: .85rem 1rem;
    border-radius: 1.1rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-tenants-edit-page .fi-section{
    border-radius: .85rem;
  }
}

@media (max-width: 1024px){
  html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-header,
  html:not([data-admin-overrides="0"]) .fi-ta .fi-ta-content{
    border-radius: .85rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-header{
    padding: .85rem 1rem;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-section{
    border-radius: .85rem;
  }
}

/* Keep the accrual page in a single vertical flow to avoid broken card masonry */
@media (min-width: 1025px){
  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-sc-form > .grid{
    grid-template-columns: minmax(0, 1fr) !important;
  }

  html:not([data-admin-overrides="0"]) .fi-resource-accruals-edit-page .fi-sc-form > .grid > *{
    grid-column: 1 / -1 !important;
  }
}

/* ====================================================================== */
/* === Sidebar width: narrower on desktop, even narrower on tablet/mobile === */
/* ====================================================================== */
/* Desktop: 20rem → 16rem (−20%) */
html:not([data-admin-overrides="0"]){
  --sidebar-width: 16rem;
}

/* Tablet/Mobile: 16rem → 14.4rem (−10% от desktop) */
@media (max-width: 1279px){
  html:not([data-admin-overrides="0"]){
    --sidebar-width: 14.4rem;
  }
}

/* ====================================================================== */
/* === Staff create page: match edit page styling                       === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page{
    --staff-create-border: #d8e3f1;
    --staff-create-surface: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-header{
    padding: 1.05rem 1.2rem 0.95rem;
    background:
        radial-gradient(circle at top left, rgba(59, 130, 246, 0.13), transparent 24%),
        linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
    border: 1px solid #c5d4e8;
    border-radius: 1.25rem;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-header .fi-header-heading{
    color: #0f172a;
    letter-spacing: -0.01em;
    font-size: clamp(1.08rem, 0.92rem + 0.8vw, 1.6rem);
    line-height: 1.02;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-header .fi-header-subheading{
    color: #334155;
    font-size: 0.92rem;
    line-height: 1.45;
    max-width: 42rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-section{
    border-color: var(--staff-create-border);
    border-radius: 1rem;
    background: var(--staff-create-surface);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-section-content{
    padding: 1rem 1.1rem 1.15rem;
    overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-input-wrp{
    border-color: #cfd9e8;
    background: #ffffff;
    transition: border-color .16s ease, box-shadow .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-input-wrp:focus-within{
    border-color: #5f8fdc;
    box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
}

html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-checkbox-list-option-label,
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-toggle label{
    font-size: 0.92rem;
}

/* Compact fields: narrow inputs where full width is unnecessary */
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-field-wrp:has([name*="email"]),
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-field-wrp:has([name*="password"]),
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-field-wrp:has([name*="telegram_chat_id"]),
html:not([data-admin-overrides="0"]) .fi-resource-staff-create-page .fi-fo-field-wrp:has([name*="market_id"]){
    max-width: 28rem;
}

/* ====================================================================== */
/* === List pages: halve the gap between hero, filters and table        === */
/* ====================================================================== */
/* Дефолтный Filament gap ~2rem (space-y-8). Уменьшаем до ~1rem.
   Все List-страницы имеют класс вида fi-resource-*-list-page,
   поэтому таргетим через атрибут class$="-list-page".
   Ключевой момент: отступы заданы через row-gap на flex/grid контейнерах,
   а не через margin у дочерних элементов. */

/* Сжимаем вертикальный стек на всех list-страницах */
html:not([data-admin-overrides="0"]) [class*="-list-page"]{
  gap: 0.75rem !important;
}

/* КРИТИЧНО: row-gap на контейнере hero — уменьшаем */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-page-header-main-ctn{
  row-gap: 0.5rem !important;
  padding-block: 0.75rem !important;
}

/* КРИТИЧНО: row-gap на контенте — уменьшаем */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-page-content{
  row-gap: 0.25rem !important;
  padding-top: 0 !important;
}

/* Сжимаем gap на контейнере таблицы */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-ta-ctn{
  margin-top: 0 !important;
  padding-top: 0 !important;
}

/* Уменьшаем отступ после hero-заголовка */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-header{
  margin-bottom: 0 !important;
}

/* Убираем лишний top-отступ у элемента, следующего за header */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-header + *{
  margin-top: 0 !important;
}

/* Сжимаем отступ после фильтров (schema) */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-sc{
  margin-bottom: 0.5rem !important;
}

/* Таблица Filament: убираем все верхние отступы */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-ta{
  margin-top: 0 !important;
}

html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-ta .fi-ta-header{
  margin-top: 0 !important;
  padding-top: 0.5rem !important;
}

html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-ta .fi-ta-content{
  margin-top: 0 !important;
}

/* Tabs strip: tighten spacing */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-tabs{
  margin-bottom: 0.25rem !important;
}

html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-sc-tabs{
  margin-bottom: 0.25rem !important;
}

html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-sc-tabs + .fi-ta{
  margin-top: 0 !important;
}

/* Убираем лишний padding у контента */
html:not([data-admin-overrides="0"]) [class*="-list-page"] .fi-page-content{
  padding-top: 0 !important;
}

/* ====================================================================== */
/* === Invitations: голубой стиль как у tenant/staff edit                === */
/* ====================================================================== */
html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-header,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-header{
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.1rem 1.2rem 1.15rem;
  border: 1px solid rgba(197, 212, 232, 0.96);
  border-radius: 1.25rem;
  background:
    radial-gradient(circle at top left, rgba(59, 130, 246, 0.12), transparent 26%),
    linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
  box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
}

html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-section,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-section{
  border-color: #d8e3f1;
  border-radius: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
  overflow: visible;
}

html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-section-header,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-section-header{
  background: linear-gradient(180deg, #f8fbff 0%, #f2f7ff 100%);
  border-bottom: 1px solid #d8e3f1;
}

html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-section-content,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-section-content{
  background: #f8fafc;
  padding: 1.1rem 1.15rem 1.2rem;
}

html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-input-wrp,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-input-wrp{
  border-color: #cfd9e8;
  background: #ffffff;
  transition: border-color .16s ease, box-shadow .16s ease;
}

html:not([data-admin-overrides="0"]) .fi-resource-invitations-create-page .fi-input-wrp:focus-within,
html:not([data-admin-overrides="0"]) .fi-resource-invitations-edit-page .fi-input-wrp:focus-within{
  border-color: #5f8fdc;
  box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
}

/* === Login page styles === */
.login-container {
    max-width: 720px;
    width: 100%;
    margin: 0 auto;
    background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
    border-radius: 1.5rem;
    box-shadow: 0 20px 60px rgba(14, 116, 144, 0.12), 0 8px 24px rgba(3, 105, 161, 0.08);
    border: 1px solid rgba(186, 230, 253, 0.6);
}

.login-header {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%);
    padding: 2rem 1.5rem;
    text-align: center;
    color: white;
    position: relative;
    border-radius: 1.5rem 1.5rem 0 0;
}

.login-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.08'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}

.login-header h1 {
    position: relative;
    z-index: 1;
    font-size: 1.75rem;
    font-weight: 600;
    margin: 0;
}

.login-body {
    padding: 2rem 1.5rem 2.5rem;
}

.login-body .fi-fo-field-wrp {
    margin-bottom: 1.25rem;
}

.login-body .fi-btn {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%) !important;
    border: none !important;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3) !important;
    transition: all 0.2s ease;
}

.login-body .fi-btn:hover {
    background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important;
    box-shadow: 0 6px 16px rgba(14, 165, 233, 0.4) !important;
    transform: translateY(-1px);
}
</style>

{{-- Tabs stay in the relation-manager container and are positioned via CSS only. --}}
