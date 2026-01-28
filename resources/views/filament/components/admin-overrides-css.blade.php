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

/* === Task "Сводка": выравниваем значения строго в одну колонку === */
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
</style>
