<x-filament-panels::page>
    <div>
    @include('filament.partials.admin-workspace-styles')

    @once
        <style>
            .mrr-table-wrap {
                overflow-x: auto;
            }

            .mrr-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.875rem;
            }

            .mrr-table th,
            .mrr-table td {
                padding: 0.72rem 0.78rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                text-align: left;
                vertical-align: top;
            }

            .dark .mrr-table th,
            .dark .mrr-table td {
                border-bottom-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-table th {
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-table th {
                color: #94a3b8;
            }

            .mrr-place {
                display: flex;
                flex-direction: column;
                gap: 0.18rem;
            }

            .mrr-place__title {
                font-weight: 700;
                color: #0f172a;
            }

            .dark .mrr-place__title {
                color: #f8fafc;
            }

            .mrr-place__meta {
                font-size: 0.8125rem;
                color: #64748b;
            }

            .dark .mrr-place__meta {
                color: #94a3b8;
            }

            .mrr-place__statusline {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.3rem;
                margin-top: 0.1rem;
            }

            .mrr-place__decision {
                margin-top: 0.42rem;
                padding: 0.42rem 0.55rem;
                border-radius: 0.625rem;
                background: rgba(248, 250, 252, 0.85);
                border: 1px solid rgba(148, 163, 184, 0.18);
                display: flex;
                flex-direction: column;
                gap: 0.12rem;
            }

            .dark .mrr-place__decision {
                background: rgba(30, 41, 59, 0.55);
                border-color: rgba(71, 85, 105, 0.35);
            }

            .mrr-place__decision-label {
                font-size: 0.8125rem;
                font-weight: 600;
                color: #0f172a;
            }

            .dark .mrr-place__decision-label {
                color: #f1f5f9;
            }

            .mrr-place__decision-reason {
                font-size: 0.75rem;
                color: #475569;
            }

            .dark .mrr-place__decision-reason {
                color: #94a3b8;
            }

            .mrr-place__decision-details {
                display: grid;
                gap: 0.4rem;
                margin-top: 0.55rem;
                padding-top: 0.55rem;
                border-top: 1px solid rgba(148, 163, 184, 0.14);
            }

            .dark .mrr-place__decision-details {
                border-top-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-place__decision-detail {
                display: grid;
                grid-template-columns: minmax(0, 11rem) minmax(0, 1fr);
                gap: 0.5rem;
                align-items: start;
            }

            .mrr-place__decision-detail-label {
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-place__decision-detail-label {
                color: #94a3b8;
            }

            .mrr-place__decision-detail-value {
                min-width: 0;
                font-size: 0.78rem;
                line-height: 1.4;
                color: #0f172a;
                word-break: break-word;
            }

            .dark .mrr-place__decision-detail-value {
                color: #e2e8f0;
            }

            .mrr-place__decision-meta {
                font-size: 0.6875rem;
                color: #94a3b8;
            }

            .dark .mrr-place__decision-meta {
                color: #64748b;
            }

            .mrr-place__decision-swap {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
                gap: 0.5rem;
                align-items: center;
                margin-top: 0.45rem;
                padding: 0.65rem 0.7rem;
                border-radius: 0.75rem;
                background: rgba(255, 255, 255, 0.75);
                border: 1px solid rgba(37, 99, 235, 0.12);
            }

            .dark .mrr-place__decision-swap {
                background: rgba(15, 23, 42, 0.45);
                border-color: rgba(96, 165, 250, 0.22);
            }

            .mrr-place__decision-side {
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.18rem;
            }

            .mrr-place__decision-side-label {
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-place__decision-side-label {
                color: #94a3b8;
            }

            .mrr-place__decision-side-value {
                font-size: 0.96rem;
                font-weight: 800;
                line-height: 1.2;
                color: #0f172a;
                word-break: break-word;
            }

            .dark .mrr-place__decision-side-value {
                color: #f8fafc;
            }

            .mrr-place__decision-side-value--target {
                color: #1d4ed8;
            }

            .dark .mrr-place__decision-side-value--target {
                color: #93c5fd;
            }

            .mrr-place__decision-arrow {
                font-size: 1.25rem;
                font-weight: 900;
                line-height: 1;
                color: #2563eb;
            }

            .dark .mrr-place__decision-arrow {
                color: #93c5fd;
            }

            .mrr-place__decision-facts {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
                margin-top: 0.45rem;
            }

            .mrr-place__decision-fact {
                display: inline-flex;
                align-items: center;
                gap: 0.2rem;
                padding: 0.22rem 0.5rem;
                border-radius: 999px;
                background: rgba(239, 246, 255, 0.9);
                border: 1px solid rgba(37, 99, 235, 0.12);
                font-size: 0.72rem;
                color: #1e3a8a;
            }

            .dark .mrr-place__decision-fact {
                background: rgba(30, 64, 175, 0.18);
                border-color: rgba(96, 165, 250, 0.24);
                color: #bfdbfe;
            }

            .mrr-place__decision-hint {
                margin-top: 0.4rem;
                font-size: 0.74rem;
                line-height: 1.35;
                color: #475569;
            }

            .dark .mrr-place__decision-hint {
                color: #cbd5e1;
            }

            .mrr-table--needs th:nth-child(1) {
                width: 24%;
            }

            .mrr-table--needs th:nth-child(2) {
                width: 40%;
            }

            .mrr-table--needs th:nth-child(3) {
                width: 36%;
            }

            .mrr-badge {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                padding: 0.22rem 0.6rem;
                font-size: 0.75rem;
                font-weight: 700;
                line-height: 1.25;
                white-space: nowrap;
            }

            .mrr-badge--matched {
                background: rgba(34, 197, 94, 0.14);
                color: #15803d;
            }

            .mrr-badge--changed {
                background: rgba(59, 130, 246, 0.14);
                color: #1d4ed8;
            }

            .mrr-badge--changed_tenant,
            .mrr-badge--conflict,
            .mrr-badge--not_found {
                background: rgba(239, 68, 68, 0.14);
                color: #b91c1c;
            }

            .mrr-badge--success {
                background: rgba(34, 197, 94, 0.14);
                color: #15803d;
            }

            .mrr-badge--unconfirmed_link {
                background: rgba(14, 165, 233, 0.16);
                color: #0369a1;
            }

            .mrr-assessment {
                display: inline-flex;
                width: fit-content;
                align-items: center;
                border-radius: 999px;
                padding: 0.28rem 0.65rem;
                font-size: 0.75rem;
                font-weight: 800;
                line-height: 1.25;
            }

            .mrr-assessment--danger {
                background: rgba(239, 68, 68, 0.12);
                color: #b91c1c;
            }

            .mrr-assessment--warning {
                background: rgba(245, 158, 11, 0.12);
                color: #92400e;
            }

            .mrr-assessment--neutral {
                background: rgba(100, 116, 139, 0.12);
                color: #475569;
            }

            .dark .mrr-assessment--danger {
                color: #fecaca;
            }

            .dark .mrr-assessment--warning {
                color: #fde68a;
            }

            .dark .mrr-assessment--neutral {
                color: #cbd5e1;
            }

            .mrr-links {
                display: flex;
                flex-wrap: wrap;
                gap: 0.38rem;
            }

            .mrr-quick-launcher {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                margin-top: 0.42rem;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.18);
                background: rgba(255, 255, 255, 0.9);
                padding: 0.34rem 0.7rem;
                font-size: 0.78rem;
                font-weight: 800;
                color: #1d4ed8;
                cursor: pointer;
                font-family: inherit;
                line-height: 1.2;
            }

            .mrr-quick-launcher::before {
                content: "•";
                font-size: 0.95rem;
                line-height: 1;
            }

            .dark .mrr-quick-launcher {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(15, 23, 42, 0.55);
                color: #bfdbfe;
            }

            .mrr-quick-review__choices {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .mrr-quick-review__choice {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.16);
                background: rgba(255, 255, 255, 0.88);
                padding: 0.22rem 0.52rem;
                font-size: 0.72rem;
                font-weight: 700;
                color: #1d4ed8;
                cursor: pointer;
                font-family: inherit;
                line-height: 1.2;
            }

            .mrr-quick-review__choice--danger {
                border-color: rgba(185, 28, 28, 0.2);
                color: #b91c1c;
            }

            .mrr-quick-review__choice--success {
                border-color: rgba(34, 197, 94, 0.22);
                color: #15803d;
                background: rgba(240, 253, 244, 0.96);
            }

            .mrr-quick-review__choice.is-selected {
                box-shadow: inset 0 0 0 1px currentColor;
            }

            .dark .mrr-quick-review__choice {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(15, 23, 42, 0.62);
                color: #bfdbfe;
            }

            .dark .mrr-quick-review__choice--danger {
                border-color: rgba(248, 113, 113, 0.28);
                color: #fecaca;
            }

            .dark .mrr-quick-review__choice--success {
                border-color: rgba(74, 222, 128, 0.28);
                background: rgba(15, 23, 42, 0.62);
                color: #bbf7d0;
            }

            .mrr-needs-list {
                display: flex;
                flex-direction: column;
                gap: 0.8rem;
            }

            .mrr-attention-filters {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.45rem;
                margin-bottom: 0.9rem;
            }

            .mrr-attention-search {
                margin-bottom: 0.75rem;
            }

            .mrr-attention-search__input {
                width: 100%;
                border-radius: 0.875rem;
                border: 1px solid rgba(148, 163, 184, 0.24);
                background: rgba(255, 255, 255, 0.92);
                padding: 0.72rem 0.9rem;
                font-size: 0.88rem;
                color: #0f172a;
            }

            .mrr-attention-search__input::placeholder {
                color: #94a3b8;
            }

            .dark .mrr-attention-search__input {
                border-color: rgba(148, 163, 184, 0.2);
                background: rgba(15, 23, 42, 0.48);
                color: #f8fafc;
            }

            .mrr-attention-filter {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                border: 1px solid rgba(148, 163, 184, 0.22);
                background: rgba(248, 250, 252, 0.7);
                padding: 0.38rem 0.75rem;
                font-size: 0.78rem;
                font-weight: 600;
                color: #475569;
                cursor: pointer;
                transition: all 0.16s ease;
            }

            .dark .mrr-attention-filter {
                border-color: rgba(148, 163, 184, 0.2);
                background: rgba(15, 23, 42, 0.35);
                color: #cbd5e1;
            }

            .mrr-attention-filter:hover {
                border-color: rgba(59, 130, 246, 0.35);
                color: #1d4ed8;
            }

            .dark .mrr-attention-filter:hover {
                color: #93c5fd;
            }

            .mrr-attention-filter.is-active {
                border-color: #2563eb;
                background: #2563eb;
                color: #fff;
            }

            .dark .mrr-attention-filter.is-active {
                border-color: #3b82f6;
                background: #3b82f6;
            }

            .mrr-attention-filter-count {
                margin-left: auto;
                font-size: 0.75rem;
                color: #64748b;
                font-weight: 500;
            }

            .dark .mrr-attention-filter-count {
                color: #94a3b8;
            }

            .mrr-needs-card.is-hidden {
                display: none;
            }

            .mrr-attention-no-results {
                text-align: center;
                padding: 1.2rem;
                color: #64748b;
                font-size: 0.85rem;
            }

            .dark .mrr-attention-no-results {
                color: #94a3b8;
            }

            .mrr-needs-card {
                border-radius: 1rem;
                border: 1px solid rgba(148, 163, 184, 0.12);
                background: rgba(248, 250, 252, 0.7);
                padding: 0.85rem 0.9rem;
            }

            .dark .mrr-needs-card {
                border-color: rgba(148, 163, 184, 0.12);
                background: rgba(15, 23, 42, 0.34);
            }

            .mrr-needs-card--priority {
                border-color: rgba(59, 130, 246, 0.22);
                background: rgba(239, 246, 255, 0.88);
            }

            .dark .mrr-needs-card--priority {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(15, 23, 42, 0.42);
            }

            .mrr-needs-card > summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                cursor: pointer;
                list-style: none;
                outline: none;
            }

            .mrr-needs-card > summary::-webkit-details-marker {
                display: none;
            }

            .mrr-needs-card__summary-main {
                min-width: 0;
                display: flex;
                flex: 1;
                flex-direction: column;
                justify-content: center;
                gap: 0.22rem;
            }

            .mrr-needs-card__summary-top {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 0.35rem;
            }

            .mrr-needs-card__summary-top-main {
                min-width: 0;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem;
            }

            .mrr-needs-card__summary-meta {
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                gap: 0.08rem;
                text-align: right;
            }

            .mrr-needs-card__summary-meta-label,
            .mrr-needs-card__summary-meta-value {
                font-size: 0.72rem;
                line-height: 1.25;
                color: #64748b;
            }

            .mrr-needs-card__summary-meta-label {
                font-weight: 700;
            }

            .dark .mrr-needs-card__summary-meta-label,
            .dark .mrr-needs-card__summary-meta-value {
                color: #94a3b8;
            }

            .mrr-needs-card__summary-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.22rem;
                align-items: start;
            }

            .mrr-needs-card__summary-place,
            .mrr-needs-card__summary-brief {
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.12rem;
            }

            .mrr-needs-card__summary-place {
                align-items: flex-start;
            }

            .mrr-needs-card__summary-brief {
                align-items: flex-start;
                justify-content: flex-start;
                text-align: left;
            }

            .mrr-needs-card__decision-label {
                font-size: 0.8125rem;
                font-weight: 700;
                color: #0f172a;
                text-align: left;
            }

            .dark .mrr-needs-card__decision-label {
                color: #f8fafc;
            }

            .mrr-needs-card__reason {
                font-size: 0.73rem;
                color: #475569;
                display: -webkit-box;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                line-height: 1.35;
                text-align: left;
            }

            .dark .mrr-needs-card__reason {
                color: #94a3b8;
            }

            .mrr-needs-card__toggle {
                display: inline-flex;
                flex-shrink: 0;
                align-items: center;
                justify-content: flex-end;
                min-width: 5.5rem;
                padding: 0.18rem 0;
                font-size: 0.75rem;
                font-weight: 800;
                color: #1d4ed8;
                white-space: nowrap;
            }

            .dark .mrr-needs-card__toggle {
                color: #bfdbfe;
            }

            .mrr-needs-card__toggle-close {
                display: none;
            }

            .mrr-needs-card[open] .mrr-needs-card__toggle-open {
                display: none;
            }

            .mrr-needs-card[open] .mrr-needs-card__toggle-close {
                display: inline;
            }

            .mrr-needs-card__body {
                margin-top: 0.85rem;
                padding-top: 0.85rem;
                border-top: 1px solid rgba(148, 163, 184, 0.14);
            }

            .dark .mrr-needs-card__body {
                border-top-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-needs-card__body-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.15fr) minmax(0, 1fr);
                gap: 0.85rem;
                align-items: start;
            }

            .mrr-needs-card__column {
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.6rem;
            }

            .mrr-needs-card--conflict-layout .mrr-needs-card__body-grid {
                grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
                gap: 0.95rem;
            }

            .mrr-needs-card--conflict-layout .mrr-needs-card__column--ai {
                grid-column: 1 / -1;
            }

            .mrr-conflict-brief {
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.94) 0%, rgba(248, 250, 252, 0.9) 100%);
                padding: 0.85rem 0.9rem;
                display: flex;
                flex-direction: column;
                gap: 0.55rem;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
            }

            .dark .mrr-conflict-brief {
                border-color: rgba(148, 163, 184, 0.14);
                background: linear-gradient(135deg, rgba(15, 23, 42, 0.82) 0%, rgba(30, 41, 59, 0.72) 100%);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
            }

            .mrr-conflict-brief__eyebrow {
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-conflict-brief__eyebrow {
                color: #94a3b8;
            }

            .mrr-conflict-brief__headline {
                font-size: 1rem;
                font-weight: 800;
                line-height: 1.25;
                color: #0f172a;
            }

            .dark .mrr-conflict-brief__headline {
                color: #f8fafc;
            }

            .mrr-conflict-brief__copy {
                font-size: 0.84rem;
                line-height: 1.45;
                color: #475569;
            }

            .dark .mrr-conflict-brief__copy {
                color: #cbd5e1;
            }

            .mrr-conflict-brief__hint {
                display: flex;
                flex-direction: column;
                gap: 0.18rem;
                border-radius: 0.85rem;
                border: 1px solid rgba(37, 99, 235, 0.16);
                background: rgba(37, 99, 235, 0.06);
                padding: 0.58rem 0.68rem;
            }

            .dark .mrr-conflict-brief__hint {
                border-color: rgba(96, 165, 250, 0.22);
                background: rgba(30, 64, 175, 0.18);
            }

            .mrr-conflict-brief__hint-label {
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #1d4ed8;
            }

            .dark .mrr-conflict-brief__hint-label {
                color: #bfdbfe;
            }

            .mrr-conflict-brief__hint-text {
                font-size: 0.84rem;
                line-height: 1.45;
                color: #1e293b;
            }

            .dark .mrr-conflict-brief__hint-text {
                color: #e2e8f0;
            }

            .mrr-conflict-brief__facts {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
            }

            .mrr-conflict-brief__fact {
                display: inline-flex;
                align-items: center;
                gap: 0.2rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: rgba(255, 255, 255, 0.88);
                padding: 0.26rem 0.56rem;
                font-size: 0.74rem;
                font-weight: 700;
                color: #334155;
            }

            .dark .mrr-conflict-brief__fact {
                border-color: rgba(148, 163, 184, 0.16);
                background: rgba(15, 23, 42, 0.78);
                color: #cbd5e1;
            }

            @media (max-width: 1140px) {
                .mrr-needs-card__body-grid {
                    grid-template-columns: 1fr;
                }

                .mrr-needs-card__summary-top {
                    align-items: flex-start;
                }

                .mrr-needs-card__summary-meta {
                    align-items: flex-start;
                    text-align: left;
                }
            }

            .mrr-link {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.1);
                padding: 0.36rem 0.64rem;
                font-size: 0.8rem;
                font-weight: 600;
                color: #0f172a;
                text-decoration: none;
            }

            .dark .mrr-link {
                border-color: rgba(148, 163, 184, 0.18);
                color: #f8fafc;
            }

            .mrr-link--button {
                background: transparent;
                appearance: none;
                cursor: pointer;
                font: inherit;
                line-height: inherit;
            }

            .mrr-link--primary {
                border-color: rgba(37, 99, 235, 0.22);
                background: #2563eb;
                color: #fff;
                box-shadow: 0 8px 18px rgba(37, 99, 235, 0.16);
            }

            .mrr-link--primary:hover {
                background: #1d4ed8;
                color: #fff;
            }

            .dark .mrr-link--primary {
                border-color: rgba(96, 165, 250, 0.28);
                background: #3b82f6;
                color: #fff;
            }

            .mrr-link--success {
                border-color: rgba(22, 163, 74, 0.24);
                background: #16a34a;
                color: #fff;
                box-shadow: 0 8px 18px rgba(22, 163, 74, 0.14);
            }

            .mrr-link--success:hover {
                background: #15803d;
                color: #fff;
            }

            .dark .mrr-link--success {
                border-color: rgba(74, 222, 128, 0.28);
                background: #22c55e;
                color: #052e16;
            }

            .mrr-link--disabled,
            .mrr-link--disabled:hover {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(148, 163, 184, 0.1);
                color: #94a3b8;
                cursor: not-allowed;
                pointer-events: none;
            }

            .dark .mrr-link--disabled,
            .dark .mrr-link--disabled:hover {
                border-color: rgba(148, 163, 184, 0.22);
                background: rgba(71, 85, 105, 0.28);
                color: #94a3b8;
            }

            .mrr-card-actions {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.72rem;
            }

            .mrr-card-actions__group {
                display: flex;
                min-width: 0;
                flex-direction: column;
                gap: 0.34rem;
            }

            .mrr-card-actions__group--primary {
                padding-bottom: 0.72rem;
                border-bottom: 1px solid rgba(148, 163, 184, 0.14);
            }

            .dark .mrr-card-actions__group--primary {
                border-bottom-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-card-actions__label {
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                line-height: 1.2;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-card-actions__label {
                color: #94a3b8;
            }

            .mrr-card-actions__hint {
                font-size: 0.78rem;
                line-height: 1.45;
                color: #64748b;
            }

            .dark .mrr-card-actions__hint {
                color: #94a3b8;
            }

            .mrr-card-actions__row {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
            }

            .mrr-diagnostics {
                display: flex;
                min-width: 17rem;
                flex-direction: column;
                gap: 0.45rem;
            }

            .mrr-diagnostics__section {
                display: flex;
                flex-direction: column;
                gap: 0.42rem;
            }

            .mrr-diagnostics__actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
                margin-bottom: 0.65rem;
            }

            .mrr-diagnostics__actions .mrr-link {
                border-color: rgba(37, 99, 235, 0.2);
                background: rgba(37, 99, 235, 0.08);
                color: #1d4ed8;
            }

            .mrr-diagnostics__actions .mrr-link:hover {
                border-color: rgba(37, 99, 235, 0.32);
                background: rgba(37, 99, 235, 0.14);
                color: #1d4ed8;
            }

            .dark .mrr-diagnostics__actions .mrr-link {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(30, 64, 175, 0.22);
                color: #dbeafe;
            }

            .dark .mrr-diagnostics__actions .mrr-link:hover {
                border-color: rgba(147, 197, 253, 0.34);
                background: rgba(37, 99, 235, 0.3);
                color: #eff6ff;
            }

            .mrr-diagnostics__intro {
                font-size: 0.8rem;
                line-height: 1.4;
                color: #64748b;
            }

            .dark .mrr-diagnostics__intro {
                color: #94a3b8;
            }

            .mrr-diagnostics__summary {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .mrr-diagnostics__section-title {
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #334155;
            }

            .dark .mrr-diagnostics__section-title {
                color: #e2e8f0;
            }

            .mrr-diagnostics__counts {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .mrr-diagnostics__count {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.09);
                background: rgba(248, 250, 252, 0.9);
                padding: 0.2rem 0.5rem;
                font-size: 0.74rem;
                font-weight: 700;
                color: #475569;
                white-space: nowrap;
            }

            .mrr-diagnostics__count--important {
                border-color: rgba(37, 99, 235, 0.18);
                background: rgba(239, 246, 255, 0.95);
                color: #1d4ed8;
            }

            .dark .mrr-diagnostics__count {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(15, 23, 42, 0.72);
                color: #cbd5e1;
            }

            .dark .mrr-diagnostics__count--important {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(30, 64, 175, 0.24);
                color: #bfdbfe;
            }

            .mrr-diagnostics__hint {
                font-size: 0.78rem;
                line-height: 1.35;
                color: #64748b;
            }

            .dark .mrr-diagnostics__hint {
                color: #94a3b8;
            }

            .mrr-diagnostics__assessment {
                max-width: 48rem;
                font-size: 0.76rem;
                line-height: 1.35;
                margin-top: 0.05rem;
                margin-bottom: 0.05rem;
                color: #64748b;
            }

            .mrr-diagnostics__candidates {
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
            }

            .mrr-diagnostics__candidate {
                display: grid;
                gap: 0.32rem;
                border-left: 2px solid rgba(37, 99, 235, 0.26);
                border-radius: 0.75rem;
                background: rgba(248, 250, 252, 0.9);
                padding: 0.55rem 0.65rem;
            }

            .dark .mrr-diagnostics__candidate {
                background: rgba(15, 23, 42, 0.6);
                border-left-color: rgba(96, 165, 250, 0.35);
            }

            .mrr-diagnostics__candidate-main {
                font-size: 0.82rem;
                font-weight: 700;
                color: #1d4ed8;
                text-decoration: none;
            }

            .dark .mrr-diagnostics__candidate-main {
                color: #93c5fd;
            }

            .mrr-diagnostics__candidate-meta {
                font-size: 0.75rem;
                color: #64748b;
            }

            .dark .mrr-diagnostics__candidate-meta {
                color: #94a3b8;
            }

            .mrr-diagnostics__candidate-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .mrr-diagnostics__candidate-action {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.18);
                background: transparent;
                padding: 0.18rem 0.48rem;
                font-size: 0.72rem;
                font-weight: 700;
                color: #1d4ed8;
                cursor: pointer;
                font-family: inherit;
                line-height: inherit;
                text-decoration: none;
            }

            .dark .mrr-diagnostics__candidate-action {
                border-color: rgba(96, 165, 250, 0.3);
                color: #bfdbfe;
            }

            .mrr-diagnostics__candidate-action--disabled,
            .mrr-diagnostics__candidate-action--disabled:hover {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(148, 163, 184, 0.1);
                color: #94a3b8;
                cursor: not-allowed;
                pointer-events: none;
            }

            .dark .mrr-diagnostics__candidate-action--disabled,
            .dark .mrr-diagnostics__candidate-action--disabled:hover {
                border-color: rgba(148, 163, 184, 0.22);
                background: rgba(71, 85, 105, 0.28);
                color: #94a3b8;
            }

            .mrr-diagnostics__details {
                border-radius: 0.8rem;
                border: 1px solid rgba(148, 163, 184, 0.12);
                background: rgba(248, 250, 252, 0.52);
                padding: 0.45rem 0.55rem;
            }

            .dark .mrr-diagnostics__details {
                border-color: rgba(148, 163, 184, 0.12);
                background: rgba(15, 23, 42, 0.28);
            }

            .mrr-diagnostics__details > summary {
                cursor: pointer;
                list-style: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 2.5rem;
                width: 100%;
                border-radius: 0.95rem;
                border: 1px solid rgba(37, 99, 235, 0.18);
                background: rgba(37, 99, 235, 0.08);
                padding: 0.5rem 0.8rem;
                font-size: 0.82rem;
                font-weight: 800;
                color: #1d4ed8;
                outline: none;
                text-align: center;
                transition: border-color .18s ease, background-color .18s ease, color .18s ease;
            }

            .mrr-diagnostics__details > summary::-webkit-details-marker {
                display: none;
            }

            .mrr-diagnostics__details > summary:hover {
                border-color: rgba(37, 99, 235, 0.3);
                background: rgba(37, 99, 235, 0.14);
            }

            .dark .mrr-diagnostics__details > summary {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(30, 64, 175, 0.22);
                color: #dbeafe;
            }

            .dark .mrr-diagnostics__details > summary:hover {
                border-color: rgba(147, 197, 253, 0.34);
                background: rgba(37, 99, 235, 0.3);
                color: #eff6ff;
            }

            .mrr-diagnostics__details-body {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                margin-top: 0.45rem;
            }

            .mrr-diagnostics__compare {
                display: grid;
                gap: 0.55rem;
                border-radius: 1rem;
                border: 1px solid rgba(37, 99, 235, 0.14);
                background: rgba(37, 99, 235, 0.06);
                padding: 0.7rem;
            }

            .dark .mrr-diagnostics__compare {
                border-color: rgba(96, 165, 250, 0.2);
                background: rgba(30, 64, 175, 0.16);
            }

            .mrr-diagnostics__compare-title {
                font-size: 0.74rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #475569;
            }

            .dark .mrr-diagnostics__compare-title {
                color: #cbd5e1;
            }

            .mrr-diagnostics__compare-copy {
                font-size: 0.8rem;
                line-height: 1.45;
                color: #64748b;
            }

            .dark .mrr-diagnostics__compare-copy {
                color: #94a3b8;
            }

            .mrr-diagnostics__compare-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.45rem;
            }

            .mrr-diagnostics__detail-list {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .mrr-diagnostics__detail-item {
                display: flex;
                flex-direction: column;
                gap: 0.16rem;
                border-radius: 0.85rem;
                border: 1px solid rgba(148, 163, 184, 0.14);
                background: rgba(255, 255, 255, 0.72);
                padding: 0.55rem 0.65rem;
            }

            .dark .mrr-diagnostics__detail-item {
                border-color: rgba(148, 163, 184, 0.14);
                background: rgba(15, 23, 42, 0.36);
            }

            .mrr-diagnostics__detail-title {
                font-size: 0.76rem;
                font-weight: 800;
                color: #334155;
            }

            .dark .mrr-diagnostics__detail-title {
                color: #e2e8f0;
            }

            .mrr-diagnostics__detail-copy {
                font-size: 0.76rem;
                line-height: 1.45;
                color: #64748b;
            }

            .dark .mrr-diagnostics__detail-copy {
                color: #94a3b8;
            }

            .mrr-duplicate-plan__grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.8rem;
                margin-top: 1rem;
            }

            .mrr-duplicate-plan-modal .mrr-clarify-modal__dialog {
                width: min(1040px, 100%);
                max-height: calc(100dvh - 2rem);
                overflow-y: auto;
                padding: 1rem;
                gap: 0.7rem;
            }

            .mrr-duplicate-plan-modal .mrr-clarify-modal__description {
                line-height: 1.4;
            }

            .mrr-quick-review-modal .mrr-clarify-modal__dialog {
                width: min(720px, 100%);
            }

            .mrr-quick-review-modal .mrr-clarify-modal__description {
                line-height: 1.4;
            }

            .mrr-quick-review__hint {
                margin-top: -0.2rem;
                font-size: 0.78rem;
                color: #64748b;
            }

            .dark .mrr-quick-review__hint {
                color: #94a3b8;
            }

            .mrr-manual-tenant-switch__suggestions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                margin-top: 0.75rem;
            }

            .mrr-manual-tenant-switch__suggestion {
                border: 1px solid rgba(59, 130, 246, 0.18);
                background: rgba(239, 246, 255, 0.9);
                color: #1d4ed8;
                border-radius: 999px;
                padding: 0.4rem 0.8rem;
                font-size: 0.82rem;
                line-height: 1.2;
                cursor: pointer;
                transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            }

            .mrr-manual-tenant-switch__suggestion:hover {
                border-color: rgba(37, 99, 235, 0.28);
                background: rgba(219, 234, 254, 0.95);
            }

            .dark .mrr-manual-tenant-switch__suggestion {
                border-color: rgba(96, 165, 250, 0.28);
                background: rgba(30, 41, 59, 0.92);
                color: #93c5fd;
            }

            .dark .mrr-manual-tenant-switch__suggestion:hover {
                border-color: rgba(147, 197, 253, 0.42);
                background: rgba(30, 64, 175, 0.22);
            }

            .mrr-quick-review__clarify {
                border-radius: 1rem;
                border: 1px solid rgba(59, 130, 246, 0.16);
                background: rgba(239, 246, 255, 0.95);
                padding: 0.9rem 1rem;
            }

            .dark .mrr-quick-review__clarify {
                border-color: rgba(96, 165, 250, 0.22);
                background: rgba(15, 23, 42, 0.45);
            }

            .mrr-quick-review__clarify--success {
                border-color: rgba(34, 197, 94, 0.18);
                background: rgba(240, 253, 244, 0.95);
            }

            .dark .mrr-quick-review__clarify--success {
                border-color: rgba(74, 222, 128, 0.22);
                background: rgba(15, 23, 42, 0.45);
            }

            .mrr-quick-review__clarify-title {
                font-size: 0.82rem;
                font-weight: 800;
                color: #1d4ed8;
            }

            .dark .mrr-quick-review__clarify-title {
                color: #bfdbfe;
            }

            .mrr-quick-review__clarify-text {
                margin-top: 0.35rem;
                font-size: 0.86rem;
                line-height: 1.55;
                color: #334155;
            }

            .dark .mrr-quick-review__clarify-text {
                color: #cbd5e1;
            }

            .mrr-quick-review__field {
                min-height: 7rem;
                resize: vertical;
            }

            .mrr-duplicate-plan__card {
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: rgba(248, 250, 252, 0.85);
                padding: 0.85rem;
            }

            .dark .mrr-duplicate-plan__card {
                border-color: rgba(148, 163, 184, 0.16);
                background: rgba(15, 23, 42, 0.56);
            }

            .mrr-duplicate-plan__card.is-selected {
                border-color: rgba(37, 99, 235, 0.26);
                background: rgba(239, 246, 255, 0.98);
                box-shadow: 0 12px 28px rgba(37, 99, 235, 0.12);
            }

            .dark .mrr-duplicate-plan__card.is-selected {
                border-color: rgba(96, 165, 250, 0.34);
                background: rgba(15, 23, 42, 0.8);
                box-shadow: 0 12px 28px rgba(15, 23, 42, 0.3);
            }

            .mrr-duplicate-plan__card-title {
                font-size: 0.75rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #64748b;
            }

            .mrr-duplicate-plan__picker {
                margin-top: 0.75rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.45rem;
            }

            .mrr-duplicate-plan__picker-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.18);
                background: #fff;
                padding: 0.38rem 0.75rem;
                font-size: 0.8rem;
                font-weight: 700;
                color: #1d4ed8;
                cursor: pointer;
                font-family: inherit;
                line-height: inherit;
            }

            .mrr-duplicate-plan__picker-button.is-selected {
                border-color: rgba(37, 99, 235, 0.24);
                background: #2563eb;
                color: #fff;
                box-shadow: 0 10px 24px rgba(37, 99, 235, 0.18);
            }

            .dark .mrr-duplicate-plan__picker-button {
                border-color: rgba(96, 165, 250, 0.3);
                background: rgba(15, 23, 42, 0.32);
                color: #bfdbfe;
            }

            .dark .mrr-duplicate-plan__picker-button.is-selected {
                border-color: rgba(96, 165, 250, 0.34);
                background: #3b82f6;
                color: #fff;
            }

            .mrr-duplicate-plan__space {
                margin-top: 0.25rem;
                font-size: 0.98rem;
                font-weight: 800;
                color: #0f172a;
            }

            .dark .mrr-duplicate-plan__space {
                color: #f8fafc;
            }

            .mrr-duplicate-plan__counts {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
                margin-top: 0.65rem;
            }

            .mrr-duplicate-plan__details {
                margin-top: 0.65rem;
                border-radius: 0.8rem;
                border: 1px solid rgba(148, 163, 184, 0.18);
                background: rgba(255, 255, 255, 0.72);
                overflow: hidden;
            }

            .dark .mrr-duplicate-plan__details {
                border-color: rgba(148, 163, 184, 0.16);
                background: rgba(15, 23, 42, 0.38);
            }

            .mrr-duplicate-plan__details-summary {
                cursor: pointer;
                padding: 0.5rem 0.65rem;
                font-size: 0.78rem;
                font-weight: 800;
                color: #1d4ed8;
                list-style: none;
            }

            .mrr-duplicate-plan__details-summary::-webkit-details-marker {
                display: none;
            }

            .dark .mrr-duplicate-plan__details-summary {
                color: #bfdbfe;
            }

            .mrr-duplicate-plan__details-content {
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
                padding: 0 0.65rem 0.65rem;
            }

            .mrr-duplicate-plan__details-empty,
            .mrr-duplicate-plan__details-meta {
                font-size: 0.76rem;
                line-height: 1.4;
                color: #64748b;
            }

            .dark .mrr-duplicate-plan__details-empty,
            .dark .mrr-duplicate-plan__details-meta {
                color: #94a3b8;
            }

            .mrr-duplicate-plan__details-item {
                border-radius: 0.65rem;
                border: 1px solid rgba(148, 163, 184, 0.16);
                background: rgba(248, 250, 252, 0.82);
                padding: 0.5rem 0.55rem;
            }

            .dark .mrr-duplicate-plan__details-item {
                border-color: rgba(148, 163, 184, 0.14);
                background: rgba(15, 23, 42, 0.5);
            }

            .mrr-duplicate-plan__details-title {
                font-size: 0.78rem;
                font-weight: 800;
                color: #0f172a;
            }

            .dark .mrr-duplicate-plan__details-title {
                color: #f8fafc;
            }

            .mrr-duplicate-plan__details-warning {
                margin-top: 0.38rem;
                border-radius: 0.55rem;
                border: 1px solid rgba(245, 158, 11, 0.28);
                background: rgba(245, 158, 11, 0.1);
                padding: 0.38rem 0.45rem;
                font-size: 0.74rem;
                line-height: 1.35;
                color: #92400e;
            }

            .dark .mrr-duplicate-plan__details-warning {
                border-color: rgba(251, 191, 36, 0.28);
                background: rgba(245, 158, 11, 0.14);
                color: #fde68a;
            }

            .mrr-duplicate-plan__links {
                display: flex;
                flex-wrap: wrap;
                gap: 0.45rem;
                margin-top: 0.75rem;
            }

            .mrr-duplicate-plan__link {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid rgba(37, 99, 235, 0.18);
                padding: 0.28rem 0.58rem;
                font-size: 0.78rem;
                font-weight: 700;
                color: #1d4ed8;
                text-decoration: none;
            }

            .dark .mrr-duplicate-plan__link {
                border-color: rgba(96, 165, 250, 0.3);
                color: #bfdbfe;
            }

            .mrr-duplicate-plan__link.is-disabled,
            .mrr-duplicate-plan__link.is-disabled:hover {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(148, 163, 184, 0.1);
                color: #94a3b8;
                cursor: not-allowed;
                pointer-events: none;
                text-decoration: none;
            }

            .dark .mrr-duplicate-plan__link.is-disabled,
            .dark .mrr-duplicate-plan__link.is-disabled:hover {
                border-color: rgba(148, 163, 184, 0.22);
                background: rgba(71, 85, 105, 0.28);
                color: #94a3b8;
            }

            .mrr-duplicate-plan__selection {
                margin-top: 0.8rem;
                border-radius: 1rem;
                border: 1px solid rgba(37, 99, 235, 0.16);
                background: rgba(239, 246, 255, 0.94);
                padding: 0.8rem 0.9rem;
            }

            .dark .mrr-duplicate-plan__selection {
                border-color: rgba(96, 165, 250, 0.22);
                background: rgba(15, 23, 42, 0.56);
            }

            .mrr-duplicate-plan__selection-title {
                font-size: 0.86rem;
                font-weight: 800;
                color: #0f172a;
            }

            .dark .mrr-duplicate-plan__selection-title {
                color: #f8fafc;
            }

            .mrr-duplicate-plan__selection-copy {
                margin-top: 0.3rem;
                font-size: 0.82rem;
                line-height: 1.45;
                color: #475569;
            }

            .dark .mrr-duplicate-plan__selection-copy {
                color: #cbd5e1;
            }

            .mrr-duplicate-plan__section {
                margin-top: 0.7rem;
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                padding: 0.75rem 0.9rem;
            }

            .dark .mrr-duplicate-plan__section {
                border-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-duplicate-plan__section h4 {
                margin: 0 0 0.55rem;
                font-size: 0.86rem;
                font-weight: 800;
                color: #0f172a;
            }

            .dark .mrr-duplicate-plan__section h4 {
                color: #f8fafc;
            }

            .mrr-duplicate-plan__list {
                margin: 0;
                padding-left: 1.1rem;
                color: #475569;
                font-size: 0.86rem;
                line-height: 1.42;
            }

            .dark .mrr-duplicate-plan__list {
                color: #cbd5e1;
            }

            @media (max-width: 760px) {
                .mrr-duplicate-plan__grid {
                    grid-template-columns: 1fr;
                }
            }

            .mrr-empty {
                border-radius: 1rem;
                border: 1px dashed rgba(15, 23, 42, 0.14);
                padding: 1rem 1.1rem;
                color: #64748b;
            }

            .dark .mrr-empty {
                border-color: rgba(148, 163, 184, 0.2);
                color: #94a3b8;
            }

            .mrr-clarify-modal {
                position: fixed;
                inset: 0;
                z-index: 60;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }

            .mrr-clarify-modal.is-open {
                display: flex;
            }

            .mrr-clarify-modal__backdrop {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(4px);
            }

            .mrr-clarify-modal__dialog {
                position: relative;
                width: min(560px, 100%);
                border-radius: 1.25rem;
                border: 1px solid rgba(148, 163, 184, 0.24);
                background: rgba(255, 255, 255, 0.98);
                box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
                padding: 1.25rem;
                display: flex;
                flex-direction: column;
                gap: 0.95rem;
            }

            .dark .mrr-clarify-modal__dialog {
                background: rgba(15, 23, 42, 0.98);
                border-color: rgba(148, 163, 184, 0.24);
            }

            .mrr-clarify-modal__eyebrow {
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #64748b;
            }

            .dark .mrr-clarify-modal__eyebrow {
                color: #94a3b8;
            }

            .mrr-clarify-modal__title {
                margin: 0;
                font-size: 1.1rem;
                line-height: 1.3;
                color: #0f172a;
            }

            .dark .mrr-clarify-modal__title {
                color: #f8fafc;
            }

            .mrr-clarify-modal__description {
                margin: 0;
                font-size: 0.9rem;
                line-height: 1.5;
                color: #475569;
            }

            .dark .mrr-clarify-modal__description {
                color: #cbd5e1;
            }

            .mrr-clarify-modal__label {
                font-size: 0.82rem;
                font-weight: 700;
                color: #334155;
            }

            .dark .mrr-clarify-modal__label {
                color: #e2e8f0;
            }

            .mrr-clarify-modal__field {
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
            }

            .mrr-clarify-modal__input {
                width: 100%;
                box-sizing: border-box;
                border-radius: 0.9rem;
                border: 1px solid rgba(148, 163, 184, 0.38);
                background: #fff;
                color: #0f172a;
                padding: 0.85rem 0.95rem;
                font-size: 0.95rem;
                outline: none;
            }

            .dark .mrr-clarify-modal__input {
                background: rgba(15, 23, 42, 0.96);
                border-color: rgba(148, 163, 184, 0.34);
                color: #f8fafc;
            }

            .mrr-clarify-modal__input:focus {
                border-color: rgba(37, 99, 235, 0.85);
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
            }

            .mrr-clarify-modal__error {
                min-height: 1.1rem;
                font-size: 0.82rem;
                color: #b91c1c;
            }

            .dark .mrr-clarify-modal__error {
                color: #f87171;
            }

            .mrr-clarify-modal__actions {
                display: flex;
                flex-wrap: wrap;
                justify-content: flex-end;
                gap: 0.55rem;
            }

            .mrr-clarify-modal__button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.35rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.1);
                padding: 0.45rem 0.78rem;
                font-size: 0.85rem;
                font-weight: 700;
                color: #0f172a;
                background: rgba(255, 255, 255, 0.95);
                cursor: pointer;
                appearance: none;
            }

            .dark .mrr-clarify-modal__button {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(15, 23, 42, 0.92);
                color: #f8fafc;
            }

            .mrr-clarify-modal__button--primary {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            }

            .dark .mrr-clarify-modal__button--primary {
                background: #2563eb;
                border-color: #2563eb;
            }

            .mrr-clarify-modal__close {
                position: absolute;
                top: 0.75rem;
                right: 0.75rem;
                width: 2rem;
                height: 2rem;
                border-radius: 999px;
                border: 1px solid rgba(148, 163, 184, 0.28);
                background: rgba(248, 250, 252, 0.95);
                color: #475569;
                cursor: pointer;
                appearance: none;
                font-size: 1.1rem;
                line-height: 1;
            }

            .dark .mrr-clarify-modal__close {
                background: rgba(15, 23, 42, 0.92);
                color: #cbd5e1;
            }

            .mrr-progress-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .mrr-hero-grid {
                align-items: flex-start;
            }

            .mrr-hero-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                min-width: min(100%, 27rem);
            }

            .mrr-hero-progress {
                display: grid;
                grid-template-columns: minmax(10rem, 16rem) minmax(0, 1fr);
                gap: 0.75rem;
                align-items: center;
                margin-top: 0.9rem;
                max-width: 40rem;
            }

            .mrr-hero-progress .mrr-progress-bar {
                width: 100%;
            }

            .mrr-progress-bar {
                height: 0.75rem;
                border-radius: 999px;
                background: rgba(148, 163, 184, 0.2);
                overflow: hidden;
            }

            .mrr-progress-bar > span {
                display: block;
                height: 100%;
                border-radius: inherit;
                background: linear-gradient(90deg, #2563eb 0%, #16a34a 100%);
            }

            .mrr-chip-row {
                display: flex;
                flex-wrap: wrap;
                gap: 0.65rem;
            }

            .mrr-chip-row--compact {
                justify-content: flex-start;
                gap: 0.45rem;
            }

            .mrr-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.08);
                padding: 0.34rem 0.6rem;
                font-size: 0.75rem;
                color: #334155;
            }

            .dark .mrr-chip {
                border-color: rgba(148, 163, 184, 0.16);
                color: #cbd5e1;
            }

            .mrr-chip strong {
                color: #0f172a;
            }

            .dark .mrr-chip strong {
                color: #f8fafc;
            }

            @media (max-width: 1024px) {
                .mrr-progress-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 640px) {
                .mrr-progress-grid {
                    grid-template-columns: 1fr;
                }

                .mrr-table th,
                .mrr-table td {
                    padding-inline: 0.7rem;
                }
            }

            /* ИИ-разбор колонка */
            .mrr-ai {
                max-width: none;
            }

            .mrr-ai-panel {
                border-radius: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: rgba(255, 255, 255, 0.86);
                padding: 0.7rem 0.85rem;
            }

            .dark .mrr-ai-panel {
                border-color: rgba(148, 163, 184, 0.16);
                background: rgba(15, 23, 42, 0.72);
            }

            .mrr-ai-panel__title {
                margin-bottom: 0.42rem;
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #334155;
            }

            .dark .mrr-ai-panel__title {
                color: #e2e8f0;
            }

            .mrr-sort-toggle {
                display: inline-flex;
                flex-wrap: wrap;
                gap: 0.45rem;
                margin-top: 0.85rem;
                padding: 0.25rem;
                border-radius: 999px;
                border: 1px solid rgba(15, 23, 42, 0.08);
                background: rgba(248, 250, 252, 0.85);
                width: fit-content;
            }

            .dark .mrr-sort-toggle {
                border-color: rgba(148, 163, 184, 0.18);
                background: rgba(15, 23, 42, 0.42);
            }

            .mrr-sort-toggle__link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                border: 1px solid transparent;
                padding: 0.42rem 0.8rem;
                font-size: 0.8125rem;
                font-weight: 700;
                line-height: 1.15;
                color: #475569;
                text-decoration: none;
                transition:
                    background-color 0.16s ease,
                    border-color 0.16s ease,
                    color 0.16s ease,
                    box-shadow 0.16s ease;
            }

            .mrr-sort-toggle__link:hover {
                color: #0f172a;
                background: rgba(255, 255, 255, 0.92);
                border-color: rgba(15, 23, 42, 0.08);
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }

            .dark .mrr-sort-toggle__link {
                color: #cbd5e1;
            }

            .dark .mrr-sort-toggle__link:hover {
                color: #f8fafc;
                background: rgba(30, 41, 59, 0.88);
                border-color: rgba(148, 163, 184, 0.16);
            }

            .mrr-sort-toggle__link.is-active {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            }

            .mrr-sort-toggle__link.is-active:hover {
                background: #0f172a;
                border-color: #0f172a;
                color: #fff;
            }

            .dark .mrr-sort-toggle__link.is-active {
                background: #e2e8f0;
                border-color: #e2e8f0;
                color: #0f172a;
            }

            .dark .mrr-sort-toggle__link.is-active:hover {
                background: #f8fafc;
                border-color: #f8fafc;
                color: #0f172a;
            }

            .mrr-row--priority td {
                background: rgba(37, 99, 235, 0.045);
            }

            .mrr-row--priority td:first-child {
                box-shadow: inset 3px 0 0 rgba(37, 99, 235, 0.22);
            }

            .dark .mrr-row--priority td {
                background: rgba(37, 99, 235, 0.085);
            }

            .dark .mrr-row--priority td:first-child {
                box-shadow: inset 3px 0 0 rgba(96, 165, 250, 0.28);
            }

            .mrr-ai__summary {
                font-size: 0.8rem;
                color: #475569;
                line-height: 1.45;
                margin-bottom: 0.3rem;
            }

            .dark .mrr-ai__summary {
                color: #cbd5e1;
            }

            .mrr-ai__reason {
                font-size: 0.75rem;
                color: #64748b;
                line-height: 1.4;
                margin-bottom: 0.28rem;
            }

            .dark .mrr-ai__reason {
                color: #94a3b8;
            }

            .mrr-ai__reason strong {
                color: #334155;
            }

            .dark .mrr-ai__reason strong {
                color: #e2e8f0;
            }

            .mrr-ai__step {
                font-size: 0.75rem;
                color: #64748b;
                line-height: 1.4;
                margin-bottom: 0.1rem;
            }

            .mrr-ai__action {
                font-size: 0.75rem;
                color: #64748b;
                line-height: 1.4;
                margin-bottom: 0.1rem;
            }

            .dark .mrr-ai__step {
                color: #94a3b8;
            }

            .dark .mrr-ai__action {
                color: #94a3b8;
            }

            .mrr-ai__step strong {
                color: #334155;
            }

            .mrr-ai__action strong {
                color: #334155;
            }

            .dark .mrr-ai__step strong {
                color: #e2e8f0;
            }

            .dark .mrr-ai__action strong {
                color: #e2e8f0;
            }

            .mrr-ai--empty {
                font-size: 0.75rem;
                color: #94a3b8;
                font-style: italic;
            }

            .mrr-ai--skipped {
                font-size: 0.6875rem;
                color: #94a3b8;
            }

            .mrr-ai.is-loading {
                opacity: 0.72;
                pointer-events: none;
            }

            .mrr-ai__placeholder {
                color: #94a3b8;
            }

            .dark .mrr-ai__placeholder {
                color: #64748b;
            }

            .mrr-applied-summary {
                max-width: 34rem;
                font-size: 0.82rem;
                line-height: 1.45;
                color: #475569;
            }

            .dark .mrr-applied-summary {
                color: #cbd5e1;
            }

            .mrr-applied-list {
                display: flex;
                flex-direction: column;
                gap: 0.8rem;
            }

            .mrr-applied-card {
                border-radius: 1rem;
                border: 1px solid rgba(34, 197, 94, 0.18);
                background: rgba(240, 253, 244, 0.84);
                padding: 0.7rem 0.8rem;
            }

            .dark .mrr-applied-card {
                border-color: rgba(74, 222, 128, 0.2);
                background: rgba(15, 23, 42, 0.34);
            }

            .mrr-applied-card > summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                cursor: pointer;
                list-style: none;
                outline: none;
            }

            .mrr-applied-card > summary::-webkit-details-marker {
                display: none;
            }

            .mrr-applied-card__summary-main {
                min-width: 0;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: 0.22rem;
            }

            .mrr-applied-card__summary-top {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem;
            }

            .mrr-applied-card__summary-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.22rem;
                align-items: start;
            }

            .mrr-applied-card__summary-place,
            .mrr-applied-card__summary-brief {
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.12rem;
            }

            .mrr-applied-card__summary-place {
                align-items: flex-start;
            }

            .mrr-applied-card__summary-brief {
                align-items: flex-start;
                justify-content: flex-start;
                text-align: left;
            }

            .mrr-applied-card__decision-label {
                font-size: 0.8125rem;
                font-weight: 700;
                color: #166534;
                text-align: left;
            }

            .dark .mrr-applied-card__decision-label {
                color: #dcfce7;
            }

            .mrr-applied-card__reason {
                font-size: 0.73rem;
                color: #475569;
                display: -webkit-box;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                line-height: 1.35;
                text-align: left;
            }

            .dark .mrr-applied-card__reason {
                color: #cbd5e1;
            }

            .mrr-applied-card__toggle {
                display: inline-flex;
                flex-shrink: 0;
                align-items: center;
                justify-content: flex-end;
                min-width: 5.5rem;
                padding: 0.18rem 0;
                font-size: 0.75rem;
                font-weight: 800;
                color: #15803d;
                white-space: nowrap;
            }

            .dark .mrr-applied-card__toggle {
                color: #86efac;
            }

            .mrr-applied-card__toggle-close {
                display: none;
            }

            .mrr-applied-card[open] .mrr-applied-card__toggle-open {
                display: none;
            }

            .mrr-applied-card[open] .mrr-applied-card__toggle-close {
                display: inline;
            }

            .mrr-applied-card__body {
                margin-top: 0.65rem;
                padding-top: 0.65rem;
                border-top: 1px solid rgba(34, 197, 94, 0.14);
            }

            .dark .mrr-applied-card__body {
                border-top-color: rgba(74, 222, 128, 0.16);
            }

            .mrr-applied-card__badge {
                display: inline-flex;
                flex-shrink: 0;
                align-items: center;
                justify-content: flex-end;
            }

            .mrr-applied-card__body-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.05fr) minmax(0, 1.3fr) minmax(0, 0.9fr) minmax(0, 0.9fr);
                gap: 0.7rem;
                align-items: start;
            }

            .mrr-applied-card__column {
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
            }

            .mrr-applied-card__label {
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #16a34a;
            }

            .dark .mrr-applied-card__label {
                color: #86efac;
            }

            .mrr-applied-card__meta {
                font-size: 0.78rem;
                line-height: 1.42;
                color: #64748b;
            }

            .dark .mrr-applied-card__meta {
                color: #cbd5e1;
            }

            @media (max-width: 1140px) {
                .mrr-applied-card__body-grid {
                    grid-template-columns: 1fr;
                }
            }

            .aw-panel--muted {
                opacity: 0.9;
            }

            .aw-panel--muted .aw-panel-head {
                margin-bottom: 0.65rem;
            }

            .aw-panel--muted .aw-panel-title {
                font-size: 1.05rem;
            }

            .aw-panel--muted .aw-panel-copy {
                font-size: 0.84rem;
                color: #64748b;
            }

            .dark .aw-panel--muted .aw-panel-copy {
                color: #94a3b8;
            }

            .aw-panel--muted .mrr-table th,
            .aw-panel--muted .mrr-table td {
                padding-top: 0.58rem;
                padding-bottom: 0.58rem;
            }

            @media (max-width: 900px) {
                .mrr-hero-progress {
                    grid-template-columns: 1fr;
                }

                .mrr-hero-stats {
                    grid-template-columns: 1fr;
                    width: 100%;
                }

                .mrr-chip-row--compact {
                    justify-content: flex-start;
                }
            }
        </style>
    @endonce

    <div class="aw-shell">
        <section class="aw-hero">
            <div class="aw-hero-grid mrr-hero-grid">
                <div class="aw-hero-copy">
                    <div class="aw-hero-title">
                        <div class="aw-hero-icon">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-6 w-6" />
                        </div>

                        <div>
                            <h1 class="aw-hero-heading">Результаты ревизии</h1>
                            <p class="aw-hero-subheading">
                                Рабочий список спорных мест: проверьте карточку, примените безопасное исправление или зафиксируйте итог.
                            </p>
                        </div>
                    </div>

                    @if ($hasSelectedMarket)
                        <div class="mrr-hero-progress">
                            <div class="mrr-progress-bar" aria-hidden="true">
                                <span style="width: {{ $progress['percent'] }}%;"></span>
                            </div>

                            <div class="mrr-chip-row mrr-chip-row--compact">
                                @forelse ($progress['counts'] as $status => $count)
                                    <div class="mrr-chip">
                                        <strong>{{ $progress['labels'][$status] ?? $status }}</strong>
                                        <span>{{ number_format($count, 0, ',', ' ') }}</span>
                                    </div>
                                @empty
                                    <div class="mrr-chip">Ревизионных отметок пока нет</div>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>

                <div class="aw-stat-grid mrr-hero-stats">
                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Проверено</div>
                        <div class="aw-stat-value">{{ number_format($progress['reviewed'], 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Осталось</div>
                        <div class="aw-stat-value">{{ number_format($progress['remaining'], 0, ',', ' ') }}</div>
                    </div>

                    <div class="aw-stat-card">
                        <div class="aw-stat-label">Готовность</div>
                        <div class="aw-stat-value">{{ $progress['percent'] }}%</div>
                    </div>
                </div>
            </div>
        </section>

        @if (! $hasSelectedMarket)
            <section class="aw-panel">
                <div class="aw-panel-body">
                    <div class="mrr-empty">
                        Для страницы результатов ревизии нужно выбрать рынок в текущей admin-session.
                    </div>
                </div>
            </section>
        @else
            <div class="aw-grid">
                <div class="aw-column">
                    <section class="aw-panel aw-panel--muted">
                        <div class="aw-panel-head">
                            <div>
                                <h2 class="aw-panel-title">Нужно уточнить</h2>
                                <p class="aw-panel-copy">
                                    {{ $attentionTab === 'unconfirmed_links'
                                        ? 'Места на карте, где статус взят по арендатору, но точная связь с местом не подтверждена.'
                                        : 'Места со спорным или незавершённым ревизионным результатом.' }}
                                </p>
                                <div class="mrr-sort-toggle">
                                    <a
                                        class="mrr-sort-toggle__link {{ $attentionTab === 'review' ? 'is-active' : '' }}"
                                        href="{{ $attentionReviewUrl }}"
                                    >
                                        Ревизионные решения
                                    </a>
                                    <a
                                        class="mrr-sort-toggle__link {{ $attentionTab === 'unconfirmed_links' ? 'is-active' : '' }}"
                                        href="{{ $attentionUnconfirmedUrl }}"
                                    >
                                        Связь не подтверждена
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            @if ($needsAttention === [])
                                <div class="mrr-empty">Сейчас нет мест, требующих уточнения.</div>
                            @else
                                <div class="mrr-attention-filters" role="group" aria-label="Фильтры карточек">
                                    <button type="button" class="mrr-attention-filter is-active" data-mrr-attention-filter="all">Все</button>
                                    <button type="button" class="mrr-attention-filter" data-mrr-attention-filter="occupancy_conflict">Конфликт по занятости</button>
                                    <button type="button" class="mrr-attention-filter" data-mrr-attention-filter="space_identity_needs_clarification">Уточнить номер / название</button>
                                    <button type="button" class="mrr-attention-filter" data-mrr-attention-filter="tenant_changed_on_site">Сменился арендатор</button>
                                    <button type="button" class="mrr-attention-filter" data-mrr-attention-filter="shape_not_found">Фигура не найдена</button>
                                    <span class="mrr-attention-filter-count" aria-live="polite"></span>
                                </div>
                                <div class="mrr-attention-search">
                                    <input
                                        type="search"
                                        class="mrr-attention-search__input"
                                        data-mrr-attention-search
                                        placeholder="Поиск по месту, названию, локации, причине, автору"
                                        autocomplete="off"
                                    >
                                </div>
                                <div class="mrr-needs-list">
                                    @foreach ($needsAttention as $row)
                                        @php
                                            $ai = $aiSummaries[$row['space_id']] ?? null;
                                            $diagnostics = is_array($row['diagnostics'] ?? null) ? $row['diagnostics'] : [];
                                            $relationCounts = is_array($diagnostics['relation_counts'] ?? null) ? $diagnostics['relation_counts'] : [];
                                            $relationDetails = is_array($diagnostics['relation_details'] ?? null) ? $diagnostics['relation_details'] : [];
                                            $candidateSpaces = is_array($diagnostics['candidate_spaces'] ?? null) ? $diagnostics['candidate_spaces'] : [];
                                            $relationAssessment = trim((string) ($diagnostics['relation_assessment'] ?? ''));
                                            $hasMapLink = collect($relationCounts)
                                                ->contains(fn (array $item): bool => ($item['key'] ?? null) === 'map_shapes' && (int) ($item['count'] ?? 0) > 0);
                                            $currentSpaceLabel = trim((string) ($row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id']))));

                                            if (filled($row['number']) && filled($row['display_name']) && $row['number'] !== $row['display_name']) {
                                                $currentSpaceLabel = $row['number'] . ' / ' . $row['display_name'];
                                            }

                                            $hasAiKey = array_key_exists($row['space_id'], $aiSummaries)
                                                || array_key_exists($row['space_id'], $aiErrors ?? []);
                                            $aiErrorType = $aiErrors[$row['space_id']] ?? null;
                                            $aiMode = (string) (($aiMeta['mode'] ?? 'ok'));
                                            $aiLimit = (int) (($aiMeta['limit'] ?? 5));

                                            // Функция для замены технических кодов на русский текст
                                            $humanize = function(?string $text): string {
                                                if (blank($text)) return '';
                                                $map = [
                                                    'occupancy_conflict'     => 'конфликт по занятости',
                                                    'tenant_changed_on_site' => 'на месте другой арендатор',
                                                    'shape_not_found'        => 'фигура не найдена на карте',
                                                    'mark_space_free'        => 'отметить место как свободное',
                                                    'mark_space_service'     => 'отметить место как служебное',
                                                    'fix_space_identity'     => 'уточнить номер и название',
                                                    'bind_shape_to_space'    => 'привязать фигуру к месту',
                                                    'unbind_shape_from_space'=> 'отвязать фигуру',
                                                ];
                                                $text = str_replace(array_keys($map), array_values($map), $text);
                                                return $text;
                                            };
                                            $searchText = trim(implode(' ', array_filter([
                                                $row['number'] ?? null,
                                                $row['display_name'] ?? null,
                                                $row['location_name'] ?? null,
                                                $row['review_status_label'] ?? null,
                                                $row['decision_label'] ?? null,
                                                data_get($row, 'tenant_change_details.observed_tenant_name'),
                                                data_get($row, 'diagnostics.financial_signal.tenant_name'),
                                                data_get($row, 'diagnostics.financial_signal.latest_period_label'),
                                                $row['reason'] ?? null,
                                                $row['created_by_name'] ?? null,
                                                $row['created_at'] ?? null,
                                                $row['reviewed_by_name'] ?? null,
                                                $row['reviewed_at'] ?? null,
                                            ], static fn ($value): bool => filled($value))));

                                            $decision = (string) ($row['decision'] ?? '');
                                            $reviewStatus = (string) ($row['review_status'] ?? '');
                                            $hasCandidates = $candidateSpaces !== [];
                                            $primaryCandidate = $hasCandidates && is_array($candidateSpaces[0] ?? null) ? $candidateSpaces[0] : null;
                                            $currentContractDetails = is_array($diagnostics['contract_details'] ?? null) ? $diagnostics['contract_details'] : [];
                                            $currentAccrualDetails = is_array($diagnostics['accrual_details'] ?? null) ? $diagnostics['accrual_details'] : [];
                                            $candidateContractDetails = is_array($primaryCandidate['contract_details'] ?? null) ? $primaryCandidate['contract_details'] : [];
                                            $candidateAccrualDetails = is_array($primaryCandidate['accrual_details'] ?? null) ? $primaryCandidate['accrual_details'] : [];
                                            $hasDuplicateResolutionAction = $attentionTab !== 'unconfirmed_links' && $primaryCandidate !== null;
                                            $hasRelationDetails = $relationDetails !== [];
                                            $contractOverride = is_array($diagnostics['contract_override'] ?? null) ? $diagnostics['contract_override'] : null;
                                            $isContractTenantOverride = $contractOverride !== null;
                                            $currentTenantName = trim((string) ($row['current_tenant_name'] ?? ''));
                                            $targetTenantName = trim((string) ($contractOverride['tenant_name'] ?? ''));
                                            $contractNumberLabel = trim((string) ($contractOverride['contract_number'] ?? ''));
                                            $hasIdentityClarification = $decision === 'space_identity_needs_clarification';
                                            $isIdentityCase = $hasIdentityClarification && ! $isContractTenantOverride;
                                            $financialSignal = is_array($diagnostics['financial_signal'] ?? null) ? $diagnostics['financial_signal'] : null;
                                            $isFinancialSignalCase = $financialSignal !== null;
                                            $financialTenantName = trim((string) ($financialSignal['tenant_name'] ?? ''));
                                            $financialCurrentTenantName = trim((string) ($financialSignal['current_tenant_name'] ?? ''));
                                            $financialPeriodLabel = trim((string) ($financialSignal['latest_period_label'] ?? ''));
                                            $financialSourceFile = trim((string) ($financialSignal['source_file'] ?? ''));
                                            $financialContractStatus = trim((string) ($financialSignal['contract_link_status'] ?? ''));
                                            $financialTenantExternalId = trim((string) ($financialSignal['tenant_external_id'] ?? ''));
                                            $financialTenantInn = trim((string) ($financialSignal['tenant_inn'] ?? ''));
                                            $financialTenantKpp = trim((string) ($financialSignal['tenant_kpp'] ?? ''));
                                            $financialAccrualId = (int) ($financialSignal['accrual_id'] ?? 0);
                                            $financialRequiresTenantResolution = (bool) ($financialSignal['requires_tenant_resolution'] ?? false);
                                            $financialResolutionAction = trim((string) ($financialSignal['resolution_action'] ?? ''));
                                            $financialExistingTenantCandidateId = (int) ($financialSignal['existing_tenant_candidate_id'] ?? 0);
                                            $financialExistingTenantCandidateName = trim((string) ($financialSignal['existing_tenant_candidate_name'] ?? ''));
                                            $financialResolveButtonLabel = $financialResolutionAction === 'activate_existing_tenant'
                                                ? 'Активировать арендатора'
                                                : 'Создать/сопоставить арендатора';
                                            $isTenantCase = $decision === 'tenant_changed_on_site' || $reviewStatus === 'changed_tenant';
                                            $isShapeCase = $decision === 'shape_not_found' || $reviewStatus === 'not_found';
                                            $isConflictCase = $decision === 'occupancy_conflict' || $reviewStatus === 'conflict';
                                            $looksFreeCase = $isConflictCase
                                                && preg_match('/(свобод|съех|не стоит|нет арендатора|пуст)/iu', (string) ($row['reason'] ?? '')) === 1;
                                            $canConfirmFree = $attentionTab !== 'unconfirmed_links' && $looksFreeCase && ! $hasCandidates;
                                            $isMergeRetirementCase = $decision === 'merge_space_into_canonical'
                                                || ($isConflictCase && preg_match('/(удал|упраздн|прибав|объедин)/iu', (string) ($row['reason'] ?? '')) === 1);
                                            $suggestedTargetTenantId = (int) ($row['suggested_target_tenant_id'] ?? 0);
                                            $suggestedTargetTenantName = trim((string) ($row['suggested_target_tenant_name'] ?? ''));
                                            $canManualTenantSwitch = $attentionTab !== 'unconfirmed_links'
                                                && ! $isContractTenantOverride
                                                && ! $financialRequiresTenantResolution
                                                && (
                                                    $isTenantCase
                                                    || $suggestedTargetTenantId > 0
                                                    || preg_match('/(арендатор|смен)/iu', (string) ($row['reason'] ?? '')) === 1
                                                );
                                            $canResolveFinancialTenant = $attentionTab !== 'unconfirmed_links'
                                                && $isFinancialSignalCase
                                                && $financialRequiresTenantResolution;
                                            $hasPrimaryResolutionAction = $attentionTab !== 'unconfirmed_links'
                                                && ($isIdentityCase || $isMergeRetirementCase || $isContractTenantOverride || $hasDuplicateResolutionAction || $canConfirmFree || $canManualTenantSwitch || $canResolveFinancialTenant);
                                            $showRelationAssessment = $contractOverride || $hasCandidates;
                                            $tenantChangeDetails = is_array($row['tenant_change_details'] ?? null) ? $row['tenant_change_details'] : [];
                                            $observedTenantName = trim((string) ($tenantChangeDetails['observed_tenant_name'] ?? ''));
                                            $tenantChangeComment = trim((string) ($tenantChangeDetails['review_comment'] ?? ''));
                                            $tenantChangeAuthor = trim((string) ($tenantChangeDetails['author_name'] ?? ''));
                                            $tenantChangeRecordedAt = trim((string) ($tenantChangeDetails['recorded_at'] ?? ''));
                                            $hasTenantChangeDetails = $isTenantCase
                                                && ($observedTenantName !== ''
                                                    || $tenantChangeComment !== '');
                                            $createdByLabel = trim((string) ($row['created_by_name'] ?? ''));
                                            $createdAtLabel = trim((string) ($row['created_at'] ?? ''));
                                            if ($isFinancialSignalCase) {
                                                $conflictHeadline = 'Финконтур сообщает о новом арендаторе';
                                            } elseif ($isContractTenantOverride) {
                                                $conflictHeadline = 'На месте уже найден новый арендатор по договору';
                                            } elseif ($hasCandidates) {
                                                $conflictHeadline = 'Похоже на дубль или спорную привязку места';
                                            } elseif ($looksFreeCase) {
                                                $conflictHeadline = 'Похоже, место уже свободно';
                                            } elseif (preg_match('/(арендатор|стоит|смен)/iu', (string) ($row['reason'] ?? '')) === 1) {
                                                $conflictHeadline = 'Фактическое состояние не совпадает с карточкой места';
                                            } else {
                                                $conflictHeadline = 'Фактическое состояние места требует проверки';
                                            }

                                            if ($isMergeRetirementCase) {
                                                $workflowTitle = 'Упразднить и связать с основным местом';
                                                $workflowText = 'Старое место останется в истории, текущая карта и занятость перейдут к основному месту с указанной даты.';
                                            } elseif ($isFinancialSignalCase) {
                                                $workflowTitle = 'Разобрать финансовый сигнал';
                                                $workflowText = 'По месту есть начисление на другого арендатора без найденного договора. Нужно подтвердить смену арендатора и отдельно разобрать договор.';
                                            } elseif ($isContractTenantOverride) {
                                                $workflowTitle = 'Подтвердить смену арендатора';
                                                $workflowText = 'Новый арендатор уже найден по договору. Нужно только подтвердить смену.';
                                            } elseif ($isIdentityCase) {
                                                $workflowTitle = 'Уточнить номер / название';
                                                $workflowText = 'Проверьте реквизиты места и примените исправление прямо отсюда.';
                                            } elseif ($isTenantCase) {
                                                $workflowTitle = 'Проверить арендатора';
                                                $workflowText = 'Сравните фактического арендатора с карточкой места и договорными связями.';
                                            } elseif ($isShapeCase) {
                                                $workflowTitle = 'Проверить разметку на карте';
                                                $workflowText = 'Нужно найти фигуру места на карте или зафиксировать, что разметки нет.';
                                            } elseif ($hasCandidates) {
                                                $workflowTitle = 'Разобрать возможный дубль';
                                                $workflowText = 'Сравните текущее место с найденными местами того же арендатора.';
                                            } elseif ($isConflictCase) {
                                                $workflowTitle = 'Разобрать конфликт места';
                                                $workflowText = 'Конфликт требует ручной проверки занятости и итогового решения.';
                                            } else {
                                                $workflowTitle = 'Проверить карточку';
                                                $workflowText = 'Карточка требует ручной проверки и итогового решения.';
                                            }
                                        @endphp

                                         <details class="mrr-needs-card {{ $row['priority_is_high'] ? 'mrr-needs-card--priority' : '' }} {{ $isConflictCase ? 'mrr-needs-card--conflict-layout' : '' }}"
                                                  data-mrr-attention-card
                                                  data-mrr-review-status="{{ $row['review_status'] ?? '' }}"
                                                 data-mrr-decision="{{ $row['decision'] ?? '' }}"
                                                 data-mrr-search="{{ \Illuminate\Support\Str::lower($searchText) }}">
                                            <summary>
                                                <div class="mrr-needs-card__summary-main">
                                                    <div class="mrr-needs-card__summary-top">
                                                        <div class="mrr-needs-card__summary-top-main">
                                                        <div class="mrr-place__title">
                                                            {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                        </div>
                                                        <span class="mrr-badge mrr-badge--{{ $row['review_status'] }}">
                                                            {{ $row['review_status_label'] ?? '—' }}
                                                        </span>
                                                        </div>
                                                        @if ($createdByLabel !== '' || $createdAtLabel !== '')
                                                            <div class="mrr-needs-card__summary-meta">
                                                                @if ($createdByLabel !== '')
                                                                    <div class="mrr-needs-card__summary-meta-label">{{ $createdByLabel }}</div>
                                                                @endif
                                                                @if ($createdAtLabel !== '')
                                                                    <div class="mrr-needs-card__summary-meta-value">{{ $createdAtLabel }}</div>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <div class="mrr-needs-card__summary-grid">
                                                        <div class="mrr-needs-card__summary-place">
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                        @if (filled($row['reason']))
                                                            <div class="mrr-needs-card__summary-brief">
                                                                <div class="mrr-needs-card__reason">{{ $row['reason'] }}</div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="mrr-needs-card__toggle" aria-hidden="true">
                                                    <span class="mrr-needs-card__toggle-open">Подробнее ▾</span>
                                                    <span class="mrr-needs-card__toggle-close">Скрыть ▴</span>
                                                </div>
                                            </summary>

                                            <div class="mrr-needs-card__body">
                                                <div class="mrr-needs-card__body-grid">
                                                    <div class="mrr-needs-card__column mrr-needs-card__column--main">
                                                        @if ($isConflictCase)
                                                            <div class="mrr-conflict-brief">
                                                                <div class="mrr-conflict-brief__eyebrow">Что видно по месту</div>
                                                                <div class="mrr-conflict-brief__headline">{{ $conflictHeadline }}</div>
                                                                @if (filled($row['reason']))
                                                                    <div class="mrr-conflict-brief__hint">
                                                                        <div class="mrr-conflict-brief__hint-label">Подсказка ревизора</div>
                                                                        <div class="mrr-conflict-brief__hint-text">{{ $row['reason'] }}</div>
                                                                    </div>
                                                                @else
                                                                    <div class="mrr-conflict-brief__copy">{{ $workflowText }}</div>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        @if ($attentionTab !== 'unconfirmed_links')
                                                            <div class="mrr-place__decision">
                                                                <div class="mrr-place__decision-label">{{ $workflowTitle }}</div>
                                                                <div class="mrr-place__decision-reason">{{ $workflowText }}</div>
                                                                @if ($isContractTenantOverride)
                                                                    <div class="mrr-place__decision-swap">
                                                                        <div class="mrr-place__decision-side">
                                                                            <div class="mrr-place__decision-side-label">Было</div>
                                                                            <div class="mrr-place__decision-side-value">{{ $currentTenantName !== '' ? $currentTenantName : 'Арендатор не указан' }}</div>
                                                                        </div>
                                                                        <div class="mrr-place__decision-arrow" aria-hidden="true">→</div>
                                                                        <div class="mrr-place__decision-side">
                                                                            <div class="mrr-place__decision-side-label">Станет</div>
                                                                            <div class="mrr-place__decision-side-value mrr-place__decision-side-value--target">{{ $targetTenantName !== '' ? $targetTenantName : 'Новый арендатор найден' }}</div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="mrr-place__decision-facts">
                                                                        @if ($contractNumberLabel !== '')
                                                                            <span class="mrr-place__decision-fact">Договор: {{ $contractNumberLabel }}</span>
                                                                        @endif
                                                                    </div>
                                                                    @if ($hasIdentityClarification)
                                                                        <div class="mrr-place__decision-warning">
                                                                            Найден договор другого арендатора, но точная связь места требует уточнения. Сначала разберите место/дубли, затем подтверждайте смену.
                                                                        </div>
                                                                    @else
                                                                        <div class="mrr-place__decision-hint">
                                                                            Смена арендатора уже подтверждена договором. После подтверждения система просто зафиксирует её на месте.
                                                                        </div>
                                                                    @endif
                                                                @endif
                                                                @if ($hasTenantChangeDetails)
                                                                    <div class="mrr-place__decision-details">
                                                                        @if ($observedTenantName !== '')
                                                                            <div class="mrr-place__decision-detail">
                                                                                <div class="mrr-place__decision-detail-label">Фактический арендатор</div>
                                                                                <div class="mrr-place__decision-detail-value">{{ $observedTenantName }}</div>
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                    @if ($isFinancialSignalCase)
                                                                        <div class="mrr-place__decision-details">
                                                                            @if ($financialCurrentTenantName !== '')
                                                                                <div class="mrr-place__decision-detail">
                                                                                    <div class="mrr-place__decision-detail-label">В карточке места</div>
                                                                                    <div class="mrr-place__decision-detail-value">{{ $financialCurrentTenantName }}</div>
                                                                                </div>
                                                                            @endif
                                                                            @if ($financialPeriodLabel !== '')
                                                                                <div class="mrr-place__decision-detail">
                                                                                    <div class="mrr-place__decision-detail-label">Период начисления</div>
                                                                                    <div class="mrr-place__decision-detail-value">{{ $financialPeriodLabel }}</div>
                                                                                </div>
                                                                            @endif
                                                                            <div class="mrr-place__decision-detail">
                                                                                <div class="mrr-place__decision-detail-label">Договор</div>
                                                                                <div class="mrr-place__decision-detail-value">Не найден{{ $financialContractStatus !== '' ? ' · ' . $financialContractStatus : '' }}</div>
                                                                            </div>
                                                                            @if ($financialSourceFile !== '')
                                                                                <div class="mrr-place__decision-detail">
                                                                                    <div class="mrr-place__decision-detail-label">Источник</div>
                                                                                    <div class="mrr-place__decision-detail-value">{{ $financialSourceFile }}</div>
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    @endif
                                                                    @if ($tenantChangeComment !== '')
                                                                        <div class="mrr-conflict-brief__hint">
                                                                            <div class="mrr-conflict-brief__hint-label">Подсказка ревизора</div>
                                                                            <div class="mrr-conflict-brief__hint-text">{{ $tenantChangeComment }}</div>
                                                                        </div>
                                                                    @endif
                                                                @else
                                                                    <div class="mrr-place__decision-meta">
                                                                        Создано: {{ $row['created_by_name'] ?: '—' }} · {{ $row['created_at'] ?: '—' }}
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        <div class="mrr-card-actions">
                                                            @if ($hasPrimaryResolutionAction)
                                                                <div class="mrr-card-actions__group mrr-card-actions__group--primary">
                                                                    <div class="mrr-card-actions__label">Решение</div>
                                                                    <div class="mrr-card-actions__row">
                    @if ($isContractTenantOverride && ! $hasIdentityClarification)
                        <button
                            type="button"
                            class="mrr-link mrr-link--button mrr-link--primary"
                            data-mrr-contract-tenant-switch-apply
                            data-mrr-space-id="{{ $row['space_id'] }}"
                            data-mrr-current-tenant-name="{{ $currentTenantName }}"
                            data-mrr-tenant-id="{{ (int) ($contractOverride['tenant_id'] ?? 0) }}"
                            data-mrr-tenant-name="{{ $contractOverride['tenant_name'] ?? '' }}"
                            data-mrr-contract-id="{{ (int) ($contractOverride['contract_id'] ?? 0) }}"
                            data-mrr-contract-number="{{ $contractOverride['contract_number'] ?? '' }}"
                            data-mrr-effective-date="{{ $contractOverride['starts_at'] ?? '' }}"
                        >
                            Подтвердить смену
                        </button>
                    @endif
                    @if ($canResolveFinancialTenant)
                        <button
                            type="button"
                            class="mrr-link mrr-link--button mrr-link--primary"
                            data-mrr-financial-tenant-resolve-open
                            data-mrr-space-id="{{ $row['space_id'] }}"
                            data-mrr-accrual-id="{{ $financialAccrualId }}"
                            data-mrr-tenant-name="{{ $financialTenantName }}"
                            data-mrr-tenant-external-id="{{ $financialTenantExternalId }}"
                            data-mrr-tenant-inn="{{ $financialTenantInn }}"
                            data-mrr-tenant-kpp="{{ $financialTenantKpp }}"
                            data-mrr-preferred-tenant-id="{{ $financialExistingTenantCandidateId }}"
                            data-mrr-preferred-tenant-name="{{ $financialExistingTenantCandidateName }}"
                            data-mrr-resolution-action="{{ $financialResolutionAction }}"
                            data-mrr-resolution-label="{{ $financialResolveButtonLabel }}"
                        >
                            {{ $financialResolveButtonLabel }}
                        </button>
                    @endif
                    @if ($canManualTenantSwitch)
                        <button
                            type="button"
                            class="mrr-link mrr-link--button mrr-link--primary"
                            data-mrr-manual-tenant-switch-open
                            data-mrr-space-id="{{ $row['space_id'] }}"
                            data-mrr-current-tenant-name="{{ $currentTenantName }}"
                            data-mrr-suggested-tenant-id="{{ $suggestedTargetTenantId }}"
                            data-mrr-suggested-tenant-name="{{ $suggestedTargetTenantName }}"
                            data-mrr-effective-date="{{ now()->format('Y-m-d') }}"
                            data-mrr-reason="{{ $tenantChangeComment !== '' ? $tenantChangeComment : ($row['reason'] ?? '') }}"
                        >
                            Сменить арендатора
                        </button>
                    @endif
                    @if ($hasIdentityClarification && $isContractTenantOverride)
                        <div class="mrr-card-actions__warning">
                            <strong>Требуется уточнение места:</strong> сначала разберите дубли / точную связь места, затем подтверждайте смену арендатора.
                        </div>
                    @endif
                                                                        @if ($isIdentityCase)
                                                                            <button
                                                                                type="button"
                                                                                class="mrr-link mrr-link--button mrr-link--primary"
                                                                                data-mrr-identity-fix-open
                                                                                data-mrr-space-id="{{ $row['space_id'] }}"
                                                                                data-mrr-number="{{ $row['number'] ?? '' }}"
                                                                                data-mrr-display-name="{{ $row['display_name'] ?? '' }}"
                                                                            >
                                                                                Уточнить номер / название
                                                                            </button>
                                                                        @endif
                                                                        @if ($isMergeRetirementCase)
                                                                            <button
                                                                                type="button"
                                                                                class="mrr-link mrr-link--button mrr-link--primary"
                                                                                data-mrr-merge-retire-open
                                                                                data-mrr-space-id="{{ $row['space_id'] }}"
                                                                                data-mrr-space-label="{{ $currentSpaceLabel }}"
                                                                            >
                                                                                Упразднить и связать
                                                                            </button>
                                                                        @endif
                                                                        @if ($hasDuplicateResolutionAction && $primaryCandidate)
                                                                            <button
                                                                                type="button"
                                                                                class="mrr-link mrr-link--button mrr-link--primary"
                                                                                data-mrr-duplicate-plan="open"
                                                                                data-current-space-id="{{ $row['space_id'] }}"
                                                                                data-current-label="{{ $currentSpaceLabel }}"
                                                                                data-current-space-url="{{ $row['space_url'] }}"
                                                                                data-current-map-url="{{ $row['map_url'] }}"
                                                                                data-current-counts='@json($relationCounts)'
                                                                                data-candidate-space-id="{{ $primaryCandidate['space_id'] }}"
                                                                                data-candidate-label="{{ $primaryCandidate['label'] }}"
                                                                                data-candidate-space-url="{{ $primaryCandidate['space_url'] }}"
                                                                                data-candidate-map-url="{{ $primaryCandidate['map_url'] }}"
                                                                                data-candidate-counts='@json($primaryCandidate['relation_counts'] ?? [])'
                                                                                data-current-contracts='@json($currentContractDetails)'
                                                                                data-current-accruals='@json($currentAccrualDetails)'
                                                                                data-candidate-contracts='@json($candidateContractDetails)'
                                                                                data-candidate-accruals='@json($candidateAccrualDetails)'
                                                                            >
                                                                                Разобрать дубль
                                                                            </button>
                                                                        @endif
                                                                        @if ($canConfirmFree)
                                                                            <button
                                                                                type="button"
                                                                                class="mrr-link mrr-link--button mrr-link--success"
                                                                                data-mrr-confirm-free-open
                                                                                data-mrr-space-id="{{ $row['space_id'] }}"
                                                                                data-mrr-space-label="{{ $currentSpaceLabel }}"
                                                                            >
                                                                                Подтвердить свободно
                                                                            </button>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endif

                                                            @if ($attentionTab !== 'unconfirmed_links')
                                                                <div class="mrr-card-actions__group">
                                                                    <div class="mrr-card-actions__label">Закрытие</div>
                                                                    <div class="mrr-card-actions__hint">Только если после проверки данные места менять не нужно.</div>
                                                                    <div class="mrr-card-actions__row">
                                                                        <button
                                                                            type="button"
                                                                            class="mrr-link mrr-link--button"
                                                                            data-mrr-quick-review-launcher
                                                                            data-mrr-space-id="{{ $row['space_id'] }}"
                                                                        >
                                                                            Закрыть без изменений
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="mrr-needs-card__column mrr-needs-card__column--diagnostics">
                                                        <div class="mrr-diagnostics">
                                                                <div class="mrr-diagnostics__section">
                                                                    <div class="mrr-diagnostics__actions">
                                                                    <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                                    @if ($hasMapLink)
                                                                        <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                                    @else
                                                                        <span class="mrr-link mrr-link--disabled" aria-disabled="true">Открыть карту</span>
                                                                    @endif
                                                                </div>
                                                                <div class="mrr-diagnostics__section-title">Связи текущего места</div>
                                                                <div class="mrr-diagnostics__summary">
                                                                @foreach ($relationCounts as $item)
                                                                    <span class="mrr-diagnostics__count {{ ! empty($item['important']) ? 'mrr-diagnostics__count--important' : '' }}">
                                                                        {{ $item['label'] }}: {{ $item['count'] }}
                                                                    </span>
                                                                @endforeach
                                                                </div>
                                                                @if ($isConflictCase)
                                                                    <div class="mrr-diagnostics__intro">
                                                                        Здесь видно, где у места остаются хвосты по договору, начислениям и карте.
                                                                    </div>
                                                                @endif
                                                            </div>

                                                            @if ($showRelationAssessment && $relationAssessment !== '')
                                                                <span class="mrr-assessment mrr-assessment--{{ $row['assessment_tone'] ?? 'neutral' }}">
                                                                    {{ $row['assessment_label'] ?? 'Требует проверки' }}
                                                                </span>
                                                                <div class="mrr-diagnostics__assessment">{{ $relationAssessment }}</div>
                                                            @endif

                                                            @if ($hasCandidates)
                                                                <div class="mrr-diagnostics__compare">
                                                                    <div class="mrr-diagnostics__compare-title">Возможные дубли</div>
                                                                    <div class="mrr-diagnostics__compare-copy">
                                                                        Найдено {{ count($candidateSpaces) }} {{ count($candidateSpaces) === 1 ? 'место' : 'места' }} того же арендатора. Для выбора основного места используйте действие «Разобрать дубль» в блоке исправлений.
                                                                    </div>
                                                                </div>
                                                            @elseif ($hasRelationDetails)
                                                                <details class="mrr-diagnostics__details">
                                                                    <summary>Проверить связи места</summary>
                                                                    <div class="mrr-diagnostics__details-body">
                                                                        <div class="mrr-diagnostics__detail-list">
                                                                            @foreach ($relationDetails as $item)
                                                                                <div class="mrr-diagnostics__detail-item">
                                                                                    <div class="mrr-diagnostics__detail-title">{{ $item['label'] }}: {{ $item['count'] }}</div>
                                                                                    <div class="mrr-diagnostics__detail-copy">{{ $item['description'] }}</div>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </div>
                                                                </details>
                                                            @elseif (! $isConflictCase)
                                                                <div class="mrr-diagnostics__intro">
                                                                    Дополнительных связей для раскрытия нет.
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="mrr-needs-card__column mrr-needs-card__column--ai">
                                                        <div class="mrr-ai-panel">
                                                            <div class="mrr-ai-panel__title">ИИ-разбор</div>
                                                            <div class="mrr-ai" data-mrr-ai-panel data-mrr-space-id="{{ $row['space_id'] }}">
                                                                @if ($ai && filled($ai['summary']))
                                                                    <div class="mrr-ai__summary">
                                                                        <strong>Ситуация:</strong> {{ $humanize($ai['summary']) }}
                                                                    </div>
                                                                    <div class="mrr-ai__reason">
                                                                        <strong>Почему:</strong> {{ $humanize($ai['why_flagged']) }}
                                                                    </div>
                                                                    @if (filled($ai['recommended_action_label'] ?? $ai['recommended_action'] ?? null))
                                                                        <div class="mrr-ai__action">
                                                                            <strong>Рекомендованное действие:</strong> {{ $humanize($ai['recommended_action_label'] ?? $ai['recommended_action']) }}
                                                                        </div>
                                                                    @endif
                                                                    <div class="mrr-ai__step">
                                                                        <strong>Что сделать:</strong> {{ $humanize($ai['recommended_next_step']) }}
                                                                    </div>
                                                                @elseif ($hasAiKey)
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">
                                                                            @if ($aiErrorType === 'policy')
                                                                                ИИ-анализ отклонён проверкой качества ответа
                                                                            @elseif ($aiErrorType === 'connectivity')
                                                                                ИИ-анализ временно недоступен из-за ошибки соединения
                                                                            @else
                                                                                ИИ-анализ недоступен
                                                                            @endif
                                                                        </span>
                                                                    </div>
                                                                @elseif ($aiMode === 'disabled')
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">ИИ-разбор отключён в этом окружении</span>
                                                                    </div>
                                                                @elseif (in_array($aiMode, ['connectivity_cooldown', 'page_error'], true))
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">AI-сводка временно недоступна</span>
                                                                    </div>
                                                                @elseif (count($needsAttention) > $aiLimit)
                                                                    <div class="mrr-ai mrr-ai--skipped">
                                                                        <button
                                                                            type="button"
                                                                            class="mrr-link mrr-link--button"
                                                                            data-mrr-ai-load
                                                                            data-mrr-space-id="{{ $row['space_id'] }}"
                                                                        >
                                                                            Загрузить ИИ-разбор
                                                                        </button>
                                                                    </div>
                                                                @else
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">ИИ-анализ недоступен</span>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="aw-panel aw-panel--muted">
                        <div class="aw-panel-head">
                            <div>
                                <h2 class="aw-panel-title">Применено</h2>
                                <p class="aw-panel-copy">Безопасные изменения, уже прошедшие через SPACE_REVIEW.</p>
                            </div>
                        </div>

                        <div class="aw-panel-body">
                            @if ($appliedChanges === [])
                                <div class="mrr-empty">Применённых ревизионных изменений по выбранному рынку пока нет.</div>
                            @else
                                <div class="mrr-applied-list">
                                    @foreach ($appliedChanges as $row)
                                        <details class="mrr-applied-card">
                                            <summary>
                                                <div class="mrr-applied-card__summary-main">
                                                    <div class="mrr-applied-card__summary-top">
                                                        <div class="mrr-place__title">
                                                            {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                        </div>
                                                        <span class="mrr-badge mrr-badge--success">
                                                            {{ $row['review_status_label'] ?: 'Применено' }}
                                                        </span>
                                                    </div>
                                                    <div class="mrr-applied-card__summary-grid">
                                                        <div class="mrr-applied-card__summary-place">
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <div class="mrr-applied-card__summary-brief">
                                                            <div class="mrr-applied-card__decision-label">{{ $row['decision_label'] }}</div>
                                                            @if (filled($row['summary']))
                                                                <div class="mrr-applied-card__reason">{{ $row['summary'] }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mrr-applied-card__toggle" aria-hidden="true">
                                                    <span class="mrr-applied-card__toggle-open">Подробнее ▾</span>
                                                    <span class="mrr-applied-card__toggle-close">Скрыть ▴</span>
                                                </div>
                                            </summary>

                                            <div class="mrr-applied-card__body">
                                                <div class="mrr-applied-card__body-grid">
                                                    <div class="mrr-applied-card__column">
                                                        <div class="mrr-applied-card__label">Что применено</div>
                                                        <div class="mrr-place__title">{{ $row['decision_label'] }}</div>
                                                        @if (filled($row['review_status_label']))
                                                            <div class="mrr-applied-card__meta">{{ $row['review_status_label'] }}</div>
                                                        @endif
                                                    </div>

                                                    <div class="mrr-applied-card__column">
                                                        <div class="mrr-applied-card__label">Детали</div>
                                                        <div class="mrr-applied-summary">{{ $row['summary'] }}</div>
                                                    </div>

                                                    <div class="mrr-applied-card__column">
                                                        <div class="mrr-applied-card__label">Кем и когда</div>
                                                        <div class="mrr-place__title">{{ $row['created_by_name'] ?: '—' }}</div>
                                                        <div class="mrr-place__meta">{{ $row['effective_at'] ?: '—' }}</div>
                                                    </div>

                                                    @if ($row['is_auto_closed'])
                                                        <div class="mrr-applied-card__column">
                                                            <div class="mrr-applied-card__label">Закрыто автоматически</div>
                                                            <div class="mrr-place__title">Система</div>
                                                            @if (filled($row['auto_close_binding_id']))
                                                                <div class="mrr-place__meta">Основание: договорная привязка #{{ $row['auto_close_binding_id'] }}</div>
                                                            @endif
                                                            @if (filled($row['auto_close_at']))
                                                                <div class="mrr-place__meta">{{ $row['auto_close_at'] }}</div>
                                                            @endif
                                                        </div>
                                                    @endif

                                                    <div class="mrr-applied-card__column">
                                                        <div class="mrr-applied-card__label">Переходы</div>
                                                        <div class="mrr-links">
                                                            <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>
                </div>

                <div id="mrrDuplicatePlanModal" class="mrr-clarify-modal mrr-duplicate-plan-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-duplicate-plan-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrDuplicatePlanTitle"
                        aria-describedby="mrrDuplicatePlanDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-duplicate-plan-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Разбор дубля</div>
                        <h3 id="mrrDuplicatePlanTitle" class="mrr-clarify-modal__title">План безопасного разбора</h3>
                        <p id="mrrDuplicatePlanDescription" class="mrr-clarify-modal__description">
                            Выберите, какое место должно остаться основным. Система перенесёт карту, кабинет и товары на выбранную карточку, а вторую выведет из рабочего контура. Договоры, начисления и долги не переносятся.
                        </p>
                        <div id="mrrDuplicatePlanError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-duplicate-plan__grid">
                            <div id="mrrDuplicatePlanCurrentCard" class="mrr-duplicate-plan__card">
                                <div class="mrr-duplicate-plan__card-title">Место из ревизии</div>
                                <div id="mrrDuplicatePlanCurrentTitle" class="mrr-duplicate-plan__space">—</div>
                                <div id="mrrDuplicatePlanCurrentCounts" class="mrr-duplicate-plan__counts"></div>
                                <details class="mrr-duplicate-plan__details">
                                    <summary class="mrr-duplicate-plan__details-summary">Договоры</summary>
                                    <div id="mrrDuplicatePlanCurrentContracts" class="mrr-duplicate-plan__details-content"></div>
                                </details>
                                <details class="mrr-duplicate-plan__details">
                                    <summary class="mrr-duplicate-plan__details-summary">Начисления</summary>
                                    <div id="mrrDuplicatePlanCurrentAccruals" class="mrr-duplicate-plan__details-content"></div>
                                </details>
                                <div class="mrr-duplicate-plan__picker">
                                    <button type="button" id="mrrDuplicatePlanCurrentPick" class="mrr-duplicate-plan__picker-button" data-mrr-duplicate-plan-select="current">Оставить основным</button>
                                </div>
                                <div class="mrr-duplicate-plan__links">
                                    <a id="mrrDuplicatePlanCurrentSpaceLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть место</a>
                                    <a id="mrrDuplicatePlanCurrentMapLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть карту</a>
                                </div>
                            </div>

                            <div id="mrrDuplicatePlanCandidateCard" class="mrr-duplicate-plan__card">
                                <div class="mrr-duplicate-plan__card-title">Второе место того же арендатора</div>
                                <div id="mrrDuplicatePlanCandidateTitle" class="mrr-duplicate-plan__space">—</div>
                                <div id="mrrDuplicatePlanCandidateCounts" class="mrr-duplicate-plan__counts"></div>
                                <details class="mrr-duplicate-plan__details">
                                    <summary class="mrr-duplicate-plan__details-summary">Договоры</summary>
                                    <div id="mrrDuplicatePlanCandidateContracts" class="mrr-duplicate-plan__details-content"></div>
                                </details>
                                <details class="mrr-duplicate-plan__details">
                                    <summary class="mrr-duplicate-plan__details-summary">Начисления</summary>
                                    <div id="mrrDuplicatePlanCandidateAccruals" class="mrr-duplicate-plan__details-content"></div>
                                </details>
                                <div class="mrr-duplicate-plan__picker">
                                    <button type="button" id="mrrDuplicatePlanCandidatePick" class="mrr-duplicate-plan__picker-button" data-mrr-duplicate-plan-select="candidate">Оставить основным</button>
                                </div>
                                <div class="mrr-duplicate-plan__links">
                                    <a id="mrrDuplicatePlanCandidateSpaceLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть место</a>
                                    <a id="mrrDuplicatePlanCandidateMapLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть карту</a>
                                </div>
                            </div>
                        </div>

                        <div class="mrr-duplicate-plan__selection">
                            <div id="mrrDuplicatePlanSelectionTitle" class="mrr-duplicate-plan__selection-title">—</div>
                            <div id="mrrDuplicatePlanSelectionCopy" class="mrr-duplicate-plan__selection-copy">—</div>
                        </div>

                        <div class="mrr-duplicate-plan__section">
                            <h4>Что произойдёт после выбора</h4>
                            <ul class="mrr-duplicate-plan__list">
                                <li>Выбранное место останется основным для карты, кабинета и товаров.</li>
                                <li>Вторая карточка будет выведена из рабочего контура через is_active = false.</li>
                                <li>Договоры, начисления и долги не меняются. Если выбор нарушает защитные правила, система заблокирует действие.</li>
                            </ul>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-duplicate-plan-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-duplicate-plan-create>Применить разбор дубля</button>
                        </div>
                    </div>
                </div>

                <div id="mrrIdentityFixModal" class="mrr-clarify-modal mrr-identity-fix-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-identity-fix-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrIdentityFixTitle"
                        aria-describedby="mrrIdentityFixDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-identity-fix-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Уточнение места</div>
                        <h3 id="mrrIdentityFixTitle" class="mrr-clarify-modal__title">Уточнить номер / название</h3>
                        <p id="mrrIdentityFixDescription" class="mrr-clarify-modal__description">
                            Изменяются только номер и/или видимое название текущего места. Договоры, начисления, арендатор, группа и карта не переносятся.
                        </p>
                        <div id="mrrIdentityFixError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrIdentityFixNumber">Номер места</label>
                            <input id="mrrIdentityFixNumber" class="mrr-clarify-modal__input" type="text" maxlength="255">
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrIdentityFixDisplayName">Название для отображения</label>
                            <input id="mrrIdentityFixDisplayName" class="mrr-clarify-modal__input" type="text" maxlength="255">
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-identity-fix-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-identity-fix-save>Применить</button>
                        </div>
                    </div>
                </div>

                <div id="mrrMergeRetireModal" class="mrr-clarify-modal mrr-merge-retire-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-merge-retire-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrMergeRetireTitle"
                        aria-describedby="mrrMergeRetireDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-merge-retire-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Объединение места</div>
                        <h3 id="mrrMergeRetireTitle" class="mrr-clarify-modal__title">Упразднить и связать с основным местом</h3>
                        <p id="mrrMergeRetireDescription" class="mrr-clarify-modal__description">
                            Старое место станет архивным. Договоры, начисления и долги останутся на нём как история; активная разметка будет снята с карты.
                        </p>
                        <div id="mrrMergeRetireError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrMergeRetireCanonicalId">ID основного места</label>
                            <input id="mrrMergeRetireCanonicalId" class="mrr-clarify-modal__input" type="number" min="1" step="1" inputmode="numeric">
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrMergeRetireEffectiveDate">Дата действия</label>
                            <input id="mrrMergeRetireEffectiveDate" class="mrr-clarify-modal__input" type="date">
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrMergeRetireReason">Комментарий</label>
                            <textarea id="mrrMergeRetireReason" class="mrr-clarify-modal__input mrr-quick-review__field" maxlength="2000" placeholder="Например: место физически объединено с соседним местом"></textarea>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-merge-retire-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-merge-retire-save>Упразднить</button>
                        </div>
                    </div>
                </div>

                <div id="mrrContractTenantSwitchModal" class="mrr-clarify-modal mrr-contract-tenant-switch-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-contract-tenant-switch-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrContractTenantSwitchTitle"
                        aria-describedby="mrrContractTenantSwitchDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-contract-tenant-switch-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Смена арендатора</div>
                        <h3 id="mrrContractTenantSwitchTitle" class="mrr-clarify-modal__title">Подтвердить смену арендатора</h3>
                        <p id="mrrContractTenantSwitchDescription" class="mrr-clarify-modal__description">
                            Новый арендатор уже найден по договору. Проверьте дату действия и подтвердите смену. Старые начисления и долги останутся на прежнем арендаторе.
                        </p>
                        <div id="mrrContractTenantSwitchError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrContractTenantSwitchTenant">Новый арендатор</label>
                            <input id="mrrContractTenantSwitchTenant" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrContractTenantSwitchContract">Договор</label>
                            <input id="mrrContractTenantSwitchContract" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrContractTenantSwitchEffectiveDate">Дата начала действия</label>
                            <input id="mrrContractTenantSwitchEffectiveDate" class="mrr-clarify-modal__input" type="date">
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrContractTenantSwitchReason">Комментарий</label>
                            <textarea id="mrrContractTenantSwitchReason" class="mrr-clarify-modal__input mrr-quick-review__field" maxlength="2000" placeholder="Необязательно: что проверили перед подтверждением"></textarea>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-quick-review__choice" style="justify-content:flex-start;gap:0.5rem;padding:0.75rem 0.9rem;border-radius:0.875rem;">
                                <input id="mrrContractTenantSwitchCloseContract" type="checkbox" style="margin:0;">
                                <span>Завершить прежний договор датой начала нового договора</span>
                            </label>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-contract-tenant-switch-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-contract-tenant-switch-save>Запланировать смену</button>
                        </div>
                    </div>
                </div>

                <div id="mrrFinancialTenantResolveModal" class="mrr-clarify-modal mrr-contract-tenant-switch-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-financial-tenant-resolve-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrFinancialTenantResolveTitle"
                        aria-describedby="mrrFinancialTenantResolveDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-financial-tenant-resolve-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Справочник арендаторов</div>
                        <h3 id="mrrFinancialTenantResolveTitle" class="mrr-clarify-modal__title">&#x0412;&#x043E;&#x0441;&#x0441;&#x0442;&#x0430;&#x043D;&#x043E;&#x0432;&#x0438;&#x0442;&#x044C; &#x0430;&#x0440;&#x0435;&#x043D;&#x0434;&#x0430;&#x0442;&#x043E;&#x0440;&#x0430;</h3>
                        <p id="mrrFinancialTenantResolveDescription" class="mrr-clarify-modal__description">
                            Финансовый сигнал указывает на арендатора, которого пока нельзя выбрать в смене арендатора. Сначала восстановите его карточку в локальной базе, затем выполните обычную смену арендатора.
                        </p>
                        <div id="mrrFinancialTenantResolveError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrFinancialTenantResolveName">Арендатор из сигнала</label>
                            <input id="mrrFinancialTenantResolveName" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrFinancialTenantResolvePreferredTenant">Кандидат в БД</label>
                            <input id="mrrFinancialTenantResolvePreferredTenant" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrFinancialTenantResolveExternalId">External ID source</label>
                            <input id="mrrFinancialTenantResolveExternalId" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrFinancialTenantResolveInn">ИНН</label>
                            <input id="mrrFinancialTenantResolveInn" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrFinancialTenantResolveKpp">КПП</label>
                            <input id="mrrFinancialTenantResolveKpp" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-financial-tenant-resolve-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-financial-tenant-resolve-save>Создать/сопоставить</button>
                        </div>
                    </div>
                </div>

                <div id="mrrManualTenantSwitchModal" class="mrr-clarify-modal mrr-contract-tenant-switch-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-manual-tenant-switch-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrManualTenantSwitchTitle"
                        aria-describedby="mrrManualTenantSwitchDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-manual-tenant-switch-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Смена арендатора</div>
                        <h3 id="mrrManualTenantSwitchTitle" class="mrr-clarify-modal__title">Сменить арендатора на карточке ревизии</h3>
                        <p id="mrrManualTenantSwitchDescription" class="mrr-clarify-modal__description">
                            Подтвердите нового арендатора для места, укажите дату начала действия и при необходимости завершите прежний договор этой же датой.
                        </p>
                        <div id="mrrManualTenantSwitchError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrManualTenantSwitchCurrentTenant">Текущий арендатор</label>
                            <input id="mrrManualTenantSwitchCurrentTenant" class="mrr-clarify-modal__input" type="text" readonly>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrManualTenantSwitchTenantSearch">Новый арендатор</label>
                            <input id="mrrManualTenantSwitchTenant" type="hidden" value="">
                            <input
                                id="mrrManualTenantSwitchTenantSearch"
                                class="mrr-clarify-modal__input"
                                type="search"
                                inputmode="search"
                                autocomplete="off"
                                placeholder="Начните вводить имя арендатора"
                            >
                            <div id="mrrManualTenantSwitchTenantHint" class="mrr-quick-review__hint">Начните вводить имя арендатора, чтобы выбрать нужного.</div>
                            <div id="mrrManualTenantSwitchTenantSuggestions" class="mrr-manual-tenant-switch__suggestions" hidden></div>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrManualTenantSwitchEffectiveDate">Дата начала действия</label>
                            <input id="mrrManualTenantSwitchEffectiveDate" class="mrr-clarify-modal__input" type="date">
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrManualTenantSwitchReason">Комментарий</label>
                            <textarea id="mrrManualTenantSwitchReason" class="mrr-clarify-modal__input mrr-quick-review__field" maxlength="2000" placeholder="Что проверили перед сменой арендатора"></textarea>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-quick-review__choice" style="justify-content:flex-start;gap:0.5rem;padding:0.75rem 0.9rem;border-radius:0.875rem;">
                                <input id="mrrManualTenantSwitchCloseContract" type="checkbox" style="margin:0;">
                                <span>Завершить прежний договор датой начала нового договора</span>
                            </label>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-manual-tenant-switch-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-manual-tenant-switch-save>Запланировать смену</button>
                        </div>
                    </div>
                </div>

                <div id="mrrQuickReviewModal" class="mrr-clarify-modal mrr-quick-review-modal" hidden aria-hidden="true">
                    <div class="mrr-clarify-modal__backdrop" data-mrr-quick-review-close></div>
                    <div
                        class="mrr-clarify-modal__dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mrrQuickReviewTitle"
                        aria-describedby="mrrQuickReviewDescription"
                    >
                        <button type="button" class="mrr-clarify-modal__close" data-mrr-quick-review-close aria-label="Закрыть">×</button>
                        <div class="mrr-clarify-modal__eyebrow">Закрытие карточки</div>
                        <h3 id="mrrQuickReviewTitle" class="mrr-clarify-modal__title">Закрыть без изменений</h3>
                        <p id="mrrQuickReviewDescription" class="mrr-clarify-modal__description">
                            Используйте это действие только если карточку проверили и менять данные места не нужно. Карточка будет закрыта как проверенная, без изменения статуса, арендатора, карты и связей.
                        </p>
                        <div id="mrrQuickReviewError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrQuickReviewReason">Комментарий к закрытию</label>
                            <textarea
                                id="mrrQuickReviewReason"
                                class="mrr-clarify-modal__input mrr-quick-review__field"
                                rows="4"
                                placeholder="Коротко напишите, почему данные менять не нужно"
                            ></textarea>
                            <div class="mrr-quick-review__hint">Комментарий сохранится в истории ревизии. Само действие не меняет данные места.</div>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-quick-review-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-quick-review-save>Закрыть карточку</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="mrrConfirmFreeModal" class="mrr-clarify-modal" hidden aria-hidden="true">
                <div class="mrr-clarify-modal__backdrop" data-mrr-confirm-free-close></div>
                <div
                    class="mrr-clarify-modal__dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="mrrConfirmFreeTitle"
                    aria-describedby="mrrConfirmFreeDescription"
                >
                    <button type="button" class="mrr-clarify-modal__close" data-mrr-confirm-free-close aria-label="Закрыть">×</button>
                    <div class="mrr-clarify-modal__eyebrow">Изменение места</div>
                    <h3 id="mrrConfirmFreeTitle" class="mrr-clarify-modal__title">Подтвердить свободно</h3>
                    <p id="mrrConfirmFreeDescription" class="mrr-clarify-modal__description">
                        Статус места изменится на свободное. Локальная текущая привязка будет закрыта, а договоры, начисления и долги не будут изменены автоматически.
                    </p>
                    <div id="mrrConfirmFreeError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                    <div class="mrr-quick-review__clarify mrr-quick-review__clarify--success">
                        <div class="mrr-quick-review__clarify-title">Что произойдёт</div>
                        <div id="mrrConfirmFreeSummary" class="mrr-quick-review__clarify-text">
                            Место будет зафиксировано как свободное после ручной проверки.
                        </div>
                    </div>

                    <div class="mrr-clarify-modal__field">
                        <label class="mrr-clarify-modal__label" for="mrrConfirmFreeReason">Комментарий к изменению</label>
                        <textarea
                            id="mrrConfirmFreeReason"
                            class="mrr-clarify-modal__input mrr-quick-review__field"
                            rows="4"
                            placeholder="Например: на месте нет арендатора, проверено по карте и карточке"
                        ></textarea>
                        <div class="mrr-quick-review__hint">Комментарий сохранится в истории ревизии вместе с изменением статуса места.</div>
                    </div>

                    <div class="mrr-clarify-modal__actions">
                        <button type="button" class="mrr-clarify-modal__button" data-mrr-confirm-free-close>Отмена</button>
                        <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-confirm-free-save>Подтвердить свободно</button>
                    </div>
                </div>
            </div>
        @endif

        <script>
            (() => {
                const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
                const reviewContractTenantSwitchUrl = @json(route('filament.admin.market-map.review-contract-tenant-switch'));
                const reviewTenantSwitchUrl = @json(route('filament.admin.market-map.review-tenant-switch'));
                const reviewResolveFinancialTenantUrl = @json(route('filament.admin.market-map.review-resolve-financial-tenant'));
                const aiReviewUrl = @json(route('filament.admin.map-review-results.ai-review'));
                const tenantSwitchOptions = @json(array_values($tenantSwitchOptions ?? []));
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const quickReviewModal = document.getElementById('mrrQuickReviewModal');
                const quickReviewTitle = document.getElementById('mrrQuickReviewTitle');
                const quickReviewDescription = document.getElementById('mrrQuickReviewDescription');
                const quickReviewReason = document.getElementById('mrrQuickReviewReason');
                const quickReviewError = document.getElementById('mrrQuickReviewError');
                const quickReviewSave = quickReviewModal?.querySelector('[data-mrr-quick-review-save]');
                const quickReviewChoiceButtons = Array.from(document.querySelectorAll('[data-mrr-quick-review-choice]'));
                const quickReviewHintBlocks = Array.from(document.querySelectorAll('[data-mrr-quick-review-hint]'));
                const confirmFreeModal = document.getElementById('mrrConfirmFreeModal');
                const confirmFreeReason = document.getElementById('mrrConfirmFreeReason');
                const confirmFreeError = document.getElementById('mrrConfirmFreeError');
                const confirmFreeSummary = document.getElementById('mrrConfirmFreeSummary');
                const confirmFreeSave = confirmFreeModal?.querySelector('[data-mrr-confirm-free-save]');
                const identityFixModal = document.getElementById('mrrIdentityFixModal');
                const identityFixNumber = document.getElementById('mrrIdentityFixNumber');
                const identityFixDisplayName = document.getElementById('mrrIdentityFixDisplayName');
                const identityFixError = document.getElementById('mrrIdentityFixError');
                const identityFixSave = identityFixModal?.querySelector('[data-mrr-identity-fix-save]');
                const mergeRetireModal = document.getElementById('mrrMergeRetireModal');
                const mergeRetireCanonicalId = document.getElementById('mrrMergeRetireCanonicalId');
                const mergeRetireEffectiveDate = document.getElementById('mrrMergeRetireEffectiveDate');
                const mergeRetireReason = document.getElementById('mrrMergeRetireReason');
                const mergeRetireError = document.getElementById('mrrMergeRetireError');
                const mergeRetireSave = mergeRetireModal?.querySelector('[data-mrr-merge-retire-save]');
                const contractTenantSwitchModal = document.getElementById('mrrContractTenantSwitchModal');
                const contractTenantSwitchTenant = document.getElementById('mrrContractTenantSwitchTenant');
                const contractTenantSwitchContract = document.getElementById('mrrContractTenantSwitchContract');
                const contractTenantSwitchEffectiveDate = document.getElementById('mrrContractTenantSwitchEffectiveDate');
                const contractTenantSwitchReason = document.getElementById('mrrContractTenantSwitchReason');
                const contractTenantSwitchCloseContract = document.getElementById('mrrContractTenantSwitchCloseContract');
                const contractTenantSwitchError = document.getElementById('mrrContractTenantSwitchError');
                const contractTenantSwitchSave = contractTenantSwitchModal?.querySelector('[data-mrr-contract-tenant-switch-save]');
                const financialTenantResolveModal = document.getElementById('mrrFinancialTenantResolveModal');
                const financialTenantResolveTitle = document.getElementById('mrrFinancialTenantResolveTitle');
                const financialTenantResolveName = document.getElementById('mrrFinancialTenantResolveName');
                const financialTenantResolveExternalId = document.getElementById('mrrFinancialTenantResolveExternalId');
                const financialTenantResolveInn = document.getElementById('mrrFinancialTenantResolveInn');
                const financialTenantResolveKpp = document.getElementById('mrrFinancialTenantResolveKpp');
                const financialTenantResolvePreferredTenant = document.getElementById('mrrFinancialTenantResolvePreferredTenant');
                const financialTenantResolveError = document.getElementById('mrrFinancialTenantResolveError');
                const financialTenantResolveSave = financialTenantResolveModal?.querySelector('[data-mrr-financial-tenant-resolve-save]');
                const manualTenantSwitchModal = document.getElementById('mrrManualTenantSwitchModal');
                const manualTenantSwitchCurrentTenant = document.getElementById('mrrManualTenantSwitchCurrentTenant');
                const manualTenantSwitchTenant = document.getElementById('mrrManualTenantSwitchTenant');
                const manualTenantSwitchTenantSearch = document.getElementById('mrrManualTenantSwitchTenantSearch');
                const manualTenantSwitchTenantHint = document.getElementById('mrrManualTenantSwitchTenantHint');
                const manualTenantSwitchTenantSuggestions = document.getElementById('mrrManualTenantSwitchTenantSuggestions');
                const manualTenantSwitchEffectiveDate = document.getElementById('mrrManualTenantSwitchEffectiveDate');
                const manualTenantSwitchReason = document.getElementById('mrrManualTenantSwitchReason');
                const manualTenantSwitchCloseContract = document.getElementById('mrrManualTenantSwitchCloseContract');
                const manualTenantSwitchError = document.getElementById('mrrManualTenantSwitchError');
                const manualTenantSwitchSave = manualTenantSwitchModal?.querySelector('[data-mrr-manual-tenant-switch-save]');
                const modal = document.getElementById('mrrDuplicatePlanModal');
                const currentCard = document.getElementById('mrrDuplicatePlanCurrentCard');
                const candidateCard = document.getElementById('mrrDuplicatePlanCandidateCard');
                const currentTitle = document.getElementById('mrrDuplicatePlanCurrentTitle');
                const candidateTitle = document.getElementById('mrrDuplicatePlanCandidateTitle');
                const currentCounts = document.getElementById('mrrDuplicatePlanCurrentCounts');
                const candidateCounts = document.getElementById('mrrDuplicatePlanCandidateCounts');
                const currentContractsTarget = document.getElementById('mrrDuplicatePlanCurrentContracts');
                const currentAccrualsTarget = document.getElementById('mrrDuplicatePlanCurrentAccruals');
                const candidateContractsTarget = document.getElementById('mrrDuplicatePlanCandidateContracts');
                const candidateAccrualsTarget = document.getElementById('mrrDuplicatePlanCandidateAccruals');
                const currentPick = document.getElementById('mrrDuplicatePlanCurrentPick');
                const candidatePick = document.getElementById('mrrDuplicatePlanCandidatePick');
                const currentSpaceLink = document.getElementById('mrrDuplicatePlanCurrentSpaceLink');
                const currentMapLink = document.getElementById('mrrDuplicatePlanCurrentMapLink');
                const candidateSpaceLink = document.getElementById('mrrDuplicatePlanCandidateSpaceLink');
                const candidateMapLink = document.getElementById('mrrDuplicatePlanCandidateMapLink');
                const selectionTitle = document.getElementById('mrrDuplicatePlanSelectionTitle');
                const selectionCopy = document.getElementById('mrrDuplicatePlanSelectionCopy');
                const createButton = modal?.querySelector('[data-mrr-duplicate-plan-create]');
                const error = document.getElementById('mrrDuplicatePlanError');
                const quickReviewState = {
                    decision: '',
                    label: '',
                    reasonRequired: false,
                    spaceId: 0,
                };
                const confirmFreeState = {
                    spaceId: 0,
                    spaceLabel: '',
                };
                const identityFixState = {
                    spaceId: 0,
                    originalNumber: '',
                    originalDisplayName: '',
                };
                const contractTenantSwitchState = {
                    spaceId: 0,
                    tenantId: 0,
                    contractId: 0,
                    currentTenantName: '',
                    tenantName: '',
                    contractNumber: '',
                    closePreviousContract: false,
                };
                const financialTenantResolveState = {
                    spaceId: 0,
                    accrualId: 0,
                    tenantName: '',
                    tenantExternalId: '',
                    tenantInn: '',
                    tenantKpp: '',
                    preferredTenantId: 0,
                    preferredTenantName: '',
                    resolutionAction: '',
                    resolutionLabel: 'Создать/сопоставить',
                };
                const manualTenantSwitchState = {
                    spaceId: 0,
                    currentTenantName: '',
                    suggestedTenantId: 0,
                    suggestedTenantName: '',
                    closePreviousContract: false,
                };
                const normalizedTenantSwitchOptions = Array.isArray(tenantSwitchOptions)
                    ? tenantSwitchOptions
                        .map((tenantOption) => {
                            const tenantId = Number(tenantOption?.id || 0);
                            const tenantName = String(tenantOption?.name || '').trim();

                            if (!Number.isFinite(tenantId) || tenantId <= 0 || tenantName === '') {
                                return null;
                            }

                            return {
                                id: tenantId,
                                name: tenantName,
                                normalizedName: tenantName.toLocaleLowerCase('ru-RU').replace(/\s+/g, ' ').trim(),
                            };
                        })
                        .filter(Boolean)
                    : [];
                const mergeRetireState = {
                    spaceId: 0,
                    spaceLabel: '',
                };
                const duplicatePlanState = {
                    selectedPrimary: 'candidate',
                    currentCounts: [],
                    candidateCounts: [],
                    currentSpaceId: 0,
                    candidateSpaceId: 0,
                    currentLabel: '',
                    candidateLabel: '',
                };

                if (
                    (!modal
                    || !currentCard
                    || !candidateCard
                    || !currentTitle
                    || !candidateTitle
                    || !currentCounts
                    || !candidateCounts
                    || !currentContractsTarget
                    || !currentAccrualsTarget
                    || !candidateContractsTarget
                    || !candidateAccrualsTarget
                    || !currentPick
                    || !candidatePick
                    || !currentSpaceLink
                    || !currentMapLink
                    || !candidateSpaceLink
                    || !candidateMapLink
                    || !selectionTitle
                    || !selectionCopy
                    || !createButton
                    || !error)
                    && !quickReviewModal
                    && !confirmFreeModal
                    && !contractTenantSwitchModal
                    && !financialTenantResolveModal
                    && !manualTenantSwitchModal
                ) {
                    return;
                }

                const parseJson = (value, fallback) => {
                    if (!value) {
                        return fallback;
                    }

                    try {
                        const parsed = JSON.parse(value);
                        return parsed ?? fallback;
                    } catch (e) {
                        return fallback;
                    }
                };

                const renderCounts = (target, counts) => {
                    target.innerHTML = '';

                    const items = Array.isArray(counts)
                        ? counts
                        : [];

                    if (items.length === 0) {
                        const empty = document.createElement('span');
                        empty.className = 'mrr-diagnostics__hint';
                        empty.textContent = 'СВЯЗЕЙ НЕ НАЙДЕНО';
                        target.appendChild(empty);
                        return;
                    }

                    items.forEach((item) => {
                        const badge = document.createElement('span');
                        badge.className = 'mrr-diagnostics__count';

                        if (typeof item === 'string') {
                            badge.textContent = item;
                        } else {
                            const label = String(item?.label || '').trim();
                            const count = Number(item?.count || 0);
                            badge.textContent = label ? `${label}: ${count}` : String(count);

                            if (item?.important) {
                                badge.classList.add('mrr-diagnostics__count--important');
                            }
                        }

                        target.appendChild(badge);
                    });
                };

                const escapeHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');

                const appendDetailLine = (target, value) => {
                    const normalized = String(value ?? '').trim();

                    if (!normalized) {
                        return;
                    }

                    const line = document.createElement('div');
                    line.className = 'mrr-duplicate-plan__details-meta';
                    line.textContent = normalized;
                    target.appendChild(line);
                };

                const renderDuplicatePlanDetailList = (target, items, type) => {
                    target.replaceChildren();

                    const rows = Array.isArray(items) ? items : [];

                    if (rows.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'mrr-duplicate-plan__details-empty';
                        empty.textContent = 'Нет данных';
                        target.appendChild(empty);
                        return;
                    }

                    rows.forEach((item) => {
                        const card = document.createElement('div');
                        card.className = 'mrr-duplicate-plan__details-item';

                        const title = document.createElement('div');
                        title.className = 'mrr-duplicate-plan__details-title';

                        if (type === 'contract') {
                            title.textContent = item?.number
                                ? `Договор ${item.number}`
                                : `Договор #${item?.id || '—'}`;

                            card.appendChild(title);
                            appendDetailLine(card, item?.tenant_name ? `Арендатор: ${item.tenant_name}` : '');
                            appendDetailLine(card, item?.status ? `Статус: ${item.status}` : '');
                            appendDetailLine(card, item?.starts_at || item?.ends_at
                                ? `Период: ${item?.starts_at || '—'} — ${item?.ends_at || '—'}`
                                : '');
                        } else {
                            title.textContent = item?.period
                                ? `Начисление за ${item.period}`
                                : `Начисление #${item?.id || '—'}`;

                            card.appendChild(title);
                            appendDetailLine(card, item?.tenant_name ? `Арендатор: ${item.tenant_name}` : '');
                            appendDetailLine(card, item?.total_with_vat !== null && item?.total_with_vat !== undefined ? `С НДС: ${item.total_with_vat}` : '');
                            appendDetailLine(card, item?.cash_amount !== null && item?.cash_amount !== undefined ? `Наличные / без НДС: ${item.cash_amount}` : '');
                            appendDetailLine(card, item?.source ? `Источник: ${item.source}` : '');
                            appendDetailLine(card, item?.contract_number ? `Договор: ${item.contract_number}` : '');

                            if (item?.contract_space_mismatch) {
                                const warning = document.createElement('div');
                                warning.className = 'mrr-duplicate-plan__details-warning';
                                warning.textContent = 'Начисление связано с договором другого места. Перед разбором дубля проверьте, где должна остаться финансовая история.';
                                card.appendChild(warning);
                            }
                        }

                        target.appendChild(card);
                    });
                };

                const renderDuplicatePlanDetails = (currentContracts, currentAccruals, candidateContracts, candidateAccruals) => {
                    renderDuplicatePlanDetailList(currentContractsTarget, currentContracts, 'contract');
                    renderDuplicatePlanDetailList(currentAccrualsTarget, currentAccruals, 'accrual');
                    renderDuplicatePlanDetailList(candidateContractsTarget, candidateContracts, 'contract');
                    renderDuplicatePlanDetailList(candidateAccrualsTarget, candidateAccruals, 'accrual');
                };

                const humanizeAiText = (value) => {
                    const text = String(value ?? '')
                        .replaceAll('_', ' ')
                        .replace(/\s+/g, ' ')
                        .trim();

                    if (!text) {
                        return '';
                    }

                    return text.charAt(0).toUpperCase() + text.slice(1);
                };

                const renderAiPlaceholder = (panel, message, modifier = 'empty') => {
                    panel.replaceChildren();

                    const wrapper = document.createElement('div');
                    wrapper.className = `mrr-ai--${modifier}`;

                    const placeholder = document.createElement('span');
                    placeholder.className = 'mrr-ai__placeholder';
                    placeholder.textContent = String(message || '');

                    wrapper.appendChild(placeholder);
                    panel.appendChild(wrapper);
                };

                const createAiSection = (className, label, value) => {
                    const section = document.createElement('div');
                    section.className = className;

                    const strong = document.createElement('strong');
                    strong.textContent = label;
                    section.appendChild(strong);
                    section.append(` ${value}`);

                    return section;
                };

                const renderAiReview = (panel, payload = {}) => {
                    const review = payload && typeof payload.review === 'object' && payload.review !== null
                        ? payload.review
                        : null;
                    const errorType = String(payload?.error_type || '').trim();
                    const summary = humanizeAiText(review?.summary);
                    const whyFlagged = humanizeAiText(review?.why_flagged);
                    const nextStep = humanizeAiText(review?.recommended_next_step);
                    const recommendedAction = humanizeAiText(review?.recommended_action_label || review?.recommended_action);

                    if (summary || whyFlagged || recommendedAction || nextStep) {
                        const sections = [];

                        if (summary) {
                            sections.push(`
                                \u003Cdiv class="mrr-ai__summary">
                                    <strong>Ситуация:</strong> ${escapeHtml(summary)}
                                \u003C/div>
                            `);
                        }

                        if (whyFlagged) {
                            sections.push(`
                                \u003Cdiv class="mrr-ai__reason">
                                    <strong>Почему:</strong> ${escapeHtml(whyFlagged)}
                                \u003C/div>
                            `);
                        }

                        if (recommendedAction) {
                            sections.push(`
                                \u003Cdiv class="mrr-ai__action">
                                    <strong>Рекомендованное действие:</strong> ${escapeHtml(recommendedAction)}
                                \u003C/div>
                            `);
                        }

                        if (nextStep) {
                            sections.push(`
                                \u003Cdiv class="mrr-ai__step">
                                    <strong>Что сделать:</strong> ${escapeHtml(nextStep)}
                                \u003C/div>
                            `);
                        }

                        panel.innerHTML = sections.join('');
                        return;
                    }

                    if (errorType === 'policy') {
                        renderAiPlaceholder(panel, 'ИИ-анализ отклонён проверкой качества ответа');
                        return;
                    }

                    if (errorType === 'disabled') {
                        renderAiPlaceholder(panel, 'ИИ-разбор отключён в этом окружении');
                        return;
                    }

                    if (errorType === 'connectivity') {
                        renderAiPlaceholder(panel, 'ИИ-анализ временно недоступен из-за ошибки соединения');
                        return;
                    }

                    renderAiPlaceholder(panel, 'ИИ-анализ недоступен');
                };

                const loadAiReview = async (spaceId, button = null) => {
                    const normalizedSpaceId = Number(spaceId || 0);
                    const panel = Number.isFinite(normalizedSpaceId) && normalizedSpaceId > 0
                        ? document.querySelector(`[data-mrr-ai-panel][data-mrr-space-id="${normalizedSpaceId}"]`)
                        : null;

                    if (!(panel instanceof HTMLElement)) {
                        return;
                    }

                    panel.classList.add('is-loading');

                    if (button instanceof HTMLElement) {
                        button.setAttribute('disabled', 'disabled');
                        button.textContent = 'Загружаем ИИ-разбор...';
                    }

                    try {
                        const url = new URL(aiReviewUrl, window.location.origin);
                        url.searchParams.set('space_id', String(normalizedSpaceId));

                        const response = await fetch(url.toString(), {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data?.ok) {
                            throw new Error(String(data?.message || 'Не удалось загрузить ИИ-разбор.'));
                        }

                        renderAiReview(panel, data);
                    } catch (errorInstance) {
                        renderAiReview(panel, {
                            review: null,
                            error_type: 'connectivity',
                        });
                    } finally {
                        panel.classList.remove('is-loading');

                        if (button instanceof HTMLElement) {
                            button.removeAttribute('disabled');
                        }
                    }
                };

                const setLink = (link, href) => {
                    const url = String(href || '').trim();

                    if (!url) {
                        link.removeAttribute('href');
                        link.setAttribute('aria-disabled', 'true');
                        link.classList.add('is-disabled');
                        return;
                    }

                    link.href = url;
                    link.removeAttribute('aria-disabled');
                    link.classList.remove('is-disabled');
                };

                const countByLabel = (counts, label) => {
                    const normalizedLabel = String(label || '').trim().toLowerCase();
                    const items = Array.isArray(counts) ? counts : [];

                    for (const item of items) {
                        if (typeof item === 'string') {
                            const match = item.match(/^(.+?):\s*(-?\d+)$/);

                            if (match && String(match[1] || '').trim().toLowerCase() === normalizedLabel) {
                                return Number(match[2] || 0);
                            }
                        } else if (item && String(item.label || '').trim().toLowerCase() === normalizedLabel) {
                            return Number(item.count || 0);
                        }
                    }

                    return 0;
                };

                const updateDuplicatePlanSelection = (selectedPrimary) => {
                    duplicatePlanState.selectedPrimary = selectedPrimary === 'current' ? 'current' : 'candidate';

                    const keepCurrent = duplicatePlanState.selectedPrimary === 'current';
                    const primaryLabel = keepCurrent ? duplicatePlanState.currentLabel : duplicatePlanState.candidateLabel;
                    const secondaryLabel = keepCurrent ? duplicatePlanState.candidateLabel : duplicatePlanState.currentLabel;
                    const transferSourceCounts = keepCurrent ? duplicatePlanState.candidateCounts : duplicatePlanState.currentCounts;
                    const mapCount = countByLabel(transferSourceCounts, 'Карта');
                    const cabinetCount = countByLabel(transferSourceCounts, 'Кабинет');
                    const productCount = countByLabel(transferSourceCounts, 'Товары');

                    currentCard.classList.toggle('is-selected', keepCurrent);
                    candidateCard.classList.toggle('is-selected', !keepCurrent);
                    currentPick.classList.toggle('is-selected', keepCurrent);
                    candidatePick.classList.toggle('is-selected', !keepCurrent);

                    selectionTitle.textContent = keepCurrent
                        ? 'Основным останется место из ревизии'
                        : 'Основным станет второе место того же арендатора';
                    selectionCopy.textContent = `${primaryLabel || 'Выбранное место'} останется основным. Система перенесёт с ${secondaryLabel || 'второй карточки'} карту: ${mapCount}, кабинет: ${cabinetCount}, товары: ${productCount}, а вторую карточку выведет из рабочего контура. Договоры, начисления и долги не переносятся.`;
                    createButton.textContent = 'Применить разбор дубля';
                };

                const openModal = (button) => {
                    const currentLabel = String(button.dataset.currentLabel || '').trim();
                    const candidateLabel = String(button.dataset.candidateLabel || '').trim();
                    const currentSpaceId = String(button.dataset.currentSpaceId || '').trim();
                    const candidateSpaceId = String(button.dataset.candidateSpaceId || '').trim();
                    const parsedCurrentCounts = parseJson(button.dataset.currentCounts, []);
                    const parsedCandidateCounts = parseJson(button.dataset.candidateCounts, []);
                    const parsedCurrentContracts = parseJson(button.dataset.currentContracts, []);
                    const parsedCurrentAccruals = parseJson(button.dataset.currentAccruals, []);
                    const parsedCandidateContracts = parseJson(button.dataset.candidateContracts, []);
                    const parsedCandidateAccruals = parseJson(button.dataset.candidateAccruals, []);

                    currentTitle.textContent = currentLabel
                        ? `#${currentSpaceId} · ${currentLabel}`
                        : `#${currentSpaceId}`;
                    candidateTitle.textContent = candidateLabel
                        ? `#${candidateSpaceId} · ${candidateLabel}`
                        : `#${candidateSpaceId}`;

                    renderCounts(currentCounts, parsedCurrentCounts);
                    renderCounts(candidateCounts, parsedCandidateCounts);
                    setLink(currentSpaceLink, button.dataset.currentSpaceUrl);
                    setLink(currentMapLink, countByLabel(parsedCurrentCounts, 'Карта') > 0 ? button.dataset.currentMapUrl : '');
                    setLink(candidateSpaceLink, button.dataset.candidateSpaceUrl);
                    setLink(candidateMapLink, countByLabel(parsedCandidateCounts, 'Карта') > 0 ? button.dataset.candidateMapUrl : '');
                    error.textContent = '';
                    modal.dataset.currentSpaceId = currentSpaceId;
                    modal.dataset.candidateSpaceId = candidateSpaceId;
                    duplicatePlanState.currentCounts = parsedCurrentCounts;
                    duplicatePlanState.candidateCounts = parsedCandidateCounts;
                    duplicatePlanState.currentSpaceId = Number(currentSpaceId || 0);
                    duplicatePlanState.candidateSpaceId = Number(candidateSpaceId || 0);
                    duplicatePlanState.currentLabel = currentLabel ? `#${currentSpaceId} · ${currentLabel}` : `#${currentSpaceId}`;
                    duplicatePlanState.candidateLabel = candidateLabel ? `#${candidateSpaceId} · ${candidateLabel}` : `#${candidateSpaceId}`;
                    updateDuplicatePlanSelection('candidate');
                    renderDuplicatePlanDetails(
                        parsedCurrentContracts,
                        parsedCurrentAccruals,
                        parsedCandidateContracts,
                        parsedCandidateAccruals,
                    );

                    modal.hidden = false;
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                };

                const closeModal = () => {
                    modal.classList.remove('is-open');
                    modal.hidden = true;
                    modal.setAttribute('aria-hidden', 'true');
                    delete modal.dataset.currentSpaceId;
                    delete modal.dataset.candidateSpaceId;
                    error.textContent = '';
                    createButton.removeAttribute('disabled');
                    duplicatePlanState.selectedPrimary = 'candidate';
                    createButton.textContent = 'Применить разбор дубля';
                };

                const syncQuickReviewChoiceState = () => {
                    quickReviewChoiceButtons.forEach((choiceButton) => {
                        const isSelected = String(choiceButton.dataset.mrrQuickReviewChoice || '') === quickReviewState.decision;
                        choiceButton.classList.toggle('is-selected', isSelected);
                    });
                };

                const syncQuickReviewHintState = (decision) => {
                    quickReviewHintBlocks.forEach((hintBlock) => {
                        hintBlock.hidden = String(hintBlock.dataset.mrrQuickReviewHint || '') !== decision;
                    });
                };

                const openQuickReviewModal = (button) => {
                    if (!quickReviewModal || !quickReviewTitle || !quickReviewDescription || !quickReviewReason || !quickReviewError || !quickReviewSave) {
                        return;
                    }

                    const currentSpaceId = Number(button.closest('[data-mrr-space-id]')?.dataset.mrrSpaceId || 0);

                    if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0) {
                        return;
                    }

                    quickReviewState.spaceId = currentSpaceId;
                    quickReviewState.decision = 'matched';
                    quickReviewState.label = 'Закрыть без изменений';
                    quickReviewState.reasonRequired = true;

                    quickReviewTitle.textContent = 'Закрыть без изменений';
                    quickReviewDescription.textContent = 'Карточка будет закрыта как проверенная. Данные места, арендатор, карта и связи не изменятся.';
                    quickReviewReason.value = '';
                    quickReviewReason.required = true;
                    quickReviewError.textContent = '';
                    quickReviewSave.removeAttribute('disabled');
                    quickReviewSave.textContent = 'Закрыть карточку';
                    syncQuickReviewChoiceState();
                    syncQuickReviewHintState('');

                    quickReviewModal.hidden = false;
                    quickReviewModal.classList.add('is-open');
                    quickReviewModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => quickReviewReason.focus(), 0);
                };

                const closeQuickReviewModal = () => {
                    if (!quickReviewModal || !quickReviewReason || !quickReviewError || !quickReviewSave) {
                        return;
                    }

                    quickReviewModal.classList.remove('is-open');
                    quickReviewModal.hidden = true;
                    quickReviewModal.setAttribute('aria-hidden', 'true');
                    quickReviewState.decision = '';
                    quickReviewState.label = '';
                    quickReviewState.reasonRequired = false;
                    quickReviewState.spaceId = 0;
                    quickReviewReason.value = '';
                    quickReviewReason.required = false;
                    quickReviewError.textContent = '';
                    quickReviewSave.removeAttribute('disabled');
                    quickReviewSave.textContent = 'Закрыть карточку';
                    syncQuickReviewChoiceState();
                    syncQuickReviewHintState('');
                };

                const sendQuickReview = async () => {
                    if (!quickReviewModal || !quickReviewReason || !quickReviewError || !quickReviewSave) {
                        return;
                    }

                    const spaceId = Number(quickReviewState.spaceId || 0);
                    const decision = String(quickReviewState.decision || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !decision) {
                        quickReviewError.textContent = 'Не удалось определить карточку для закрытия.';
                        return;
                    }

                    const reason = String(quickReviewReason.value || '').trim();
                    if (quickReviewState.reasonRequired && !reason) {
                        quickReviewError.textContent = 'Напишите короткий комментарий, почему карточку можно закрыть без изменений.';
                        quickReviewReason.focus();
                        return;
                    }

                    quickReviewSave.setAttribute('disabled', 'disabled');
                    quickReviewSave.textContent = 'Закрываем...';
                    quickReviewError.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision,
                            market_space_id: spaceId,
                            ...(reason ? { reason } : {}),
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        quickReviewSave.removeAttribute('disabled');
                        quickReviewSave.textContent = 'Закрыть карточку';
                        quickReviewError.textContent = String(data?.message || 'Не удалось закрыть карточку.');
                        return;
                    }

                    window.location.reload();
                };

                const openConfirmFreeModal = (button) => {
                    if (!confirmFreeModal || !confirmFreeReason || !confirmFreeError || !confirmFreeSummary || !confirmFreeSave) {
                        return;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || button.closest('[data-mrr-space-id]')?.dataset.mrrSpaceId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        return;
                    }

                    confirmFreeState.spaceId = spaceId;
                    confirmFreeState.spaceLabel = String(button.dataset.mrrSpaceLabel || '').trim();
                    confirmFreeSummary.textContent = confirmFreeState.spaceLabel
                        ? `Место «${confirmFreeState.spaceLabel}» будет переведено в свободные. Договоры, начисления и долги останутся в истории без автоматического переноса.`
                        : 'Место будет переведено в свободные. Договоры, начисления и долги останутся в истории без автоматического переноса.';
                    confirmFreeReason.value = '';
                    confirmFreeReason.required = true;
                    confirmFreeError.textContent = '';
                    confirmFreeSave.removeAttribute('disabled');
                    confirmFreeSave.textContent = 'Подтвердить свободно';

                    confirmFreeModal.hidden = false;
                    confirmFreeModal.classList.add('is-open');
                    confirmFreeModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => confirmFreeReason.focus(), 0);
                };

                const closeConfirmFreeModal = () => {
                    if (!confirmFreeModal || !confirmFreeReason || !confirmFreeError || !confirmFreeSave) {
                        return;
                    }

                    confirmFreeModal.classList.remove('is-open');
                    confirmFreeModal.hidden = true;
                    confirmFreeModal.setAttribute('aria-hidden', 'true');
                    confirmFreeState.spaceId = 0;
                    confirmFreeState.spaceLabel = '';
                    confirmFreeReason.value = '';
                    confirmFreeReason.required = false;
                    confirmFreeError.textContent = '';
                    confirmFreeSave.removeAttribute('disabled');
                    confirmFreeSave.textContent = 'Подтвердить свободно';
                };

                const sendConfirmFree = async () => {
                    if (!confirmFreeModal || !confirmFreeReason || !confirmFreeError || !confirmFreeSave) {
                        return;
                    }

                    const spaceId = Number(confirmFreeState.spaceId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        confirmFreeError.textContent = 'Не удалось определить место для изменения.';
                        return;
                    }

                    const reason = String(confirmFreeReason.value || '').trim();

                    if (!reason) {
                        confirmFreeError.textContent = 'Напишите короткий комментарий, почему место можно подтвердить свободным.';
                        confirmFreeReason.focus();
                        return;
                    }

                    confirmFreeSave.setAttribute('disabled', 'disabled');
                    confirmFreeSave.textContent = 'Сохраняем...';
                    confirmFreeError.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: 'mark_space_free',
                            market_space_id: spaceId,
                            reason,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        confirmFreeSave.removeAttribute('disabled');
                        confirmFreeSave.textContent = 'Подтвердить свободно';
                        confirmFreeError.textContent = String(data?.message || 'Не удалось подтвердить свободное место.');
                        return;
                    }

                    window.location.reload();
                };

                const applyQuickReviewChoice = (button) => {
                    if (!quickReviewModal || !quickReviewTitle || !quickReviewDescription || !quickReviewReason || !quickReviewError || !quickReviewSave) {
                        return;
                    }

                    const decision = String(button.dataset.mrrQuickReviewChoice || '').trim();
                    const label = String(button.textContent || decision).trim();
                    const reasonRequired = String(button.dataset.mrrQuickReasonRequired || '0') === '1';
                    const reasonTitle = String(button.dataset.mrrQuickReasonTitle || label || 'Решение').trim();

                    if (!decision || !Number.isFinite(quickReviewState.spaceId) || quickReviewState.spaceId <= 0) {
                        return;
                    }

                    quickReviewState.decision = decision;
                    quickReviewState.label = label;
                    quickReviewState.reasonRequired = reasonRequired;
                    quickReviewTitle.textContent = reasonTitle || 'Выберите вариант решения';
                    quickReviewDescription.textContent = reasonRequired
                        ? 'Укажите короткий комментарий. Решение будет записано в историю ревизии без изменения данных места.'
                        : 'Решение будет записано в историю ревизии без изменения данных места.';
                    quickReviewReason.required = reasonRequired;
                    quickReviewError.textContent = '';
                    quickReviewSave.removeAttribute('disabled');
                    quickReviewSave.textContent = 'Сохранить';
                    syncQuickReviewChoiceState();
                    syncQuickReviewHintState(decision);

                    if (!reasonRequired) {
                        sendQuickReview().catch((errorInstance) => {
                            quickReviewSave.removeAttribute('disabled');
                            quickReviewSave.textContent = 'Сохранить';
                            quickReviewError.textContent = String(errorInstance?.message || errorInstance);
                        });
                        return;
                    }

                    window.setTimeout(() => quickReviewReason.focus(), 0);
                };

                const openIdentityFixModal = (button) => {
                    if (!identityFixModal || !identityFixNumber || !identityFixDisplayName || !identityFixError || !identityFixSave) {
                        return;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        return;
                    }

                    identityFixState.spaceId = spaceId;
                    identityFixState.originalNumber = String(button.dataset.mrrNumber || '').trim();
                    identityFixState.originalDisplayName = String(button.dataset.mrrDisplayName || '').trim();
                    identityFixNumber.value = identityFixState.originalNumber;
                    identityFixDisplayName.value = identityFixState.originalDisplayName;
                    identityFixError.textContent = '';
                    identityFixSave.removeAttribute('disabled');
                    identityFixSave.textContent = 'Применить';

                    identityFixModal.hidden = false;
                    identityFixModal.classList.add('is-open');
                    identityFixModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => identityFixNumber.focus(), 0);
                };

                const populateContractTenantSwitchState = (button) => {
                    if (!contractTenantSwitchModal || !contractTenantSwitchTenant || !contractTenantSwitchContract || !contractTenantSwitchEffectiveDate || !contractTenantSwitchReason || !contractTenantSwitchError || !contractTenantSwitchSave) {
                        return false;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || 0);
                    const tenantId = Number(button.dataset.mrrTenantId || 0);
                    const contractId = Number(button.dataset.mrrContractId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !Number.isFinite(tenantId) || tenantId <= 0) {
                        return false;
                    }

                    contractTenantSwitchState.spaceId = spaceId;
                    contractTenantSwitchState.tenantId = tenantId;
                    contractTenantSwitchState.contractId = Number.isFinite(contractId) && contractId > 0 ? contractId : 0;
                    contractTenantSwitchState.currentTenantName = String(button.dataset.mrrCurrentTenantName || '').trim();
                    contractTenantSwitchState.tenantName = String(button.dataset.mrrTenantName || '').trim();
                    contractTenantSwitchState.contractNumber = String(button.dataset.mrrContractNumber || '').trim();

                    contractTenantSwitchTenant.value = contractTenantSwitchState.tenantName || `#${tenantId}`;
                    contractTenantSwitchContract.value = contractTenantSwitchState.contractNumber || (contractTenantSwitchState.contractId > 0 ? `#${contractTenantSwitchState.contractId}` : '—');
                    contractTenantSwitchEffectiveDate.value = String(button.dataset.mrrEffectiveDate || '').trim();
                    contractTenantSwitchReason.value = contractTenantSwitchState.contractNumber
                        ? `Подтвердить смену арендатора по договору ${contractTenantSwitchState.contractNumber}.`
                        : 'Подтвердить смену арендатора по договору.';
                    if (contractTenantSwitchCloseContract) {
                        contractTenantSwitchCloseContract.checked = false;
                    }
                    contractTenantSwitchError.textContent = '';
                    contractTenantSwitchSave.removeAttribute('disabled');
                    contractTenantSwitchSave.textContent = 'Запланировать смену';

                    return true;
                };

                const openFinancialTenantResolveModal = (button) => {
                    if (!financialTenantResolveModal || !financialTenantResolveName || !financialTenantResolvePreferredTenant || !financialTenantResolveExternalId || !financialTenantResolveInn || !financialTenantResolveKpp || !financialTenantResolveError || !financialTenantResolveSave) {
                        return;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || 0);
                    const accrualId = Number(button.dataset.mrrAccrualId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !Number.isFinite(accrualId) || accrualId <= 0) {
                        return;
                    }

                    financialTenantResolveState.spaceId = spaceId;
                    financialTenantResolveState.accrualId = accrualId;
                    financialTenantResolveState.tenantName = String(button.dataset.mrrTenantName || '').trim();
                    financialTenantResolveState.tenantExternalId = String(button.dataset.mrrTenantExternalId || '').trim();
                    financialTenantResolveState.tenantInn = String(button.dataset.mrrTenantInn || '').trim();
                    financialTenantResolveState.tenantKpp = String(button.dataset.mrrTenantKpp || '').trim();
                    financialTenantResolveState.preferredTenantId = Number(button.dataset.mrrPreferredTenantId || 0);
                    financialTenantResolveState.preferredTenantName = String(button.dataset.mrrPreferredTenantName || '').trim();
                    financialTenantResolveState.resolutionAction = String(button.dataset.mrrResolutionAction || '').trim();
                    financialTenantResolveState.resolutionLabel = String(button.dataset.mrrResolutionLabel || '').trim();

                    // Fill inputs but do not show placeholder dashes for empty values.
                    financialTenantResolveName.value = financialTenantResolveState.tenantName || '';
                    financialTenantResolvePreferredTenant.value = financialTenantResolveState.preferredTenantName || '';
                    financialTenantResolveExternalId.value = financialTenantResolveState.tenantExternalId || '';
                    financialTenantResolveInn.value = financialTenantResolveState.tenantInn || '';
                    financialTenantResolveKpp.value = financialTenantResolveState.tenantKpp || '';

                    // Hide fields if values are empty (do not show rows with “—”).
                    const preferredTenantField = financialTenantResolvePreferredTenant.closest('.mrr-clarify-modal__field');
                    const externalField = financialTenantResolveExternalId.closest('.mrr-clarify-modal__field');
                    const innField = financialTenantResolveInn.closest('.mrr-clarify-modal__field');
                    const kppField = financialTenantResolveKpp.closest('.mrr-clarify-modal__field');

                    if (preferredTenantField instanceof HTMLElement) {
                        preferredTenantField.style.display = financialTenantResolveState.preferredTenantId > 0 ? '' : 'none';
                    }

                    if (externalField instanceof HTMLElement) {
                        externalField.style.display = financialTenantResolveState.tenantExternalId ? '' : 'none';
                    }

                    if (innField instanceof HTMLElement) {
                        innField.style.display = financialTenantResolveState.tenantInn ? '' : 'none';
                    }

                    if (kppField instanceof HTMLElement) {
                        kppField.style.display = financialTenantResolveState.tenantKpp ? '' : 'none';
                    }

                    financialTenantResolveError.textContent = '';
                    financialTenantResolveSave.removeAttribute('disabled');

                    // Button label depends on resolution_action
                    if (financialTenantResolveState.resolutionAction === 'activate_existing_tenant') {
                        financialTenantResolveSave.textContent = 'Активировать';
                    } else {
                        financialTenantResolveSave.textContent = 'Создать/сопоставить';
                    }

                    financialTenantResolveModal.hidden = false;
                    financialTenantResolveModal.classList.add('is-open');
                    financialTenantResolveModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => financialTenantResolveSave.focus(), 0);
                };

                const closeFinancialTenantResolveModal = () => {
                    if (!financialTenantResolveModal || !financialTenantResolveName || !financialTenantResolvePreferredTenant || !financialTenantResolveExternalId || !financialTenantResolveInn || !financialTenantResolveKpp || !financialTenantResolveError || !financialTenantResolveSave) {
                        return;
                    }

                    financialTenantResolveModal.classList.remove('is-open');
                    financialTenantResolveModal.hidden = true;
                    financialTenantResolveModal.setAttribute('aria-hidden', 'true');
                    financialTenantResolveState.spaceId = 0;
                    financialTenantResolveState.accrualId = 0;
                    financialTenantResolveState.tenantName = '';
                    financialTenantResolveState.tenantExternalId = '';
                    financialTenantResolveState.tenantInn = '';
                    financialTenantResolveState.tenantKpp = '';
                    financialTenantResolveState.preferredTenantId = 0;
                    financialTenantResolveState.preferredTenantName = '';
                    financialTenantResolveState.resolutionAction = '';
                    financialTenantResolveState.resolutionLabel = 'Создать/сопоставить';
                    financialTenantResolveName.value = '';
                    financialTenantResolvePreferredTenant.value = '';
                    financialTenantResolveExternalId.value = '';
                    financialTenantResolveInn.value = '';
                    financialTenantResolveKpp.value = '';
                    financialTenantResolveError.textContent = '';
                    financialTenantResolveSave.removeAttribute('disabled');
                    financialTenantResolveSave.textContent = 'Создать/сопоставить';

                    // restore field visibility
                    try {
                        const preferredTenantField = financialTenantResolvePreferredTenant.closest('.mrr-clarify-modal__field');
                        const externalField = financialTenantResolveExternalId.closest('.mrr-clarify-modal__field');
                        const innField = financialTenantResolveInn.closest('.mrr-clarify-modal__field');
                        const kppField = financialTenantResolveKpp.closest('.mrr-clarify-modal__field');

                        if (preferredTenantField instanceof HTMLElement) preferredTenantField.style.display = '';
                        if (externalField instanceof HTMLElement) externalField.style.display = '';
                        if (innField instanceof HTMLElement) innField.style.display = '';
                        if (kppField instanceof HTMLElement) kppField.style.display = '';
                    } catch (e) {}
                };

                const sendFinancialTenantResolve = async () => {
                    if (!financialTenantResolveModal || !financialTenantResolveError || !financialTenantResolveSave) {
                        return;
                    }

                    const spaceId = Number(financialTenantResolveState.spaceId || 0);
                    const accrualId = Number(financialTenantResolveState.accrualId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !Number.isFinite(accrualId) || accrualId <= 0) {
                        financialTenantResolveError.textContent = 'Не удалось определить финансовый сигнал для восстановления арендатора.';
                        return;
                    }

                    financialTenantResolveSave.setAttribute('disabled', 'disabled');
                    financialTenantResolveSave.textContent = 'Сопоставляем...';
                    financialTenantResolveError.textContent = '';

                    const response = await fetch(reviewResolveFinancialTenantUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            market_space_id: spaceId,
                            accrual_id: accrualId,
                            ...(financialTenantResolveState.preferredTenantId > 0
                                ? { preferred_tenant_id: financialTenantResolveState.preferredTenantId }
                                : {}),
                            tenant_external_id: financialTenantResolveState.tenantExternalId,
                            tenant_name: financialTenantResolveState.tenantName,
                            inn: financialTenantResolveState.tenantInn,
                            kpp: financialTenantResolveState.tenantKpp,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        financialTenantResolveSave.removeAttribute('disabled');
                        if (financialTenantResolveState.resolutionAction === 'activate_existing_tenant') {
                            financialTenantResolveSave.textContent = 'Активировать';
                        } else {
                            financialTenantResolveSave.textContent = 'Создать/сопоставить';
                        }
                        financialTenantResolveError.textContent = String(data?.message || 'Не удалось создать или сопоставить арендатора.');
                        return;
                    }

                    closeFinancialTenantResolveModal();
                    window.location.reload();
                };

                const openManualTenantSwitchModal = (button) => {
                    if (!manualTenantSwitchModal || !manualTenantSwitchCurrentTenant || !manualTenantSwitchTenant || !manualTenantSwitchTenantSearch || !manualTenantSwitchTenantHint || !manualTenantSwitchTenantSuggestions || !manualTenantSwitchEffectiveDate || !manualTenantSwitchReason || !manualTenantSwitchCloseContract || !manualTenantSwitchError || !manualTenantSwitchSave) {
                        return;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        return;
                    }

                    manualTenantSwitchState.spaceId = spaceId;
                    manualTenantSwitchState.currentTenantName = String(button.dataset.mrrCurrentTenantName || '').trim();
                    manualTenantSwitchState.suggestedTenantId = Number(button.dataset.mrrSuggestedTenantId || 0);
                    manualTenantSwitchState.suggestedTenantName = String(button.dataset.mrrSuggestedTenantName || '').trim();

                    manualTenantSwitchCurrentTenant.value = manualTenantSwitchState.currentTenantName || '—';
                    manualTenantSwitchTenant.value = manualTenantSwitchState.suggestedTenantId > 0 ? String(manualTenantSwitchState.suggestedTenantId) : '';
                    manualTenantSwitchTenantSearch.value = manualTenantSwitchState.suggestedTenantName || '';
                    manualTenantSwitchEffectiveDate.value = String(button.dataset.mrrEffectiveDate || '').trim();
                    manualTenantSwitchReason.value = String(button.dataset.mrrReason || '').trim();
                    manualTenantSwitchCloseContract.checked = false;
                    manualTenantSwitchError.textContent = '';
                    manualTenantSwitchSave.removeAttribute('disabled');
                    manualTenantSwitchSave.textContent = 'Запланировать смену';
                    updateManualTenantSwitchSuggestions(manualTenantSwitchState.suggestedTenantName || '', {
                        preserveSelection: true,
                    });

                    manualTenantSwitchModal.hidden = false;
                    manualTenantSwitchModal.classList.add('is-open');
                    manualTenantSwitchModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => manualTenantSwitchTenantSearch.focus(), 0);
                };

                const openContractTenantSwitchModal = (button) => {
                    if (!populateContractTenantSwitchState(button)) {
                        return;
                    }

                    contractTenantSwitchModal.hidden = false;
                    contractTenantSwitchModal.classList.add('is-open');
                    contractTenantSwitchModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => contractTenantSwitchEffectiveDate.focus(), 0);
                };

                const applyContractTenantSwitch = async (button) => {
                    if (!populateContractTenantSwitchState(button)) {
                        return;
                    }

                    const effectiveDate = String(contractTenantSwitchEffectiveDate.value || '').trim();
                    const currentTenantName = contractTenantSwitchState.currentTenantName || 'текущий арендатор';
                    const tenantName = contractTenantSwitchState.tenantName || contractTenantSwitchTenant.value || 'арендатора';
                    const contractNumber = contractTenantSwitchState.contractNumber || contractTenantSwitchContract.value || 'договор';
                    const summaryParts = [
                        'По данным договора произошла смена арендатора.',
                        '',
                        `Было: ${currentTenantName}`,
                        `Станет: ${tenantName}`,
                        effectiveDate ? `С даты: ${effectiveDate}` : 'С даты: нужно указать',
                        `Основание: ${contractNumber}`,
                        '',
                        'Подтвердить смену?',
                    ];

                    if (!window.confirm(summaryParts.join('\n'))) {
                        return;
                    }

                    if (!effectiveDate) {
                        openContractTenantSwitchModal(button);
                        return;
                    }

                    await sendContractTenantSwitch({ throwOnError: true });
                };

                const closeContractTenantSwitchModal = () => {
                    if (!contractTenantSwitchModal || !contractTenantSwitchTenant || !contractTenantSwitchContract || !contractTenantSwitchEffectiveDate || !contractTenantSwitchReason || !contractTenantSwitchError || !contractTenantSwitchSave || !contractTenantSwitchCloseContract) {
                        return;
                    }

                    contractTenantSwitchModal.classList.remove('is-open');
                    contractTenantSwitchModal.hidden = true;
                    contractTenantSwitchModal.setAttribute('aria-hidden', 'true');
                    contractTenantSwitchState.spaceId = 0;
                    contractTenantSwitchState.tenantId = 0;
                    contractTenantSwitchState.contractId = 0;
                    contractTenantSwitchState.currentTenantName = '';
                    contractTenantSwitchState.tenantName = '';
                    contractTenantSwitchState.contractNumber = '';
                    contractTenantSwitchTenant.value = '';
                    contractTenantSwitchContract.value = '';
                    contractTenantSwitchEffectiveDate.value = '';
                    contractTenantSwitchReason.value = '';
                    contractTenantSwitchCloseContract.checked = false;
                    contractTenantSwitchError.textContent = '';
                    contractTenantSwitchSave.removeAttribute('disabled');
                    contractTenantSwitchSave.textContent = 'Запланировать смену';
                };

                const sendContractTenantSwitch = async (options = {}) => {
                    if (!contractTenantSwitchModal || !contractTenantSwitchTenant || !contractTenantSwitchContract || !contractTenantSwitchEffectiveDate || !contractTenantSwitchReason || !contractTenantSwitchError || !contractTenantSwitchSave || !contractTenantSwitchCloseContract) {
                        return;
                    }

                    const spaceId = Number(contractTenantSwitchState.spaceId || 0);
                    const tenantId = Number(contractTenantSwitchState.tenantId || 0);
                    const contractId = Number(contractTenantSwitchState.contractId || 0);
                    const effectiveDate = String(contractTenantSwitchEffectiveDate.value || '').trim();
                    const reason = String(contractTenantSwitchReason.value || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !Number.isFinite(tenantId) || tenantId <= 0) {
                        contractTenantSwitchError.textContent = 'Не удалось определить место или арендатора.';
                        return;
                    }

                    if (!effectiveDate) {
                        contractTenantSwitchError.textContent = 'Укажите дату начала действия.';
                        contractTenantSwitchEffectiveDate.focus();
                        return;
                    }

                    contractTenantSwitchSave.setAttribute('disabled', 'disabled');
                    contractTenantSwitchSave.textContent = 'Планируем...';
                    contractTenantSwitchError.textContent = '';

                    const response = await fetch(reviewContractTenantSwitchUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            market_space_id: spaceId,
                            target_tenant_id: tenantId,
                            ...(contractId > 0 ? { contract_id: contractId } : {}),
                            effective_date: effectiveDate,
                            ...(reason ? { reason } : {}),
                            close_previous_contract: contractTenantSwitchCloseContract.checked,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        contractTenantSwitchSave.removeAttribute('disabled');
                        contractTenantSwitchSave.textContent = 'Запланировать смену';
                        const message = String(data?.message || 'Не удалось запланировать смену арендатора.');
                        contractTenantSwitchError.textContent = message;

                        if (options?.throwOnError) {
                            throw new Error(message);
                        }

                        return;
                    }

                    // Закрываем модалку перед перезагрузкой
                    closeContractTenantSwitchModal();
                    window.location.reload();
                };

                const closeManualTenantSwitchModal = () => {
                    if (!manualTenantSwitchModal || !manualTenantSwitchCurrentTenant || !manualTenantSwitchTenant || !manualTenantSwitchTenantSearch || !manualTenantSwitchTenantHint || !manualTenantSwitchTenantSuggestions || !manualTenantSwitchEffectiveDate || !manualTenantSwitchReason || !manualTenantSwitchCloseContract || !manualTenantSwitchError || !manualTenantSwitchSave) {
                        return;
                    }

                    manualTenantSwitchModal.classList.remove('is-open');
                    manualTenantSwitchModal.hidden = true;
                    manualTenantSwitchModal.setAttribute('aria-hidden', 'true');
                    manualTenantSwitchState.spaceId = 0;
                    manualTenantSwitchState.currentTenantName = '';
                    manualTenantSwitchState.suggestedTenantId = 0;
                    manualTenantSwitchState.suggestedTenantName = '';
                    manualTenantSwitchCurrentTenant.value = '';
                    manualTenantSwitchTenant.value = '';
                    manualTenantSwitchTenantSearch.value = '';
                    manualTenantSwitchEffectiveDate.value = '';
                    manualTenantSwitchReason.value = '';
                    manualTenantSwitchCloseContract.checked = false;
                    manualTenantSwitchError.textContent = '';
                    manualTenantSwitchTenantHint.textContent = 'Начните вводить имя арендатора, чтобы выбрать нужного.';
                    manualTenantSwitchTenantSuggestions.replaceChildren();
                    manualTenantSwitchTenantSuggestions.hidden = true;
                    manualTenantSwitchSave.removeAttribute('disabled');
                    manualTenantSwitchSave.textContent = 'Запланировать смену';
                };

                const sendManualTenantSwitch = async () => {
                    if (!manualTenantSwitchModal || !manualTenantSwitchTenant || !manualTenantSwitchTenantSearch || !manualTenantSwitchEffectiveDate || !manualTenantSwitchReason || !manualTenantSwitchCloseContract || !manualTenantSwitchError || !manualTenantSwitchSave) {
                        return;
                    }

                    const spaceId = Number(manualTenantSwitchState.spaceId || 0);
                    const tenantId = Number(manualTenantSwitchTenant.value || 0);
                    const effectiveDate = String(manualTenantSwitchEffectiveDate.value || '').trim();
                    const reason = String(manualTenantSwitchReason.value || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !Number.isFinite(tenantId) || tenantId <= 0) {
                        manualTenantSwitchError.textContent = 'Выберите нового арендатора.';
                        return;
                    }

                    if (!effectiveDate) {
                        manualTenantSwitchError.textContent = 'Укажите дату начала действия.';
                        manualTenantSwitchEffectiveDate.focus();
                        return;
                    }

                    manualTenantSwitchSave.setAttribute('disabled', 'disabled');
                    manualTenantSwitchSave.textContent = 'Планируем...';
                    manualTenantSwitchError.textContent = '';

                    const response = await fetch(reviewTenantSwitchUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            market_space_id: spaceId,
                            target_tenant_id: tenantId,
                            effective_date: effectiveDate,
                            ...(reason ? { reason } : {}),
                            close_previous_contract: manualTenantSwitchCloseContract.checked,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        manualTenantSwitchSave.removeAttribute('disabled');
                        manualTenantSwitchSave.textContent = 'Запланировать смену';
                        manualTenantSwitchError.textContent = String(data?.message || 'Не удалось запланировать смену арендатора.');
                        return;
                    }

                    closeManualTenantSwitchModal();
                    window.location.reload();
                };

                const selectManualTenantSwitchTenant = (tenantOption) => {
                    if (!manualTenantSwitchTenant || !manualTenantSwitchTenantSearch || !manualTenantSwitchTenantHint || !tenantOption) {
                        return;
                    }

                    manualTenantSwitchTenant.value = String(tenantOption.id);
                    manualTenantSwitchTenantSearch.value = tenantOption.name;
                    manualTenantSwitchTenantHint.textContent = `Выбран арендатор: ${tenantOption.name}`;
                };

                const renderManualTenantSwitchSuggestions = (matchedOptions, hintText) => {
                    if (!manualTenantSwitchTenantSuggestions || !manualTenantSwitchTenantHint) {
                        return;
                    }

                    manualTenantSwitchTenantSuggestions.replaceChildren();
                    manualTenantSwitchTenantSuggestions.hidden = matchedOptions.length === 0;
                    manualTenantSwitchTenantHint.textContent = hintText;

                    matchedOptions.forEach((tenantOption) => {
                        const suggestionButton = document.createElement('button');
                        suggestionButton.type = 'button';
                        suggestionButton.className = 'mrr-manual-tenant-switch__suggestion';
                        suggestionButton.textContent = tenantOption.name;
                        suggestionButton.addEventListener('click', () => {
                            selectManualTenantSwitchTenant(tenantOption);
                            manualTenantSwitchTenantSuggestions.replaceChildren();
                            manualTenantSwitchTenantSuggestions.hidden = true;
                        });
                        manualTenantSwitchTenantSuggestions.appendChild(suggestionButton);
                    });
                };

                const updateManualTenantSwitchSuggestions = (query, options = {}) => {
                    if (!manualTenantSwitchTenant || !manualTenantSwitchTenantHint || !manualTenantSwitchTenantSuggestions) {
                        return;
                    }

                    const normalizedQuery = String(query || '').toLocaleLowerCase('ru-RU').replace(/\s+/g, ' ').trim();

                    if (!options?.preserveSelection) {
                        manualTenantSwitchTenant.value = '';
                    }

                    manualTenantSwitchTenantSuggestions.replaceChildren();
                    manualTenantSwitchTenantSuggestions.hidden = true;

                    if (normalizedQuery === '') {
                        const defaultSuggestions = [];

                        if (manualTenantSwitchState.suggestedTenantId > 0) {
                            const suggestedTenant = normalizedTenantSwitchOptions.find((tenantOption) => tenantOption.id === manualTenantSwitchState.suggestedTenantId);

                            if (suggestedTenant) {
                                defaultSuggestions.push(suggestedTenant);
                            }
                        }

                        normalizedTenantSwitchOptions.slice(0, 5).forEach((tenantOption) => {
                            if (!defaultSuggestions.some((suggestedOption) => suggestedOption.id === tenantOption.id)) {
                                defaultSuggestions.push(tenantOption);
                            }
                        });

                        renderManualTenantSwitchSuggestions(
                            defaultSuggestions.slice(0, 5),
                            manualTenantSwitchState.suggestedTenantName !== ''
                                ? `Подтвердите предполагаемого арендатора или выберите другого: ${manualTenantSwitchState.suggestedTenantName}`
                                : 'Начните вводить имя арендатора или выберите из подсказок ниже.'
                        );

                        return;
                    }

                    const exactMatches = normalizedTenantSwitchOptions.filter((tenantOption) => tenantOption.normalizedName === normalizedQuery);

                    if (exactMatches.length === 1) {
                        selectManualTenantSwitchTenant(exactMatches[0]);
                        if (options?.showSuggestionsOnExactMatch) {
                            renderManualTenantSwitchSuggestions(exactMatches, 'Подтвердите выбранного арендатора.');
                        }
                        return;
                    }

                    const matchedOptions = normalizedTenantSwitchOptions
                        .filter((tenantOption) => tenantOption.normalizedName.includes(normalizedQuery))
                        .slice(0, 5);

                    if (matchedOptions.length === 1) {
                        selectManualTenantSwitchTenant(matchedOptions[0]);
                        if (options?.showSuggestionsOnExactMatch) {
                            renderManualTenantSwitchSuggestions(matchedOptions, 'Подтвердите выбранного арендатора.');
                        }
                        return;
                    }

                    if (matchedOptions.length === 0) {
                        manualTenantSwitchTenantHint.textContent = 'Совпадений не найдено. Уточните имя арендатора.';
                        return;
                    }

                    renderManualTenantSwitchSuggestions(matchedOptions, 'Выберите одного из найденных арендаторов.');
                };

                const closeIdentityFixModal = () => {
                    if (!identityFixModal || !identityFixNumber || !identityFixDisplayName || !identityFixError || !identityFixSave) {
                        return;
                    }

                    identityFixModal.classList.remove('is-open');
                    identityFixModal.hidden = true;
                    identityFixModal.setAttribute('aria-hidden', 'true');
                    identityFixState.spaceId = 0;
                    identityFixState.originalNumber = '';
                    identityFixState.originalDisplayName = '';
                    identityFixNumber.value = '';
                    identityFixDisplayName.value = '';
                    identityFixError.textContent = '';
                    identityFixSave.removeAttribute('disabled');
                    identityFixSave.textContent = 'Применить';
                };

                const sendIdentityFix = async () => {
                    if (!identityFixModal || !identityFixNumber || !identityFixDisplayName || !identityFixError || !identityFixSave) {
                        return;
                    }

                    const spaceId = Number(identityFixState.spaceId || 0);
                    const number = String(identityFixNumber.value || '').trim();
                    const displayName = String(identityFixDisplayName.value || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        identityFixError.textContent = 'Не удалось определить место для изменения.';
                        return;
                    }

                    if (!number && !displayName) {
                        identityFixError.textContent = 'Укажите номер или название места.';
                        identityFixNumber.focus();
                        return;
                    }

                    if (number === identityFixState.originalNumber && displayName === identityFixState.originalDisplayName) {
                        identityFixError.textContent = 'Значения не изменились.';
                        return;
                    }

                    identityFixSave.setAttribute('disabled', 'disabled');
                    identityFixSave.textContent = 'Применяем...';
                    identityFixError.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: 'fix_space_identity',
                            market_space_id: spaceId,
                            ...(number ? { number } : {}),
                            ...(displayName ? { display_name: displayName } : {}),
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        identityFixSave.removeAttribute('disabled');
                        identityFixSave.textContent = 'Применить';
                        identityFixError.textContent = String(data?.message || 'Не удалось применить изменение.');
                        return;
                    }

                    window.location.reload();
                };

                const openMergeRetireModal = (button) => {
                    if (!mergeRetireModal || !mergeRetireCanonicalId || !mergeRetireEffectiveDate || !mergeRetireReason || !mergeRetireError || !mergeRetireSave) {
                        return;
                    }

                    const spaceId = Number(button.dataset.mrrSpaceId || 0);

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        return;
                    }

                    mergeRetireState.spaceId = spaceId;
                    mergeRetireState.spaceLabel = String(button.dataset.mrrSpaceLabel || '').trim();
                    mergeRetireCanonicalId.value = '';
                    mergeRetireEffectiveDate.value = new Date().toISOString().slice(0, 10);
                    mergeRetireReason.value = mergeRetireState.spaceLabel
                        ? `Место ${mergeRetireState.spaceLabel} физически объединено с основным местом.`
                        : 'Место физически объединено с основным местом.';
                    mergeRetireError.textContent = '';
                    mergeRetireSave.removeAttribute('disabled');
                    mergeRetireSave.textContent = 'Упразднить';

                    mergeRetireModal.hidden = false;
                    mergeRetireModal.classList.add('is-open');
                    mergeRetireModal.setAttribute('aria-hidden', 'false');

                    window.setTimeout(() => mergeRetireCanonicalId.focus(), 0);
                };

                const closeMergeRetireModal = () => {
                    if (!mergeRetireModal || !mergeRetireCanonicalId || !mergeRetireEffectiveDate || !mergeRetireReason || !mergeRetireError || !mergeRetireSave) {
                        return;
                    }

                    mergeRetireModal.classList.remove('is-open');
                    mergeRetireModal.hidden = true;
                    mergeRetireModal.setAttribute('aria-hidden', 'true');
                    mergeRetireState.spaceId = 0;
                    mergeRetireState.spaceLabel = '';
                    mergeRetireCanonicalId.value = '';
                    mergeRetireEffectiveDate.value = '';
                    mergeRetireReason.value = '';
                    mergeRetireError.textContent = '';
                    mergeRetireSave.removeAttribute('disabled');
                    mergeRetireSave.textContent = 'Упразднить';
                };

                const sendMergeRetire = async () => {
                    if (!mergeRetireModal || !mergeRetireCanonicalId || !mergeRetireEffectiveDate || !mergeRetireReason || !mergeRetireError || !mergeRetireSave) {
                        return;
                    }

                    const spaceId = Number(mergeRetireState.spaceId || 0);
                    const canonicalSpaceId = Number(mergeRetireCanonicalId.value || 0);
                    const effectiveDate = String(mergeRetireEffectiveDate.value || '').trim();
                    const reason = String(mergeRetireReason.value || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0) {
                        mergeRetireError.textContent = 'Не удалось определить упраздняемое место.';
                        return;
                    }

                    if (!Number.isFinite(canonicalSpaceId) || canonicalSpaceId <= 0 || canonicalSpaceId === spaceId) {
                        mergeRetireError.textContent = 'Укажите ID активного основного места.';
                        mergeRetireCanonicalId.focus();
                        return;
                    }

                    if (!effectiveDate) {
                        mergeRetireError.textContent = 'Укажите дату действия.';
                        mergeRetireEffectiveDate.focus();
                        return;
                    }

                    mergeRetireSave.setAttribute('disabled', 'disabled');
                    mergeRetireSave.textContent = 'Применяем...';
                    mergeRetireError.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: 'merge_space_into_canonical',
                            market_space_id: spaceId,
                            candidate_market_space_id: canonicalSpaceId,
                            effective_date: effectiveDate,
                            ...(reason ? { reason } : {}),
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        mergeRetireSave.removeAttribute('disabled');
                        mergeRetireSave.textContent = 'Упразднить';
                        mergeRetireError.textContent = String(data?.message || 'Не удалось упразднить место.');
                        return;
                    }

                    window.location.reload();
                };

                const createDuplicateReviewOperation = async () => {
                    const currentSpaceId = Number(modal.dataset.currentSpaceId || 0);
                    const candidateSpaceId = Number(modal.dataset.candidateSpaceId || 0);

                    if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0 || !Number.isFinite(candidateSpaceId) || candidateSpaceId <= 0) {
                        error.textContent = 'Не удалось определить пару мест для разбора.';
                        return;
                    }

                    const keepCurrent = duplicatePlanState.selectedPrimary === 'current';
                    const duplicateSpaceId = keepCurrent ? candidateSpaceId : currentSpaceId;
                    const canonicalSpaceId = keepCurrent ? currentSpaceId : candidateSpaceId;

                    createButton.setAttribute('disabled', 'disabled');
                    createButton.textContent = 'Применяем разбор...';
                    error.textContent = '';

                    const response = await fetch(reviewDecisionUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            decision: 'duplicate_space_needs_resolution',
                            market_space_id: duplicateSpaceId,
                            candidate_market_space_id: canonicalSpaceId,
                            reason: keepCurrent
                                ? 'Основным оставлено место из ревизии; безопасные связи перенести со второй карточки.'
                                : 'Основным выбрано второе место того же арендатора; безопасные связи перенести с карточки из ревизии.',
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        createButton.removeAttribute('disabled');
                        createButton.textContent = 'Применить разбор дубля';
                        error.textContent = String(data?.message || 'Не удалось перенести безопасные связи.');
                        return;
                    }

                    window.location.reload();
                };

                document.addEventListener('click', (event) => {
                    const button = event.target instanceof Element
                        ? event.target.closest('[data-mrr-duplicate-plan="open"]')
                        : null;

                    if (!button || !(button instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    openModal(button);
                });

                modal.addEventListener('click', (event) => {
                    if (!(event.target instanceof Element)) {
                        return;
                    }

                    if (event.target.hasAttribute('data-mrr-duplicate-plan-close')) {
                        event.preventDefault();
                        closeModal();
                        return;
                    }

                    const picker = event.target.closest('[data-mrr-duplicate-plan-select]');

                    if (picker instanceof HTMLElement) {
                        event.preventDefault();
                        updateDuplicatePlanSelection(String(picker.dataset.mrrDuplicatePlanSelect || 'candidate'));
                        return;
                    }

                    if (event.target.hasAttribute('data-mrr-duplicate-plan-create')) {
                        event.preventDefault();
                        createDuplicateReviewOperation().catch((errorInstance) => {
                            createButton.removeAttribute('disabled');
                            createButton.textContent = 'Применить разбор дубля';
                            error.textContent = String(errorInstance?.message || errorInstance);
                        });
                    }
                });

                document.addEventListener('click', (event) => {
                    const launcher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-quick-review-launcher]')
                        : null;
                    const aiReviewButton = event.target instanceof Element
                        ? event.target.closest('[data-mrr-ai-load]')
                        : null;

                    const contractTenantSwitchApply = event.target instanceof Element
                        ? event.target.closest('[data-mrr-contract-tenant-switch-apply]')
                        : null;

                    const contractTenantSwitchLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-contract-tenant-switch-open]')
                        : null;
                    const financialTenantResolveLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-financial-tenant-resolve-open]')
                        : null;
                    const manualTenantSwitchLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-manual-tenant-switch-open]')
                        : null;

                    const identityFixLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-identity-fix-open]')
                        : null;

                    const mergeRetireLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-merge-retire-open]')
                        : null;

                    const confirmFreeLauncher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-confirm-free-open]')
                        : null;

                    if (aiReviewButton && aiReviewButton instanceof HTMLElement) {
                        event.preventDefault();
                        loadAiReview(aiReviewButton.dataset.mrrSpaceId, aiReviewButton).catch(() => {});
                        return;
                    }

                    if (contractTenantSwitchApply && contractTenantSwitchApply instanceof HTMLElement) {
                        event.preventDefault();
                        applyContractTenantSwitch(contractTenantSwitchApply).catch((errorInstance) => {
                            if (contractTenantSwitchSave) {
                                contractTenantSwitchSave.removeAttribute('disabled');
                                contractTenantSwitchSave.textContent = 'Запланировать смену';
                            }

                            if (contractTenantSwitchError) {
                                contractTenantSwitchError.textContent = String(errorInstance?.message || errorInstance);
                            }
                        });
                        return;
                    }

                    if (mergeRetireLauncher && mergeRetireLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openMergeRetireModal(mergeRetireLauncher);
                        return;
                    }

                    if (confirmFreeLauncher && confirmFreeLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openConfirmFreeModal(confirmFreeLauncher);
                        return;
                    }

                    if (contractTenantSwitchLauncher && contractTenantSwitchLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openContractTenantSwitchModal(contractTenantSwitchLauncher);
                        return;
                    }

                    if (financialTenantResolveLauncher && financialTenantResolveLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openFinancialTenantResolveModal(financialTenantResolveLauncher);
                        return;
                    }

                    if (manualTenantSwitchLauncher && manualTenantSwitchLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openManualTenantSwitchModal(manualTenantSwitchLauncher);
                        return;
                    }

                    if (identityFixLauncher && identityFixLauncher instanceof HTMLElement) {
                        event.preventDefault();
                        openIdentityFixModal(identityFixLauncher);
                        return;
                    }

                    if (launcher && launcher instanceof HTMLElement) {
                        event.preventDefault();
                        openQuickReviewModal(launcher);
                        return;
                    }

                    const button = event.target instanceof Element
                        ? event.target.closest('[data-mrr-quick-review-choice]')
                        : null;

                    if (!button || !(button instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    applyQuickReviewChoice(button);
                });

                window.addEventListener('keydown', (event) => {
                    const quickOpen = quickReviewModal?.classList.contains('is-open');
                    const confirmFreeOpen = confirmFreeModal?.classList.contains('is-open');
                    const contractTenantSwitchOpen = contractTenantSwitchModal?.classList.contains('is-open');
                    const financialTenantResolveOpen = financialTenantResolveModal?.classList.contains('is-open');
                    const manualTenantSwitchOpen = manualTenantSwitchModal?.classList.contains('is-open');
                    const identityOpen = identityFixModal?.classList.contains('is-open');
                    const mergeRetireOpen = mergeRetireModal?.classList.contains('is-open');
                    if (!modal.classList.contains('is-open') && !quickOpen && !confirmFreeOpen && !contractTenantSwitchOpen && !financialTenantResolveOpen && !manualTenantSwitchOpen && !identityOpen && !mergeRetireOpen) {
                        return;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        if (contractTenantSwitchOpen) {
                            closeContractTenantSwitchModal();
                        }
                        if (financialTenantResolveOpen) {
                            closeFinancialTenantResolveModal();
                        }
                        if (manualTenantSwitchOpen) {
                            closeManualTenantSwitchModal();
                        }
                        if (mergeRetireOpen) {
                            closeMergeRetireModal();
                        }
                        if (identityOpen) {
                            closeIdentityFixModal();
                        }
                        if (quickOpen) {
                            closeQuickReviewModal();
                        }
                        if (confirmFreeOpen) {
                            closeConfirmFreeModal();
                        }
                        if (modal.classList.contains('is-open')) {
                            closeModal();
                        }
                    }
                });

                if (quickReviewModal && quickReviewSave && quickReviewReason) {
                    quickReviewModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-quick-review-close')) {
                            event.preventDefault();
                            closeQuickReviewModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-quick-review-save')) {
                            event.preventDefault();
                            sendQuickReview().catch((errorInstance) => {
                                quickReviewSave.removeAttribute('disabled');
                                quickReviewSave.textContent = 'Сохранить';
                                quickReviewError.textContent = String(errorInstance?.message || errorInstance);
                            });
                        }
                    });
                }

                if (confirmFreeModal && confirmFreeSave && confirmFreeReason) {
                    confirmFreeModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-confirm-free-close')) {
                            event.preventDefault();
                            closeConfirmFreeModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-confirm-free-save')) {
                            event.preventDefault();
                            sendConfirmFree().catch((errorInstance) => {
                                confirmFreeSave.removeAttribute('disabled');
                                confirmFreeSave.textContent = 'Подтвердить свободно';
                                if (confirmFreeError) {
                                    confirmFreeError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                if (identityFixModal && identityFixSave) {
                    identityFixModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-identity-fix-close')) {
                            event.preventDefault();
                            closeIdentityFixModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-identity-fix-save')) {
                            event.preventDefault();
                            sendIdentityFix().catch((errorInstance) => {
                                identityFixSave.removeAttribute('disabled');
                                identityFixSave.textContent = 'Применить';
                                if (identityFixError) {
                                    identityFixError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                if (contractTenantSwitchModal && contractTenantSwitchSave) {
                    contractTenantSwitchModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-contract-tenant-switch-close')) {
                            event.preventDefault();
                            closeContractTenantSwitchModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-contract-tenant-switch-save')) {
                            event.preventDefault();
                            sendContractTenantSwitch().catch((errorInstance) => {
                                contractTenantSwitchSave.removeAttribute('disabled');
                                contractTenantSwitchSave.textContent = 'Запланировать смену';
                                if (contractTenantSwitchError) {
                                    contractTenantSwitchError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                if (financialTenantResolveModal && financialTenantResolveSave) {
                    financialTenantResolveModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-financial-tenant-resolve-close')) {
                            event.preventDefault();
                            closeFinancialTenantResolveModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-financial-tenant-resolve-save')) {
                            event.preventDefault();
                            sendFinancialTenantResolve().catch((errorInstance) => {
                                financialTenantResolveSave.removeAttribute('disabled');
                                if (financialTenantResolveState.resolutionAction === 'activate_existing_tenant') {
                                    financialTenantResolveSave.textContent = 'Активировать';
                                } else {
                                    financialTenantResolveSave.textContent = 'Создать/сопоставить';
                                }
                                if (financialTenantResolveError) {
                                    financialTenantResolveError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                if (manualTenantSwitchModal && manualTenantSwitchSave) {
                    manualTenantSwitchModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-manual-tenant-switch-close')) {
                            event.preventDefault();
                            closeManualTenantSwitchModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-manual-tenant-switch-save')) {
                            event.preventDefault();
                            sendManualTenantSwitch().catch((errorInstance) => {
                                manualTenantSwitchSave.removeAttribute('disabled');
                                manualTenantSwitchSave.textContent = 'Запланировать смену';
                                if (manualTenantSwitchError) {
                                    manualTenantSwitchError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                if (manualTenantSwitchTenantSearch) {
                    manualTenantSwitchTenantSearch.addEventListener('focus', (event) => {
                        const query = event.target instanceof HTMLInputElement ? event.target.value : '';
                        updateManualTenantSwitchSuggestions(query, {
                            preserveSelection: true,
                            showSuggestionsOnExactMatch: true,
                        });
                    });

                    manualTenantSwitchTenantSearch.addEventListener('input', (event) => {
                        const query = event.target instanceof HTMLInputElement ? event.target.value : '';
                        updateManualTenantSwitchSuggestions(query, {
                            showSuggestionsOnExactMatch: true,
                        });
                    });

                    manualTenantSwitchTenantSearch.addEventListener('keydown', (event) => {
                        if (event.key !== 'Enter') {
                            return;
                        }

                        if (Number(manualTenantSwitchTenant?.value || 0) > 0) {
                            event.preventDefault();
                            manualTenantSwitchEffectiveDate?.focus();
                        }
                    });
                }

                if (mergeRetireModal && mergeRetireSave) {
                    mergeRetireModal.addEventListener('click', (event) => {
                        if (!(event.target instanceof Element)) {
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-merge-retire-close')) {
                            event.preventDefault();
                            closeMergeRetireModal();
                            return;
                        }

                        if (event.target.hasAttribute('data-mrr-merge-retire-save')) {
                            event.preventDefault();
                            sendMergeRetire().catch((errorInstance) => {
                                mergeRetireSave.removeAttribute('disabled');
                                mergeRetireSave.textContent = 'Упразднить';
                                if (mergeRetireError) {
                                    mergeRetireError.textContent = String(errorInstance?.message || errorInstance);
                                }
                            });
                        }
                    });
                }

                // --- Фильтры карточек "Нужно уточнить" ---
                const attentionFilterButtons = Array.from(document.querySelectorAll('[data-mrr-attention-filter]'));
                const attentionCards = Array.from(document.querySelectorAll('[data-mrr-attention-card]'));
                const attentionFilterCount = document.querySelector('.mrr-attention-filter-count');
                const attentionSearchInput = document.querySelector('[data-mrr-attention-search]');

                if (attentionFilterButtons.length > 0 && attentionCards.length > 0) {
                    let currentFilter = 'all';
                    let currentSearch = '';

                    const updateFilterCount = () => {
                        const visibleCount = attentionCards.filter(card => !card.classList.contains('is-hidden')).length;
                        const totalCount = attentionCards.length;
                        if (attentionFilterCount) {
                            if (visibleCount === totalCount) {
                                attentionFilterCount.textContent = `${totalCount} карточек`;
                            } else {
                                attentionFilterCount.textContent = `${visibleCount} из ${totalCount}`;
                            }
                        }
                    };

                    const applyFilter = () => {
                        let visibleCount = 0;

                        attentionCards.forEach(card => {
                            const decision = String(card.dataset.mrrDecision || '').trim();
                            const haystack = String(card.dataset.mrrSearch || '').trim();
                            const matchesFilter = currentFilter === 'all' || decision === currentFilter;
                            const matchesSearch = currentSearch === '' || haystack.includes(currentSearch);
                            const shouldShow = matchesFilter && matchesSearch;

                            if (shouldShow) {
                                card.classList.remove('is-hidden');
                                visibleCount++;
                            } else {
                                card.classList.add('is-hidden');
                            }
                        });

                        // Показать сообщение, если нет карточек
                        const needsAttentionPanel = document.querySelector('.aw-panel--muted .mrr-panel-body');
                        if (needsAttentionPanel) {
                            let noResultsMsg = needsAttentionPanel.querySelector('.mrr-attention-no-results');
                            if (!noResultsMsg) {
                                noResultsMsg = document.createElement('div');
                                noResultsMsg.className = 'mrr-attention-no-results';
                                noResultsMsg.textContent = 'Нет карточек по выбранному фильтру';
                                needsAttentionPanel.insertBefore(noResultsMsg, needsAttentionPanel.querySelector('.mrr-needs-list'));
                            }
                            noResultsMsg.hidden = visibleCount > 0;
                        }

                        updateFilterCount();
                    };

                    // Инициализация: по умолчанию "all"
                    applyFilter();

                    // Обработчики кликов
                    attentionFilterButtons.forEach(button => {
                        button.addEventListener('click', () => {
                            currentFilter = String(button.dataset.mrrAttentionFilter || 'all').trim();

                            // Обновить активную кнопку
                            attentionFilterButtons.forEach(btn => btn.classList.remove('is-active'));
                            button.classList.add('is-active');

                            applyFilter();
                        });
                    });

                    if (attentionSearchInput instanceof HTMLInputElement) {
                        attentionSearchInput.addEventListener('input', () => {
                            currentSearch = String(attentionSearchInput.value || '').trim().toLowerCase();
                            applyFilter();
                        });
                    }
                }
            })();
        </script>
    </div>
</div>
</x-filament-panels::page>
