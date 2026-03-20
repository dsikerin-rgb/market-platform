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
</style>
