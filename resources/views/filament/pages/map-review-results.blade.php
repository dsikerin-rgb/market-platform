<x-filament-panels::page>
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

            .mrr-place__decision-meta {
                font-size: 0.6875rem;
                color: #94a3b8;
            }

            .dark .mrr-place__decision-meta {
                color: #64748b;
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
                font-size: 0.72rem;
                font-weight: 700;
                color: #475569;
                outline: none;
            }

            .mrr-diagnostics__details > summary::-webkit-details-marker {
                display: none;
            }

            .dark .mrr-diagnostics__details > summary {
                color: #cbd5e1;
            }

            .mrr-diagnostics__details-body {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                margin-top: 0.45rem;
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

            .mrr-duplicate-plan__card-title {
                font-size: 0.75rem;
                font-weight: 800;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #64748b;
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

            /* AI-разбор колонка */
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

            .dark .mrr-ai__step {
                color: #94a3b8;
            }

            .mrr-ai__step strong {
                color: #334155;
            }

            .dark .mrr-ai__step strong {
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
                                Read-only сводка по карте и ревизионным решениям без захода в сырой журнал операций.
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
                                <div class="mrr-table-wrap">
                                    <table class="mrr-table mrr-table--needs {{ $attentionTab === 'unconfirmed_links' ? 'mrr-table--unconfirmed' : '' }}">
                                        <thead>
                                            <tr>
                                                <th>Место</th>
                                                <th>Анализ связей</th>
                                                <th>AI-разбор</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($needsAttention as $row)
                                                @php
                                                    $ai = $aiSummaries[$row['space_id']] ?? null;
                                                @endphp
                                                <tr class="{{ $row['priority_is_high'] ? 'mrr-row--priority' : '' }}">
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">
                                                                {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                            </div>
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                            <div class="mrr-place__statusline">
                                                                <span class="mrr-badge mrr-badge--{{ $row['review_status'] }}">
                                                                    {{ $row['review_status_label'] ?? '—' }}
                                                                </span>
                                                            </div>
                                                            @if ($attentionTab !== 'unconfirmed_links')
                                                                <div class="mrr-place__decision">
                                                                    <div class="mrr-place__decision-label">{{ $row['decision_label'] ?? '—' }}</div>
                                                                    @if (filled($row['reason']))
                                                                        <div class="mrr-place__decision-reason">{{ $row['reason'] }}</div>
                                                                    @endif
                                                                    <div class="mrr-place__decision-meta">
                                                                        {{ $row['reviewed_by_name'] ?: '—' }} · {{ $row['reviewed_at'] ?: '—' }}
                                                                    </div>
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    class="mrr-quick-launcher"
                                                                    data-mrr-quick-review-launcher
                                                                    data-mrr-space-id="{{ $row['space_id'] }}"
                                                                >
                                                                    Быстрое решение
                                                                </button>
                                                            @endif
                                                            <div class="mrr-links">
                                                                <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                                <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        @php
                                                            $diagnostics = is_array($row['diagnostics'] ?? null) ? $row['diagnostics'] : [];
                                                            $relationCounts = is_array($diagnostics['relation_counts'] ?? null) ? $diagnostics['relation_counts'] : [];
                                                            $candidateSpaces = is_array($diagnostics['candidate_spaces'] ?? null) ? $diagnostics['candidate_spaces'] : [];
                                                            $relationAssessment = trim((string) ($diagnostics['relation_assessment'] ?? ''));
                                                            $currentSpaceLabel = trim((string) ($row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id']))));

                                                            if (filled($row['number']) && filled($row['display_name']) && $row['number'] !== $row['display_name']) {
                                                                $currentSpaceLabel = $row['number'] . ' / ' . $row['display_name'];
                                                            }
                                                        @endphp
                                                        <div class="mrr-diagnostics">
                                                            <div class="mrr-diagnostics__section">
                                                                <div class="mrr-diagnostics__section-title">Связи текущего места</div>
                                                                <div class="mrr-diagnostics__summary">
                                                                    @foreach ($relationCounts as $item)
                                                                        <span class="mrr-diagnostics__count {{ ! empty($item['important']) ? 'mrr-diagnostics__count--important' : '' }}">
                                                                            {{ $item['label'] }}: {{ $item['count'] }}
                                                                        </span>
                                                                    @endforeach
                                                                </div>
                                                            </div>

                                                            @if ($relationAssessment !== '')
                                                                <span class="mrr-assessment mrr-assessment--{{ $row['assessment_tone'] ?? 'neutral' }}">
                                                                    {{ $row['assessment_label'] ?? 'Требует проверки' }}
                                                                </span>
                                                                <div class="mrr-diagnostics__assessment">{{ $relationAssessment }}</div>
                                                            @endif

                                                            <details class="mrr-diagnostics__details">
                                                                <summary>Показать подробности связей и кандидатов</summary>
                                                                <div class="mrr-diagnostics__details-body">
                                                                    @if ($candidateSpaces !== [])
                                                                        <div class="mrr-diagnostics__section">
                                                                            <div class="mrr-diagnostics__section-title">Кандидаты того же арендатора</div>
                                                                            <div class="mrr-diagnostics__candidates">
                                                                                @foreach ($candidateSpaces as $candidate)
                                                                                    <div class="mrr-diagnostics__candidate">
                                                                                        <a class="mrr-diagnostics__candidate-main" href="{{ $candidate['space_url'] }}" target="_blank" rel="noopener">
                                                                                            #{{ $candidate['space_id'] }} · {{ $candidate['label'] }}
                                                                                        </a>
                                                                                        <div class="mrr-diagnostics__candidate-meta">
                                                                                            {{ implode(' · ', $candidate['relation_counts'] ?? []) }}
                                                                                        </div>
                                                                                        <div class="mrr-diagnostics__candidate-actions">
                                                                                            <a class="mrr-diagnostics__candidate-action" href="{{ $candidate['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                                                            <a class="mrr-diagnostics__candidate-action" href="{{ $candidate['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                                                            <button
                                                                                                type="button"
                                                                                                class="mrr-diagnostics__candidate-action"
                                                                                                data-mrr-duplicate-plan="open"
                                                                                                data-current-space-id="{{ $row['space_id'] }}"
                                                                                                data-current-label="{{ $currentSpaceLabel }}"
                                                                                                data-current-space-url="{{ $row['space_url'] }}"
                                                                                                data-current-map-url="{{ $row['map_url'] }}"
                                                                                                data-current-counts='@json($relationCounts)'
                                                                                                data-candidate-space-id="{{ $candidate['space_id'] }}"
                                                                                                data-candidate-label="{{ $candidate['label'] }}"
                                                                                                data-candidate-space-url="{{ $candidate['space_url'] }}"
                                                                                                data-candidate-map-url="{{ $candidate['map_url'] }}"
                                                                                                data-candidate-counts='@json($candidate['relation_counts'] ?? [])'
                                                                                            >
                                                                                                {{ ! empty($candidate['is_stronger_than_current']) ? 'Проверить как основное' : 'Сравнить места' }}
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                @endforeach
                                                                            </div>
                                                                        </div>
                                                                    @else
                                                                        <div class="mrr-diagnostics__hint">Других активных мест этого арендатора не найдено.</div>
                                                                    @endif
                                                                </div>
                                                            </details>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        @php
                                                            $hasAiKey = array_key_exists($row['space_id'], $aiSummaries);

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
                                                        @endphp
                                                        <div class="mrr-ai-panel">
                                                            <div class="mrr-ai-panel__title">AI-разбор</div>
                                                            <div class="mrr-ai">
                                                                @if ($ai && filled($ai['summary']))
                                                                    <div class="mrr-ai__summary">
                                                                        <strong>Ситуация:</strong> {{ $humanize($ai['summary']) }}
                                                                    </div>
                                                                    <div class="mrr-ai__reason">
                                                                        <strong>Почему:</strong> {{ $humanize($ai['why_flagged']) }}
                                                                    </div>
                                                                    <div class="mrr-ai__step">
                                                                        <strong>Что сделать:</strong> {{ $humanize($ai['recommended_next_step']) }}
                                                                    </div>
                                                                @elseif ($hasAiKey)
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">AI-анализ недоступен</span>
                                                                    </div>
                                                                @elseif (empty($aiSummaries))
                                                                    <div class="mrr-ai mrr-ai--empty">
                                                                        <span class="mrr-ai__placeholder">AI-сводка временно недоступна</span>
                                                                    </div>
                                                                @else
                                                                    <div class="mrr-ai mrr-ai--skipped">
                                                                        <span class="mrr-ai__placeholder">AI-разбор показан для первых 5 мест</span>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
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
                                <div class="mrr-table-wrap">
                                    <table class="mrr-table">
                                        <thead>
                                            <tr>
                                                <th>Место</th>
                                                <th>Что применено</th>
                                                <th>Детали</th>
                                                <th>Кем и когда</th>
                                                <th>Переходы</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($appliedChanges as $row)
                                                <tr>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">
                                                                {{ $row['number'] ?: ($row['display_name'] ?: ('#' . $row['space_id'])) }}
                                                            </div>
                                                            <div class="mrr-place__meta">
                                                                {{ $row['display_name'] ?: 'Без отображаемого названия' }}
                                                                @if (filled($row['location_name']))
                                                                    · {{ $row['location_name'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['decision_label'] }}</div>
                                                            @if (filled($row['review_status_label']))
                                                                <div class="mrr-place__meta">{{ $row['review_status_label'] }}</div>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td><div class="mrr-applied-summary">{{ $row['summary'] }}</div></td>
                                                    <td>
                                                        <div class="mrr-place">
                                                            <div class="mrr-place__title">{{ $row['created_by_name'] ?: '—' }}</div>
                                                            <div class="mrr-place__meta">{{ $row['effective_at'] ?: '—' }}</div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrr-links">
                                                            <a class="mrr-link" href="{{ $row['map_url'] }}" target="_blank" rel="noopener">Открыть карту</a>
                                                            <a class="mrr-link" href="{{ $row['space_url'] }}" target="_blank" rel="noopener">Открыть место</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
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
                            Выберите основное место. Система перенесёт карту, кабинет и товары на него, а текущий дубль выведет из рабочего контура. Договоры, начисления и долги не переносятся.
                        </p>
                        <div id="mrrDuplicatePlanError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-duplicate-plan__grid">
                            <div class="mrr-duplicate-plan__card">
                                <div class="mrr-duplicate-plan__card-title">Текущее место из ревизии</div>
                                <div id="mrrDuplicatePlanCurrentTitle" class="mrr-duplicate-plan__space">—</div>
                                <div id="mrrDuplicatePlanCurrentCounts" class="mrr-duplicate-plan__counts"></div>
                                <div class="mrr-duplicate-plan__links">
                                    <a id="mrrDuplicatePlanCurrentSpaceLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть место</a>
                                    <a id="mrrDuplicatePlanCurrentMapLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть карту</a>
                                </div>
                            </div>

                            <div class="mrr-duplicate-plan__card">
                                <div class="mrr-duplicate-plan__card-title">Возможное каноническое место</div>
                                <div id="mrrDuplicatePlanCandidateTitle" class="mrr-duplicate-plan__space">—</div>
                                <div id="mrrDuplicatePlanCandidateCounts" class="mrr-duplicate-plan__counts"></div>
                                <div class="mrr-duplicate-plan__links">
                                    <a id="mrrDuplicatePlanCandidateSpaceLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть место</a>
                                    <a id="mrrDuplicatePlanCandidateMapLink" class="mrr-duplicate-plan__link" href="#" target="_blank" rel="noopener">Открыть карту</a>
                                </div>
                            </div>
                        </div>

                        <div class="mrr-duplicate-plan__section">
                            <h4>Что произойдёт после выбора</h4>
                            <ul class="mrr-duplicate-plan__list">
                                <li>Кандидат станет основным местом для карты, кабинета и товаров.</li>
                                <li>Текущее место будет выведено из рабочего контура через is_active = false.</li>
                                <li>Договоры, начисления и долги не меняются. Если они есть на текущем дубле, действие будет заблокировано.</li>
                            </ul>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-duplicate-plan-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-duplicate-plan-create>Выбрать кандидата основным</button>
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
                        <div class="mrr-clarify-modal__eyebrow">Быстрое решение</div>
                        <h3 id="mrrQuickReviewTitle" class="mrr-clarify-modal__title">Выберите вариант решения</h3>
                        <p id="mrrQuickReviewDescription" class="mrr-clarify-modal__description">
                            Выберите ручное решение для истории ревизии. Оно не меняет данные места. Если у места нет фигуры на карте, это отдельный контекст, а не автоматическое решение.
                        </p>
                        <div id="mrrQuickReviewError" class="mrr-clarify-modal__error" aria-live="polite"></div>

                        <div class="mrr-clarify-modal__field">
                            <div class="mrr-quick-review__choices" role="group" aria-label="Выбор варианта решения">
                                <button type="button" class="mrr-quick-review__choice" data-mrr-quick-review-choice="matched" data-mrr-quick-reason-required="0">Совпало</button>
                                <button type="button" class="mrr-quick-review__choice mrr-quick-review__choice--danger" data-mrr-quick-review-choice="occupancy_conflict" data-mrr-quick-reason-required="1" data-mrr-quick-reason-title="Конфликт по занятости">Конфликт по занятости</button>
                                <button type="button" class="mrr-quick-review__choice mrr-quick-review__choice--danger" data-mrr-quick-review-choice="shape_not_found" data-mrr-quick-reason-required="1" data-mrr-quick-reason-title="Фигура не найдена на карте">Фигура не найдена на карте</button>
                                <button type="button" class="mrr-quick-review__choice" data-mrr-quick-review-choice="space_identity_needs_clarification" data-mrr-quick-reason-required="0">Требует уточнения</button>
                            </div>
                        </div>

                        <div class="mrr-clarify-modal__field">
                            <label class="mrr-clarify-modal__label" for="mrrQuickReviewReason">Комментарий к решению</label>
                            <textarea
                                id="mrrQuickReviewReason"
                                class="mrr-clarify-modal__input mrr-quick-review__field"
                                rows="4"
                                placeholder="Коротко опишите, что увидели"
                            ></textarea>
                            <div class="mrr-quick-review__hint">Нужен для спорных решений. Сам по себе не меняет данные места.</div>
                        </div>

                        <div class="mrr-clarify-modal__actions">
                            <button type="button" class="mrr-clarify-modal__button" data-mrr-quick-review-close>Отмена</button>
                            <button type="button" class="mrr-clarify-modal__button mrr-clarify-modal__button--primary" data-mrr-quick-review-save>Сохранить</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <script>
            (() => {
                const reviewDecisionUrl = @json(route('filament.admin.market-map.review-decision'));
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const quickReviewModal = document.getElementById('mrrQuickReviewModal');
                const quickReviewTitle = document.getElementById('mrrQuickReviewTitle');
                const quickReviewDescription = document.getElementById('mrrQuickReviewDescription');
                const quickReviewReason = document.getElementById('mrrQuickReviewReason');
                const quickReviewError = document.getElementById('mrrQuickReviewError');
                const quickReviewSave = quickReviewModal?.querySelector('[data-mrr-quick-review-save]');
                const quickReviewChoiceButtons = Array.from(document.querySelectorAll('[data-mrr-quick-review-choice]'));
                const modal = document.getElementById('mrrDuplicatePlanModal');
                const currentTitle = document.getElementById('mrrDuplicatePlanCurrentTitle');
                const candidateTitle = document.getElementById('mrrDuplicatePlanCandidateTitle');
                const currentCounts = document.getElementById('mrrDuplicatePlanCurrentCounts');
                const candidateCounts = document.getElementById('mrrDuplicatePlanCandidateCounts');
                const currentSpaceLink = document.getElementById('mrrDuplicatePlanCurrentSpaceLink');
                const currentMapLink = document.getElementById('mrrDuplicatePlanCurrentMapLink');
                const candidateSpaceLink = document.getElementById('mrrDuplicatePlanCandidateSpaceLink');
                const candidateMapLink = document.getElementById('mrrDuplicatePlanCandidateMapLink');
                const createButton = modal?.querySelector('[data-mrr-duplicate-plan-create]');
                const error = document.getElementById('mrrDuplicatePlanError');
                const quickReviewState = {
                    decision: '',
                    label: '',
                    reasonRequired: false,
                    spaceId: 0,
                };

                if (
                    (!modal
                    || !currentTitle
                    || !candidateTitle
                    || !currentCounts
                    || !candidateCounts
                    || !currentSpaceLink
                    || !currentMapLink
                    || !candidateSpaceLink
                    || !candidateMapLink
                    || !createButton
                    || !error)
                    && !quickReviewModal
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

                const setLink = (link, href) => {
                    const url = String(href || '').trim();

                    if (!url) {
                        link.removeAttribute('href');
                        link.setAttribute('aria-disabled', 'true');
                        return;
                    }

                    link.href = url;
                    link.removeAttribute('aria-disabled');
                };

                const openModal = (button) => {
                    const currentLabel = String(button.dataset.currentLabel || '').trim();
                    const candidateLabel = String(button.dataset.candidateLabel || '').trim();
                    const currentSpaceId = String(button.dataset.currentSpaceId || '').trim();
                    const candidateSpaceId = String(button.dataset.candidateSpaceId || '').trim();

                    currentTitle.textContent = currentLabel
                        ? `#${currentSpaceId} · ${currentLabel}`
                        : `#${currentSpaceId}`;
                    candidateTitle.textContent = candidateLabel
                        ? `#${candidateSpaceId} · ${candidateLabel}`
                        : `#${candidateSpaceId}`;

                    renderCounts(currentCounts, parseJson(button.dataset.currentCounts, []));
                    renderCounts(candidateCounts, parseJson(button.dataset.candidateCounts, []));
                    setLink(currentSpaceLink, button.dataset.currentSpaceUrl);
                    setLink(currentMapLink, button.dataset.currentMapUrl);
                    setLink(candidateSpaceLink, button.dataset.candidateSpaceUrl);
                    setLink(candidateMapLink, button.dataset.candidateMapUrl);
                    error.textContent = '';
                    modal.dataset.currentSpaceId = currentSpaceId;
                    modal.dataset.candidateSpaceId = candidateSpaceId;

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
                    createButton.textContent = 'Выбрать кандидата основным';
                };

                const syncQuickReviewChoiceState = () => {
                    quickReviewChoiceButtons.forEach((choiceButton) => {
                        const isSelected = String(choiceButton.dataset.mrrQuickReviewChoice || '') === quickReviewState.decision;
                        choiceButton.classList.toggle('is-selected', isSelected);
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
                    quickReviewState.decision = '';
                    quickReviewState.label = '';
                    quickReviewState.reasonRequired = false;

                    quickReviewTitle.textContent = 'Выберите вариант решения';
                    quickReviewDescription.textContent = 'Выберите ручное решение для истории ревизии. Оно не меняет данные места. Если у места нет фигуры на карте, это отдельный контекст, а не автоматическое решение.';
                    quickReviewReason.value = '';
                    quickReviewReason.required = false;
                    quickReviewError.textContent = '';
                    quickReviewSave.removeAttribute('disabled');
                    quickReviewSave.textContent = 'Сохранить';
                    syncQuickReviewChoiceState();

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
                    quickReviewSave.textContent = 'Сохранить';
                    syncQuickReviewChoiceState();
                };

                const sendQuickReview = async () => {
                    if (!quickReviewModal || !quickReviewReason || !quickReviewError || !quickReviewSave) {
                        return;
                    }

                    const spaceId = Number(quickReviewState.spaceId || 0);
                    const decision = String(quickReviewState.decision || '').trim();

                    if (!Number.isFinite(spaceId) || spaceId <= 0 || !decision) {
                        quickReviewError.textContent = 'Не удалось определить решение или место для сохранения.';
                        return;
                    }

                    const reason = String(quickReviewReason.value || '').trim();
                    if (quickReviewState.reasonRequired && !reason) {
                        quickReviewError.textContent = 'Для этого решения нужен комментарий / reason.';
                        quickReviewReason.focus();
                        return;
                    }

                    quickReviewSave.setAttribute('disabled', 'disabled');
                    quickReviewSave.textContent = 'Сохраняем...';
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
                        quickReviewSave.textContent = 'Сохранить';
                        quickReviewError.textContent = String(data?.message || 'Не удалось сохранить решение.');
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

                const createDuplicateReviewOperation = async () => {
                    const currentSpaceId = Number(modal.dataset.currentSpaceId || 0);
                    const candidateSpaceId = Number(modal.dataset.candidateSpaceId || 0);

                    if (!Number.isFinite(currentSpaceId) || currentSpaceId <= 0 || !Number.isFinite(candidateSpaceId) || candidateSpaceId <= 0) {
                        error.textContent = 'Не удалось определить пару мест для разбора.';
                        return;
                    }

                    createButton.setAttribute('disabled', 'disabled');
                    createButton.textContent = 'Переносим связи...';
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
                            market_space_id: currentSpaceId,
                            candidate_market_space_id: candidateSpaceId,
                            reason: 'Выбрано основное место дубля; перенести безопасные связи.',
                        }),
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        createButton.removeAttribute('disabled');
                        createButton.textContent = 'Выбрать кандидата основным';
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

                    if (event.target.hasAttribute('data-mrr-duplicate-plan-create')) {
                        event.preventDefault();
                        createDuplicateReviewOperation().catch((errorInstance) => {
                            createButton.removeAttribute('disabled');
                            createButton.textContent = 'Выбрать кандидата основным';
                            error.textContent = String(errorInstance?.message || errorInstance);
                        });
                    }
                });

                document.addEventListener('click', (event) => {
                    const launcher = event.target instanceof Element
                        ? event.target.closest('[data-mrr-quick-review-launcher]')
                        : null;

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
                    if (!modal.classList.contains('is-open') && !quickOpen) {
                        return;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        if (quickOpen) {
                            closeQuickReviewModal();
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
            })();
        </script>
    </div>
</x-filament-panels::page>
