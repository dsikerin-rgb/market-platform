<style>
    .aw-shell {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .aw-hero {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 1.5rem;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 28%),
            radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 24%),
            linear-gradient(180deg, #eff6ff, #dbeafe);
        padding: 1.5rem;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.10);
    }

    html.dark .aw-hero {
        border-color: rgba(148, 163, 184, 0.16);
        background:
            radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
            radial-gradient(circle at top right, rgba(16, 185, 129, 0.09), transparent 24%),
            linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.92));
        box-shadow: 0 24px 48px rgba(2, 6, 23, 0.35);
    }

    .aw-hero-grid {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 1.25rem;
        flex-wrap: wrap;
    }

    .aw-hero-copy {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        max-width: 44rem;
    }

    .aw-hero-title {
        display: flex;
        align-items: center;
        gap: 0.9rem;
    }

    .aw-hero-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 3rem;
        height: 3rem;
        border-radius: 1rem;
        background: rgba(37, 99, 235, 0.12);
        color: #1d4ed8;
        flex-shrink: 0;
    }

    html.dark .aw-hero-icon {
        background: rgba(59, 130, 246, 0.14);
        color: rgb(147, 197, 253);
    }

    .aw-hero-heading {
        margin: 0;
        color: #0f172a;
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.1;
    }

    html.dark .aw-hero-heading {
        color: #f8fafc;
    }

    .aw-hero-subheading {
        margin: 0.35rem 0 0;
        color: #475569;
        font-size: 0.95rem;
        line-height: 1.65;
    }

    html.dark .aw-hero-subheading {
        color: #cbd5e1;
    }

    .aw-stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
        min-width: min(100%, 24rem);
    }

    @media (min-width: 1280px) {
        .aw-stat-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    .aw-stat-card {
        border-radius: 1rem;
        padding: 0.95rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.55);
    }

    html.dark .aw-stat-card {
        background: rgba(15, 23, 42, 0.55);
        border-color: rgba(148, 163, 184, 0.14);
    }

    .aw-stat-label {
        font-size: 0.7rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
    }

    .aw-stat-value {
        margin-top: 0.3rem;
        font-size: 1.65rem;
        line-height: 1;
        font-weight: 700;
        color: #0f172a;
    }

    html.dark .aw-stat-value {
        color: #f8fafc;
    }

    .aw-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 1.5rem;
    }

    .aw-column {
        grid-column: span 12;
    }

    @media (min-width: 1024px) {
        .aw-column--sidebar {
            grid-column: span 4;
        }

        .aw-column--content {
            grid-column: span 8;
        }
    }

    .aw-panel {
        border-radius: 1.25rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.9);
        box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
        overflow: hidden;
    }

    html.dark .aw-panel {
        background: rgba(15, 23, 42, 0.74);
        border-color: rgba(148, 163, 184, 0.16);
        box-shadow: 0 18px 36px rgba(2, 6, 23, 0.25);
    }

    .aw-panel-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    }

    .aw-panel-title {
        margin: 0;
        color: #0f172a;
        font-size: 1.1rem;
        font-weight: 700;
    }

    html.dark .aw-panel-title {
        color: #f8fafc;
    }

    .aw-panel-copy {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.92rem;
        line-height: 1.6;
    }

    html.dark .aw-panel-copy {
        color: #94a3b8;
    }

    .aw-panel-body {
        padding: 1.25rem;
    }

    .aw-action-grid {
        display: grid;
        gap: 0.85rem;
    }

    .aw-link-card {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        padding: 1rem;
        border-radius: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.96));
        color: inherit;
        text-decoration: none;
        transition: transform 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    html.dark .aw-link-card {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.88), rgba(15, 23, 42, 0.72));
        border-color: rgba(148, 163, 184, 0.16);
    }

    .aw-link-card:hover {
        transform: translateY(-1px);
        border-color: rgba(37, 99, 235, 0.28);
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.10);
    }

    .aw-link-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 0.9rem;
        background: rgba(37, 99, 235, 0.12);
        color: #1d4ed8;
        flex-shrink: 0;
    }

    html.dark .aw-link-icon {
        background: rgba(59, 130, 246, 0.14);
        color: rgb(147, 197, 253);
    }

    .aw-link-title {
        margin: 0;
        color: #0f172a;
        font-size: 0.95rem;
        font-weight: 700;
    }

    html.dark .aw-link-title {
        color: #f8fafc;
    }

    .aw-link-copy {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.87rem;
        line-height: 1.55;
    }

    html.dark .aw-link-copy {
        color: #94a3b8;
    }

    .aw-link-meta {
        margin-top: 0.55rem;
        color: #1d4ed8;
        font-size: 0.82rem;
        font-weight: 600;
    }

    html.dark .aw-link-meta {
        color: #bfdbfe;
    }

    .aw-inline-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .aw-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .aw-list-item {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        padding: 0.9rem 1rem;
        border-radius: 0.95rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.85);
    }

    html.dark .aw-list-item {
        background: rgba(255, 255, 255, 0.04);
        border-color: rgba(148, 163, 184, 0.14);
    }

    .aw-list-title {
        margin: 0;
        color: #0f172a;
        font-size: 0.92rem;
        font-weight: 600;
    }

    html.dark .aw-list-title {
        color: #f8fafc;
    }

    .aw-list-copy {
        margin: 0.25rem 0 0;
        color: #64748b;
        font-size: 0.84rem;
        line-height: 1.55;
    }

    html.dark .aw-list-copy {
        color: #94a3b8;
    }

    .aw-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        border: 1px solid rgba(37, 99, 235, 0.22);
        background: rgba(37, 99, 235, 0.08);
        color: #1d4ed8;
        font-size: 0.78rem;
        font-weight: 600;
    }

    html.dark .aw-chip {
        border-color: rgba(59, 130, 246, 0.28);
        background: rgba(37, 99, 235, 0.12);
        color: #dbeafe;
    }

    .aw-empty {
        display: flex;
        min-height: 12rem;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.8rem;
        padding: 2rem 1.5rem;
        border-radius: 1rem;
        border: 1px dashed rgba(148, 163, 184, 0.18);
        background: rgba(248, 250, 252, 0.9);
        text-align: center;
    }

    html.dark .aw-empty {
        background: rgba(255, 255, 255, 0.03);
        border-color: rgba(148, 163, 184, 0.16);
    }

    .aw-empty-title {
        color: #0f172a;
        font-size: 0.95rem;
        font-weight: 600;
    }

    html.dark .aw-empty-title {
        color: #f8fafc;
    }

    .aw-empty-copy {
        color: #64748b;
        font-size: 0.9rem;
        line-height: 1.55;
    }

    html.dark .aw-empty-copy {
        color: #94a3b8;
    }

    .aw-sticky-actions {
        position: sticky;
        bottom: 18px;
        z-index: 20;
        border-radius: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.88);
        backdrop-filter: blur(8px);
        padding: 1rem 1.1rem;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.10);
    }

    html.dark .aw-sticky-actions {
        background: rgba(15, 23, 42, 0.8);
        border-color: rgba(148, 163, 184, 0.16);
        box-shadow: 0 12px 28px rgba(2, 6, 23, 0.26);
    }

    .aw-actions-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem;
        align-items: center;
    }

    .aw-shell--staff {
        gap: 1.25rem;
    }

    .aw-hero--staff {
        padding: 1.25rem;
    }

    .aw-hero-stack--staff {
        display: grid;
        gap: 1rem;
    }

    .aw-hero-copy--staff {
        max-width: 52rem;
    }

    .aw-hero-actions {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.75rem;
        align-content: start;
    }

    .aw-hero-actions > :only-child {
        grid-column: 1 / -1;
    }

    .aw-hero-actions--staff {
        grid-template-columns: repeat(auto-fit, minmax(16rem, max-content));
        gap: 0.85rem;
        justify-content: start;
    }

    .aw-link-card--staff-action {
        min-height: 0;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .aw-link-card--staff-inline {
        max-width: 23rem;
    }

    .aw-link-head--staff {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .aw-link-icon--staff-action {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.85rem;
    }

    .aw-link-copy--staff-action {
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .aw-chip--staff-alert {
        padding: 0.2rem 0.5rem;
        font-size: 0.72rem;
        line-height: 1;
        color: #b45309;
        border-color: rgba(245, 158, 11, 0.28);
        background: rgba(245, 158, 11, 0.12);
    }

    html.dark .aw-chip--staff-alert {
        color: #fde68a;
        border-color: rgba(245, 158, 11, 0.34);
        background: rgba(245, 158, 11, 0.16);
    }

    .fi-resource-staff-list-page .fi-sc-tabs {
        margin-top: 0.25rem;
        margin-bottom: 0.85rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-staff-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-staff-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .fi-resource-staff-edit-page {
        --staff-edit-border: #d8e3f1;
        --staff-edit-surface: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    .fi-resource-staff-edit-page .staff-edit-hero {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.1rem 1.2rem 1.15rem;
        border: 1px solid rgba(197, 212, 232, 0.96);
        border-radius: 1.35rem;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.12), transparent 26%),
            linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
    }

    .fi-resource-staff-edit-page .staff-edit-hero__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem 1.2rem;
        flex-wrap: wrap;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__main {
        min-width: 0;
        flex: 1 1 32rem;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__kicker {
        margin: 0 0 .35rem;
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #1d4ed8;
        opacity: .78;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__heading {
        margin: 0;
        color: #0f172a;
        letter-spacing: -0.01em;
        font-size: clamp(1.08rem, 0.92rem + 0.8vw, 1.6rem);
        line-height: 1.02;
        max-width: 20ch;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__subheading {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        margin-top: .6rem;
        padding: .35rem .7rem;
        border: 1px solid rgba(191, 210, 239, 0.95);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.84);
        color: #334155;
        font-size: .9rem;
        line-height: 1.35;
        font-weight: 600;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions {
        flex: 0 1 41rem;
        width: min(100%, 41rem);
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-ac {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .6rem;
        width: 100%;
        align-items: stretch;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-btn {
        width: 100%;
        min-width: 0;
        min-height: 4.35rem;
        border-radius: .92rem;
        border: 1px solid #d8e3f1 !important;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
        color: #1f3251 !important;
        padding-block: .62rem !important;
        padding-inline: .72rem !important;
        text-align: left;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
        transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-btn:hover,
    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-btn:focus-visible {
        border-color: #c6d6e7 !important;
        transform: translateY(-1px);
        box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-btn .fi-btn-label {
        display: block;
        color: #0f172a;
        font-size: .9rem;
        font-weight: 700;
        line-height: 1.1;
        white-space: normal;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-btn::after {
        content: attr(data-subtitle);
        display: block;
        margin-top: .2rem;
        color: #475569;
        font-size: .74rem;
        line-height: 1.2;
        white-space: normal;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .staff-card-action--danger.fi-btn {
        border-color: #f0c2c7 !important;
        background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%) !important;
        color: #b4323d !important;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__actions .staff-card-action--danger.fi-btn::after {
        color: #9f1239;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__grid {
        display: grid;
        grid-template-columns: 1fr 2.5fr 1fr 1fr;
        gap: .75rem;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item {
        padding: .9rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.86);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.03);
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item dt {
        font-size: .74rem;
        font-weight: 700;
        letter-spacing: .01em;
        text-transform: uppercase;
        color: #1d4ed8;
        opacity: .76;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item dd {
        margin-top: .3rem;
        color: #0f172a;
        font-size: .96rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item--muted dd {
        color: #475569;
        font-weight: 600;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item--danger dd {
        color: #b42318;
    }

    .fi-resource-staff-edit-page .staff-edit-hero__item--telegram dd {
        color: #1d4ed8;
    }

    .fi-resource-staff-edit-page .fi-header .fi-header-subheading {
        color: #334155;
        font-size: 0.92rem;
        line-height: 1.45;
        max-width: 42rem;
    }

    .fi-resource-staff-edit-page .fi-section {
        border-color: var(--staff-edit-border);
        border-radius: 1rem;
        background: var(--staff-edit-surface);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        overflow: visible;
    }

    .fi-resource-staff-edit-page .fi-section-content {
        padding: 1rem 1.1rem 1.15rem;
        overflow: visible;
    }

    .fi-resource-staff-edit-page .staff-edit-summary {
        border-color: rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
    }

    .fi-resource-staff-edit-page .staff-edit-summary .fi-section-content {
        padding-top: 1rem;
    }

    .fi-resource-staff-edit-page .staff-edit-summary .fi-section-description {
        max-width: 48rem;
    }

    .fi-resource-staff-edit-page .staff-edit-summary .fi-grid {
        gap: 0.85rem;
    }

    .fi-resource-staff-edit-page .staff-edit-summary__metric {
        min-height: 100%;
        padding: 0.95rem 1rem;
        border: 1px solid rgba(37, 99, 235, 0.12);
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.03);
    }

    .fi-resource-staff-edit-page .staff-edit-summary__label {
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: 0.01em;
        text-transform: uppercase;
        color: #1d4ed8;
        opacity: 0.78;
    }

    .fi-resource-staff-edit-page .staff-edit-summary__value {
        margin-top: 0.3rem;
        font-size: 1rem;
        font-weight: 800;
        line-height: 1.35;
        color: #0f172a;
    }

    .fi-resource-staff-edit-page .staff-edit-summary__note {
        margin-top: 0.25rem;
        font-size: 0.8rem;
        line-height: 1.35;
        color: #64748b;
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-summary {
        border-color: rgba(59, 130, 246, 0.2);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.82));
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-summary__metric {
        border-color: rgba(59, 130, 246, 0.18);
        background: rgba(15, 23, 42, 0.94);
        box-shadow: 0 6px 16px rgba(2, 6, 23, 0.22);
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-summary__label {
        color: #93c5fd;
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-summary__value {
        color: #e2e8f0;
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-summary__note {
        color: #94a3b8;
    }

    .fi-resource-staff-edit-page .staff-edit-main,
    .fi-resource-staff-edit-page .staff-edit-telegram,
    .fi-resource-staff-edit-page .staff-edit-security,
    .fi-resource-staff-edit-page .staff-edit-notifications {
        border-color: rgba(148, 163, 184, 0.18);
    }

    .fi-resource-staff-edit-page .staff-edit-main .fi-section-description,
    .fi-resource-staff-edit-page .staff-edit-telegram .fi-section-description,
    .fi-resource-staff-edit-page .staff-edit-security .fi-section-description,
    .fi-resource-staff-edit-page .staff-edit-notifications .fi-section-description {
        max-width: 52rem;
    }

    .fi-resource-staff-edit-page .staff-edit-access {
        background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
        border-color: rgba(59, 130, 246, 0.16);
    }

    .fi-resource-staff-edit-page .staff-edit-access .fi-fo-field-wrp-helper-text {
        max-width: 44rem;
    }

    .fi-resource-staff-edit-page .staff-edit-role-hint {
        display: grid;
        gap: 0.75rem;
        margin-top: 0.15rem;
    }

    .fi-resource-staff-edit-page .staff-edit-role-hint__item {
        padding: 0.9rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 0.95rem;
        background: rgba(248, 250, 252, 0.92);
    }

    .fi-resource-staff-edit-page .staff-edit-role-hint__title {
        display: block;
        color: #0f172a;
        font-size: 0.92rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .fi-resource-staff-edit-page .staff-edit-role-hint__copy {
        margin-top: 0.3rem;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.5;
    }

    .fi-resource-staff-edit-page .staff-edit-security {
        background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
        border-color: rgba(96, 165, 250, 0.18);
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-security {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.82));
        border-color: rgba(96, 165, 250, 0.18);
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-access {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.84));
        border-color: rgba(59, 130, 246, 0.18);
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-role-hint__item {
        border-color: rgba(148, 163, 184, 0.16);
        background: rgba(255, 255, 255, 0.04);
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-role-hint__title {
        color: #e2e8f0;
    }

    html.dark .fi-resource-staff-edit-page .staff-edit-role-hint__copy {
        color: #94a3b8;
    }

    .fi-resource-staff-edit-page .fi-input-wrp {
        border-color: #cfd9e8;
        background: #ffffff;
        transition: border-color .16s ease, box-shadow .16s ease;
    }

    .fi-resource-staff-edit-page .fi-input-wrp:focus-within {
        border-color: #5f8fdc;
        box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
    }

    .fi-resource-staff-edit-page .fi-fo-checkbox-list-option-label,
    .fi-resource-staff-edit-page .fi-fo-toggle label {
        font-size: 0.92rem;
    }

    @media (max-width: 1279px) {
        .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-ac,
        .fi-resource-staff-edit-page .staff-edit-hero__grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .fi-resource-staff-edit-page .staff-edit-summary .fi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767px) {
        .fi-resource-staff-edit-page .staff-edit-hero__top {
            flex-direction: column;
        }

        .fi-resource-staff-edit-page .staff-edit-hero__actions .fi-ac,
        .fi-resource-staff-edit-page .staff-edit-hero__grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .fi-resource-staff-edit-page .staff-edit-summary .fi-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .fi-resource-staff-edit-page .fi-section-content {
            padding-inline: 0.9rem;
        }
    }

    /* ====================================================================== */
    /* === Market space edit page: same card language as staff edit page   === */
    /* ====================================================================== */
    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page{
        --market-space-edit-border: #d8e3f1;
        --market-space-edit-surface: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero{
        display: grid;
        gap: 0;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.13), transparent 24%),
            linear-gradient(180deg, #f4f8ff 0%, #e8effa 100%);
        border: 1px solid #c5d4e8;
        border-radius: 1.25rem;
        padding: .9rem 1rem;
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__top{
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem 1rem;
        flex-wrap: wrap;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__main{
        min-width: 0;
        flex: 1 1 20rem;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__heading-row{
        display: flex;
        align-items: center;
        gap: .7rem;
        flex-wrap: wrap;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__heading{
        margin: 0;
        color: #0f172a;
        letter-spacing: -0.01em;
        font-size: clamp(1.18rem, 1rem + 1vw, 2rem);
        font-weight: 800;
        line-height: 1.05;
        max-width: 18ch;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__status{
        display: inline-flex;
        align-items: center;
        min-height: 2rem;
        padding: .32rem .72rem;
        border-radius: 999px;
        border: 1px solid transparent;
        font-size: .82rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__status--success{
        color: #11632d;
        background: rgba(220, 252, 231, 0.95);
        border-color: rgba(134, 239, 172, 0.9);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__status--danger{
        color: #b42318;
        background: rgba(254, 226, 226, 0.96);
        border-color: rgba(252, 165, 165, 0.92);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__status--warning{
        color: #a15c07;
        background: rgba(254, 243, 199, 0.96);
        border-color: rgba(253, 224, 71, 0.88);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__status--gray{
        color: #475569;
        background: rgba(241, 245, 249, 0.96);
        border-color: rgba(203, 213, 225, 0.95);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions{
        flex: 0 1 25.5rem;
        width: min(100%, 25.5rem);
        min-width: 25.5rem;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .fi-ac{
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .6rem;
        width: 100%;
        align-items: stretch;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action.fi-btn{
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        width: 100%;
        min-width: 12.25rem;
        min-height: 4.35rem;
        border-radius: .92rem;
        border: 1px solid #d8e3f1 !important;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
        color: #1f3251 !important;
        padding-block: .62rem !important;
        padding-inline: .72rem !important;
        text-align: center;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
        transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background-color .16s ease;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action.fi-btn:hover,
    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action.fi-btn:focus-visible{
        border-color: #c6d6e7 !important;
        transform: translateY(-1px);
        box-shadow: 0 11px 24px rgba(15, 23, 42, 0.08);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action.fi-btn > .fi-icon{
        width: 1.4rem;
        height: 1.4rem;
        margin: 0;
        border-radius: .45rem;
        background: rgba(215, 227, 255, 0.95);
        box-shadow: inset 0 0 0 1px rgba(170, 190, 231, 0.45);
        color: #1d4ed8 !important;
        flex: 0 0 auto;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action.fi-btn > .fi-btn-label{
        color: #0f172a;
        display: block;
        font-size: .9rem;
        font-weight: 700;
        line-height: 1.1;
        white-space: nowrap;
        text-align: center;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action--primary.fi-btn > .fi-icon{
        background: rgba(214, 229, 255, 0.95);
        color: #1d4ed8 !important;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action--secondary.fi-btn > .fi-icon{
        background: rgba(215, 227, 255, 0.95);
        color: #1d4ed8 !important;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action--danger.fi-btn{
        border-color: #f0c2c7 !important;
        background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%) !important;
        color: #b4323d !important;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-card-action--danger.fi-btn > .fi-icon{
        background: rgba(255, 223, 226, 0.98);
        box-shadow: inset 0 0 0 1px rgba(228, 152, 161, 0.42);
        color: #b4323d !important;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card{
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

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card:hover,
    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card:focus-visible{
        border-color: #a9c5ee;
        transform: translateY(-1px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card.is-active{
        border-color: #bfd4f3;
        background: linear-gradient(180deg, #fbfdff 0%, #eef4ff 100%);
        color: #1f4b95;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card.is-inactive{
        border-color: #f0c2c7;
        background: linear-gradient(180deg, #fffdfd 0%, #fff2f4 100%);
        color: #b4323d;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-copy{
        display: grid;
        gap: .2rem;
        min-width: 0;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-title{
        font-size: .92rem;
        font-weight: 700;
        line-height: 1.1;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-subtitle{
        font-size: .75rem;
        line-height: 1.25;
        opacity: .84;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-switch{
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        flex-shrink: 0;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-switch__track{
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

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card.is-active .market-space-hero-state-switch__track{
        background: rgba(37, 99, 235, 0.16);
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.18);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card.is-inactive .market-space-hero-state-switch__track{
        background: rgba(244, 63, 94, 0.16);
        box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.16);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-switch__thumb{
        width: 1.45rem;
        height: 1.45rem;
        border-radius: 999px;
        background: #ffffff;
        box-shadow: 0 8px 14px rgba(15, 23, 42, 0.12);
        transform: translateX(0);
        transition: transform .18s ease, background-color .16s ease;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .market-space-hero-state-card.is-active .market-space-hero-state-switch__thumb{
        transform: translateX(1.28rem);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .fi-section{
        border-color: var(--market-space-edit-border);
        border-radius: 1rem;
        background: var(--market-space-edit-surface);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        overflow: visible;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .fi-input-wrp{
        border-color: #cfd9e8;
        background: #ffffff;
        transition: border-color .16s ease, box-shadow .16s ease;
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .fi-input-wrp:focus-within{
        border-color: #5f8fdc;
        box-shadow: 0 0 0 4px rgba(95, 143, 220, 0.14);
    }

    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .fi-fo-checkbox-list-option-label,
    html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .fi-fo-toggle label{
        font-size: 0.92rem;
    }

    @media (max-width: 767px) {
        html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero{
            padding: .95rem;
        }

        html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions{
            width: 100%;
            min-width: 0;
        }

        html:not([data-admin-overrides="0"]) .fi-resource-market-spaces-edit-page .market-space-edit-hero__actions .fi-ac{
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .aw-shell--tenants {
        gap: 1.25rem;
    }

    .aw-hero--tenants {
        padding: 1.25rem;
    }

    .aw-hero-stack--tenants {
        display: grid;
        gap: 1rem;
    }

    .aw-hero-copy--tenants {
        max-width: 56rem;
    }

    .aw-hero-actions--tenants {
        grid-template-columns: repeat(auto-fit, minmax(16rem, max-content));
        gap: 0.85rem;
        justify-content: start;
    }

    .aw-link-card--tenant-action {
        min-height: 0;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .aw-link-card--tenant-primary {
        border-color: rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(239, 246, 255, 0.96));
    }

    html.dark .aw-link-card--tenant-primary {
        border-color: rgba(59, 130, 246, 0.22);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.8));
    }

    .aw-link-icon--tenant-action {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.85rem;
    }

    .aw-link-copy--tenant-action {
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .fi-resource-tenants-list-page .fi-sc-tabs {
        margin-top: 0.25rem;
        margin-bottom: 0.85rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-tenants-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-tenants-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .aw-shell--contracts {
        gap: 1.1rem;
    }

    .aw-hero--contracts {
        padding: 1.2rem 1.25rem;
    }

    .aw-hero-stack--contracts {
        display: grid;
        gap: 0.9rem;
    }

    .aw-hero-copy--contracts {
        max-width: 58rem;
    }

    .aw-inline-actions--contracts {
        margin-top: 0.15rem;
    }

    .aw-chip--contracts-context {
        border-color: rgba(37, 99, 235, 0.16);
        background: rgba(255, 255, 255, 0.72);
        color: #1e3a8a;
    }

    html.dark .aw-chip--contracts-context {
        border-color: rgba(59, 130, 246, 0.24);
        background: rgba(15, 23, 42, 0.56);
        color: #dbeafe;
    }

    .fi-resource-contracts-list-page .fi-sc-tabs {
        margin-top: 0.1rem;
        margin-bottom: 0.8rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-contracts-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-contracts-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .aw-shell--spaces {
        gap: 1.1rem;
    }

    .aw-hero--spaces {
        padding: 1.2rem 1.25rem;
    }

    .aw-hero-stack--spaces {
        display: grid;
        gap: 0.95rem;
    }

    .aw-hero-copy--spaces {
        max-width: 56rem;
    }

    .aw-hero-actions--spaces {
        grid-template-columns: repeat(auto-fit, minmax(16rem, max-content));
        gap: 0.85rem;
        justify-content: start;
    }

    .aw-link-card--space-action {
        min-height: 0;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .aw-link-card--space-primary {
        border-color: rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(239, 246, 255, 0.96));
    }

    html.dark .aw-link-card--space-primary {
        border-color: rgba(59, 130, 246, 0.22);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.8));
    }

    .aw-link-icon--space-action {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.85rem;
    }

    .aw-link-copy--space-action {
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .fi-resource-market-spaces-list-page .fi-sc-tabs {
        margin-top: 0.25rem;
        margin-bottom: 0.85rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-market-spaces-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-market-spaces-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .aw-shell--accruals {
        gap: 1.1rem;
    }

    .aw-hero--accruals {
        padding: 1.2rem 1.25rem;
    }

    .aw-hero-stack--accruals {
        display: grid;
        gap: 0.9rem;
    }

    .aw-hero-copy--accruals {
        max-width: 58rem;
    }

    .aw-inline-actions--accruals {
        margin-top: 0.1rem;
    }

    .aw-chip--accruals-context {
        border-color: rgba(37, 99, 235, 0.16);
        background: rgba(255, 255, 255, 0.72);
        color: #1e3a8a;
    }

    html.dark .aw-chip--accruals-context {
        border-color: rgba(59, 130, 246, 0.24);
        background: rgba(15, 23, 42, 0.56);
        color: #dbeafe;
    }

    .fi-resource-accruals-list-page .fi-sc-tabs {
        margin-top: 0.1rem;
        margin-bottom: 0.8rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-accruals-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-accruals-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .aw-shell--tasks {
        gap: 1.1rem;
    }

    .aw-hero--tasks {
        padding: 1.2rem 1.25rem;
    }

    .aw-hero-stack--tasks {
        display: grid;
        gap: 0.95rem;
    }

    .aw-hero-copy--tasks {
        max-width: 58rem;
    }

    .aw-tasks-toolbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .aw-tasks-toolbar__main {
        display: grid;
        gap: 0.85rem;
        justify-items: start;
        min-width: 0;
    }

    .aw-inline-actions--tasks {
        margin-top: 0;
        align-items: stretch;
    }

    .aw-link-card--task-action {
        min-height: 0;
        gap: 0.75rem;
        align-items: center;
        padding: 0.8rem 0.95rem;
    }

    .aw-link-card--task-primary {
        border-color: rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(239, 246, 255, 0.96));
    }

    html.dark .aw-link-card--task-primary {
        border-color: rgba(59, 130, 246, 0.22);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.8));
    }

    .aw-link-icon--task-action {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.85rem;
    }

    .aw-link-copy--task-action {
        margin-top: 0.2rem;
        line-height: 1.45;
    }

    .fi-resource-tasks-list-page .fi-sc-tabs {
        margin-top: 0.15rem;
        margin-bottom: 0.85rem;
        justify-self: start;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-tasks-list-page .fi-sc-tabs .fi-tabs {
        margin-inline: 0;
        width: max-content;
        max-width: 100%;
    }

    .fi-resource-tasks-list-page .fi-sc-tabs + .fi-ta {
        margin-top: 0;
    }

    .aw-shell--calendar {
        gap: 1.1rem;
    }

    .aw-hero--calendar {
        padding: 1.2rem 1.25rem;
    }

    .aw-hero-stack--calendar {
        display: grid;
        gap: 0.9rem;
    }

    .aw-hero-copy--calendar {
        max-width: 58rem;
    }

    .aw-calendar-toolbar {
        display: grid;
        gap: 0.9rem;
    }

    .aw-calendar-toolbar__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .aw-calendar-toolbar__main {
        display: grid;
        gap: 0.9rem;
        min-width: 0;
        justify-items: start;
    }

    .aw-inline-actions--calendar {
        margin-top: 0;
    }

    .aw-link-card--calendar-primary {
        border-color: rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(239, 246, 255, 0.96));
    }

    html.dark .aw-link-card--calendar-primary {
        border-color: rgba(59, 130, 246, 0.22);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.8));
    }

    .aw-link-icon--calendar-action {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.85rem;
    }

    .aw-link-copy--calendar-action {
        margin-top: 0.25rem;
        line-height: 1.45;
    }

    .aw-calendar-cta {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        min-height: 3.5rem;
        margin-left: auto;
        padding: 0.7rem 1rem;
        border-radius: 1rem;
        border: 1px solid rgba(37, 99, 235, 0.18);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(239, 246, 255, 0.96));
        color: #0f172a;
        font-size: 1rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        transition: transform 150ms ease, border-color 150ms ease, box-shadow 150ms ease;
    }

    .aw-calendar-cta:hover {
        transform: translateY(-1px);
        border-color: rgba(37, 99, 235, 0.28);
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.10);
    }

    html.dark .aw-calendar-cta {
        border-color: rgba(59, 130, 246, 0.22);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.8));
        color: #f8fafc;
        box-shadow: 0 12px 24px rgba(2, 6, 23, 0.22);
    }

    .aw-view-switch {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        width: max-content;
        max-width: 100%;
        padding: 0.25rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.72);
    }

    html.dark .aw-view-switch {
        border-color: rgba(148, 163, 184, 0.18);
        background: rgba(15, 23, 42, 0.56);
    }

    .aw-view-switch__item {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 6rem;
        padding: 0.5rem 0.85rem;
        border-radius: 999px;
        color: #475569;
        font-size: 0.88rem;
        font-weight: 600;
        text-decoration: none;
        transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
    }

    html.dark .aw-view-switch__item {
        color: #cbd5e1;
    }

    .aw-view-switch__item:hover {
        color: #0f172a;
    }

    html.dark .aw-view-switch__item:hover {
        color: #f8fafc;
    }

    .aw-view-switch__item.is-active {
        background: #2563eb;
        color: #fff;
        box-shadow: 0 10px 20px rgba(37, 99, 235, 0.22);
    }

    .aw-chip--calendar-context {
        border-color: rgba(37, 99, 235, 0.16);
        background: rgba(255, 255, 255, 0.72);
        color: #1e3a8a;
        transition: opacity 150ms ease, transform 150ms ease;
    }

    html.dark .aw-chip--calendar-context {
        border-color: rgba(59, 130, 246, 0.24);
        background: rgba(15, 23, 42, 0.56);
        color: #dbeafe;
    }

    .aw-inline-actions--calendar.is-pending .aw-chip--calendar-context {
        opacity: 0.72;
        transform: translateY(1px);
    }

    .aw-content-switcher {
        position: relative;
        min-height: 12rem;
    }

    .aw-content-switcher__body {
        transition: opacity 150ms ease, transform 150ms ease;
    }

    .aw-content-switcher__body.is-loading {
        opacity: 0.42;
        transform: translateY(4px);
    }

    .aw-content-switcher__overlay {
        position: absolute;
        inset: 0;
        z-index: 10;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding-top: 1.25rem;
        pointer-events: none;
    }

    .aw-content-switcher__spinner {
        width: 2rem;
        height: 2rem;
        border: 2px solid rgba(37, 99, 235, 0.18);
        border-top-color: #2563eb;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.72);
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        animation: aw-spin 0.7s linear infinite;
    }

    html.dark .aw-content-switcher__spinner {
        border-color: rgba(59, 130, 246, 0.22);
        border-top-color: #60a5fa;
        background: rgba(15, 23, 42, 0.76);
        box-shadow: 0 12px 24px rgba(2, 6, 23, 0.18);
    }

    @keyframes aw-spin {
        to {
            transform: rotate(360deg);
        }
    }

    @media (max-width: 767px) {
        .aw-tasks-toolbar {
            align-items: stretch;
        }

        .aw-inline-actions--tasks {
            width: 100%;
            flex-direction: column;
        }

        .aw-link-card--task-action {
            width: 100%;
        }

        .aw-calendar-toolbar__top {
            align-items: stretch;
        }

        .aw-calendar-toolbar__main {
            min-width: 0;
        }

        .aw-calendar-cta {
            width: 100%;
            justify-content: center;
            margin-left: 0;
        }
    }
</style>
