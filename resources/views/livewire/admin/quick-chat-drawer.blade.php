@php
    $selectedKey = $selectedChat ? $selectedChat['type'] . ':' . $selectedChat['id'] : null;
@endphp

<div
    class="quick-chat"
    @if ($isOpen) wire:poll.15s @endif
    x-data="{
        pageContext() {
            const heading = document.querySelector('main h1, main h2, h1, h2');

            return {
                url: window.location.href,
                path: window.location.pathname + window.location.search,
                title: document.title || '',
                heading: heading ? heading.textContent.trim() : '',
            };
        },
        syncPageContext() {
            $wire.updatePageContext(this.pageContext());
        },
        openFromEvent(event) {
            const detail = event.detail || {};
            const context = this.pageContext();

            $wire.updatePageContext(context);
            $wire.openDrawer(
                detail.type || null,
                Number(detail.id || 0) || null,
                detail.source || null,
                context,
            );
        },
    }"
    x-init="syncPageContext()"
    x-on:mp-open-quick-chat.window="openFromEvent($event)"
    x-on:quick-chat-ai-reply-queued.window="setTimeout(() => $wire.completeAiReply(), Number($event.detail?.delay || 950))"
    x-on:keydown.escape.window="document.documentElement.classList.remove('quick-chat-open'); $wire.closeDrawer()"
>
    <style>
        html.quick-chat-open,
        html.quick-chat-open body {
            overflow: hidden;
        }

        .quick-chat {
            pointer-events: none;
            position: relative;
            z-index: 95;
        }

        .quick-chat__launcher {
            pointer-events: auto;
            position: fixed;
            right: 6.45rem;
            bottom: 1.25rem;
            z-index: 95;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 2.75rem;
            border: 1px solid rgba(14, 165, 233, 0.35);
            border-radius: 999px;
            background: #e0f2fe;
            padding: 0.65rem 0.95rem;
            color: #075985;
            font-size: 0.86rem;
            font-weight: 800;
            box-shadow: 0 18px 36px rgba(14, 116, 144, 0.18);
            cursor: pointer;
        }

        .quick-chat__launcher:hover,
        .quick-chat__launcher:focus-visible {
            background: #bae6fd;
            outline: none;
        }

        html.ai-help-nudge-visible .quick-chat__launcher {
            pointer-events: none;
            transform: translateY(0.35rem) scale(0.96);
            opacity: 0;
        }

        body:has(#database-notifications.fi-modal-open) .quick-chat__launcher,
        body:has(#database-notifications.fi-modal-open) .staff-presence {
            display: none !important;
        }

        .quick-chat__badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.2rem;
            height: 1.2rem;
            border-radius: 999px;
            background: #0284c7;
            color: #fff;
            font-size: 0.68rem;
            font-weight: 900;
            line-height: 1;
        }

        .quick-chat__launcher .quick-chat__badge {
            box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.38);
            animation: quick-chat-badge-pulse 1.8s ease-out infinite;
        }

        @keyframes quick-chat-badge-pulse {
            70% {
                box-shadow: 0 0 0 0.55rem rgba(2, 132, 199, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(2, 132, 199, 0);
            }
        }

        .quick-chat__backdrop {
            pointer-events: auto;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(15, 23, 42, 0.32);
            backdrop-filter: blur(4px);
            animation: quick-chat-backdrop-in 180ms ease-out both;
            will-change: opacity;
        }

        .quick-chat__drawer {
            pointer-events: auto;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 101;
            display: grid;
            grid-template-rows: auto 1fr;
            width: min(100vw, 58rem);
            height: 100dvh;
            overflow: hidden;
            background: #f8fafc;
            box-shadow: -24px 0 70px rgba(15, 23, 42, 0.24);
            animation: quick-chat-drawer-in 260ms cubic-bezier(0.22, 1, 0.36, 1) both;
            transform: translate3d(0, 0, 0);
            will-change: transform, opacity;
        }

        @keyframes quick-chat-backdrop-in {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes quick-chat-drawer-in {
            from {
                opacity: 0.78;
                transform: translate3d(2rem, 0, 0);
            }

            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }

        .quick-chat__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 0.9rem 1rem;
        }

        .quick-chat__title {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .quick-chat__subtitle {
            margin-top: 0.15rem;
            color: #64748b;
            font-size: 0.8rem;
            line-height: 1.35;
        }

        .quick-chat__close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 999px;
            background: #fff;
            color: #475569;
            cursor: pointer;
        }

        .quick-chat__layout {
            display: grid;
            min-height: 0;
            min-width: 0;
            grid-template-columns: minmax(16rem, 20rem) minmax(0, 1fr);
        }

        .quick-chat__list {
            min-height: 0;
            min-width: 0;
            overflow: hidden;
            border-right: 1px solid rgba(148, 163, 184, 0.22);
            background: #fff;
        }

        .quick-chat__search-wrap {
            padding: 0.8rem;
        }

        .quick-chat__search {
            width: 100%;
            min-height: 2.45rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 999px;
            background: #f8fafc;
            padding: 0 0.9rem;
            color: #0f172a;
            font-size: 0.88rem;
            outline: none;
        }

        .quick-chat__search:focus {
            border-color: rgba(14, 165, 233, 0.65);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
        }

        .quick-chat__items {
            display: grid;
            max-height: calc(100vh - 7rem);
            min-width: 0;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 0.2rem 0.45rem 0.85rem;
        }

        .quick-chat__item {
            display: grid;
            grid-template-columns: 2.65rem minmax(0, 1fr);
            gap: 0.58rem;
            width: 100%;
            min-width: 0;
            border: 0;
            border-radius: 0.78rem;
            background: transparent;
            padding: 0.58rem;
            overflow: hidden;
            text-align: left;
            cursor: pointer;
            touch-action: manipulation;
        }

        .quick-chat__item > span:last-child {
            min-width: 0;
            overflow: hidden;
        }

        .quick-chat__item:hover {
            background: #f1f5f9;
        }

        .quick-chat__item--selected {
            background: #e0f2fe;
        }

        .quick-chat__item--candidate {
            outline: 1px dashed rgba(14, 165, 233, 0.36);
            outline-offset: -2px;
            background: rgba(240, 249, 255, 0.72);
        }

        .quick-chat__item--candidate.quick-chat__item--selected {
            background: #e0f2fe;
        }

        .quick-chat__avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.65rem;
            height: 2.65rem;
            border-radius: 999px;
            background: #e2e8f0;
            color: #334155;
        }

        .quick-chat__avatar--ticket {
            background: #dcfce7;
            color: #166534;
        }

        .quick-chat__avatar--staff {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .quick-chat__avatar--ai {
            background: #ffffff;
            color: #123fe6;
        }

        .quick-chat__giga-logo {
            display: block;
            width: 100%;
            height: 100%;
            max-width: none;
            object-fit: contain;
        }

        .quick-chat__item-title {
            color: #0f172a;
            font-size: 0.88rem;
            font-weight: 850;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__item-meta {
            display: flex;
            gap: 0.35rem;
            align-items: center;
            margin-top: 0.15rem;
            color: #64748b;
            font-size: 0.75rem;
            line-height: 1.25;
            min-width: 0;
            overflow: hidden;
        }

        .quick-chat__item-meta > span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__item-meta > span:nth-child(3) {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .quick-chat__item-preview {
            margin-top: 0.25rem;
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.3;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__count {
            margin-left: auto;
            flex: 0 0 auto;
            border-radius: 999px;
            background: #e0f2fe;
            padding: 0.12rem 0.45rem;
            color: #0369a1;
            font-size: 0.68rem;
            font-weight: 850;
        }

        .quick-chat__thread {
            display: grid;
            min-height: 0;
            grid-template-rows: auto 1fr auto;
            background:
                linear-gradient(135deg, rgba(14, 165, 233, 0.10), rgba(34, 197, 94, 0.10)),
                radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.82) 0 0.18rem, transparent 0.2rem);
            background-size: auto, 2rem 2rem;
        }

        .quick-chat__chat-head {
            border-bottom: 1px solid rgba(148, 163, 184, 0.20);
            background: rgba(255, 255, 255, 0.86);
            padding: 0.85rem 1rem;
        }

        .quick-chat__chat-title {
            color: #0f172a;
            font-size: 1rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .quick-chat__chat-meta {
            margin-top: 0.25rem;
            color: #475569;
            font-size: 0.78rem;
            line-height: 1.35;
        }

        .quick-chat__chat-description {
            margin-top: 0.45rem;
            color: #334155;
            font-size: 0.84rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .quick-chat__messages {
            min-height: 0;
            overflow: auto;
            padding: 1rem;
        }

        .quick-chat__date {
            display: flex;
            justify-content: center;
            margin: 0.35rem 0 0.75rem;
        }

        .quick-chat__date span {
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            padding: 0.18rem 0.65rem;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
        }

        .quick-chat__bubble-row {
            display: flex;
            margin-bottom: 0.55rem;
        }

        .quick-chat__bubble-row--own {
            justify-content: flex-end;
        }

        .quick-chat__bubble {
            max-width: min(34rem, 76%);
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 0.75rem 0.75rem 0.75rem 0.25rem;
            background: #fff;
            padding: 0.54rem 0.66rem;
            color: #0f172a;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        .quick-chat__bubble--own {
            border-color: rgba(34, 197, 94, 0.22);
            border-radius: 0.75rem 0.75rem 0.25rem 0.75rem;
            background: #dcfce7;
        }

        .quick-chat__bubble-meta {
            display: flex;
            gap: 0.45rem;
            align-items: center;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 750;
            line-height: 1.2;
        }

        .quick-chat__bubble-text {
            margin-top: 0.28rem;
            font-size: 0.92rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .quick-chat__typing {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-top: 0.2rem;
            color: #64748b;
            font-size: 0.84rem;
            font-weight: 750;
        }

        .quick-chat__typing-dot {
            width: 0.34rem;
            height: 0.34rem;
            border-radius: 999px;
            background: currentColor;
            opacity: 0.38;
            animation: quick-chat-typing 1.05s ease-in-out infinite;
        }

        .quick-chat__typing-dot:nth-child(2) {
            animation-delay: 140ms;
        }

        .quick-chat__typing-dot:nth-child(3) {
            animation-delay: 280ms;
        }

        @keyframes quick-chat-typing {
            0%,
            80%,
            100% {
                transform: translateY(0);
                opacity: 0.35;
            }

            40% {
                transform: translateY(-0.18rem);
                opacity: 0.82;
            }
        }

        .quick-chat__chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.55rem;
        }

        .quick-chat__chip {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border: 1px solid rgba(14, 165, 233, 0.24);
            border-radius: 999px;
            background: rgba(224, 242, 254, 0.86);
            padding: 0.28rem 0.62rem;
            color: #075985;
            font-size: 0.78rem;
            font-weight: 850;
            line-height: 1.2;
            text-decoration: none;
        }

        .quick-chat__chip:hover,
        .quick-chat__chip:focus-visible {
            border-color: rgba(14, 116, 144, 0.42);
            background: #bae6fd;
            outline: none;
        }

        .quick-chat__text-link {
            color: #0369a1;
            font-weight: 750;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .quick-chat__text-link:hover,
        .quick-chat__text-link:focus-visible {
            color: #075985;
        }

        .quick-chat__suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.55rem;
        }

        .quick-chat__suggestion {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border: 1px solid rgba(34, 197, 94, 0.24);
            border-radius: 999px;
            background: rgba(240, 253, 244, 0.92);
            padding: 0.28rem 0.62rem;
            color: #166534;
            font-size: 0.78rem;
            font-weight: 850;
            line-height: 1.2;
            cursor: pointer;
        }

        .quick-chat__suggestion:hover,
        .quick-chat__suggestion:focus-visible {
            border-color: rgba(22, 101, 52, 0.36);
            background: #dcfce7;
            outline: none;
        }

        .quick-chat__action-card {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.65rem;
            border: 1px solid rgba(14, 165, 233, 0.22);
            border-radius: 0.75rem;
            background: rgba(240, 249, 255, 0.82);
            padding: 0.72rem;
        }

        .quick-chat__action-card--confirmed {
            border-color: rgba(34, 197, 94, 0.26);
            background: rgba(240, 253, 244, 0.84);
        }

        .quick-chat__action-card--cancelled {
            border-color: rgba(148, 163, 184, 0.32);
            background: rgba(248, 250, 252, 0.86);
        }

        .quick-chat__action-card--failed {
            border-color: rgba(248, 113, 113, 0.34);
            background: rgba(254, 242, 242, 0.86);
        }

        .quick-chat__action-head {
            display: flex;
            gap: 0.5rem;
            align-items: flex-start;
            justify-content: space-between;
        }

        .quick-chat__action-title {
            color: #0f172a;
            font-size: 0.84rem;
            font-weight: 850;
            line-height: 1.25;
        }

        .quick-chat__action-status {
            flex: 0 0 auto;
            border-radius: 999px;
            background: rgba(14, 165, 233, 0.14);
            padding: 0.18rem 0.48rem;
            color: #0369a1;
            font-size: 0.68rem;
            font-weight: 850;
            line-height: 1.15;
        }

        .quick-chat__action-card--confirmed .quick-chat__action-status {
            background: rgba(34, 197, 94, 0.14);
            color: #15803d;
        }

        .quick-chat__action-card--cancelled .quick-chat__action-status {
            background: rgba(148, 163, 184, 0.18);
            color: #475569;
        }

        .quick-chat__action-card--failed .quick-chat__action-status {
            background: rgba(248, 113, 113, 0.16);
            color: #b91c1c;
        }

        .quick-chat__action-summary {
            display: grid;
            gap: 0.4rem;
        }

        .quick-chat__action-row {
            display: grid;
            gap: 0.1rem;
            min-width: 0;
        }

        .quick-chat__action-label {
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .quick-chat__action-value {
            color: #1e293b;
            font-size: 0.8rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .quick-chat__action-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .quick-chat__action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            border-radius: 999px;
            padding: 0.34rem 0.68rem;
            font-size: 0.76rem;
            font-weight: 850;
            line-height: 1.15;
            cursor: pointer;
        }

        .quick-chat__action-button:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .quick-chat__action-button--primary {
            background: #0ea5e9;
            color: #ffffff;
        }

        .quick-chat__action-button--primary:hover,
        .quick-chat__action-button--primary:focus-visible {
            background: #0284c7;
            outline: none;
        }

        .quick-chat__action-button--secondary {
            border-color: rgba(100, 116, 139, 0.24);
            background: rgba(255, 255, 255, 0.8);
            color: #475569;
        }

        .quick-chat__action-button--secondary:hover,
        .quick-chat__action-button--secondary:focus-visible {
            border-color: rgba(100, 116, 139, 0.42);
            background: #ffffff;
            outline: none;
        }

        .quick-chat__action-result {
            color: #475569;
            font-size: 0.76rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .quick-chat__attachments {
            display: grid;
            gap: 0.45rem;
            margin-top: 0.55rem;
        }

        .quick-chat__attachment {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
            border: 1px solid rgba(14, 165, 233, 0.18);
            border-radius: 0.72rem;
            background: rgba(255, 255, 255, 0.68);
            padding: 0.45rem;
            color: inherit;
            text-decoration: none;
        }

        .quick-chat__attachment-thumb {
            width: 3.4rem;
            height: 2.5rem;
            border-radius: 0.55rem;
            object-fit: cover;
            background: #e2e8f0;
            flex-shrink: 0;
        }

        .quick-chat__attachment-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.62rem;
            background: rgba(14, 165, 233, 0.12);
            color: #0369a1;
            flex-shrink: 0;
        }

        .quick-chat__attachment-name {
            display: block;
            min-width: 0;
            overflow: hidden;
            color: #0f172a;
            font-size: 0.82rem;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__attachment-meta {
            display: block;
            margin-top: 0.1rem;
            color: #64748b;
            font-size: 0.72rem;
            line-height: 1.25;
        }

        .quick-chat__composer {
            border-top: 1px solid rgba(148, 163, 184, 0.22);
            background: #fff;
            padding: 0;
        }

        .quick-chat__composer-row {
            display: flex;
            align-items: flex-end;
            gap: 0.35rem;
            min-height: 4.35rem;
            padding: 0.62rem 0.72rem;
        }

        .quick-chat__composer-main {
            flex: 1;
            min-width: 0;
        }

        .quick-chat__input-shell {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
        }

        .quick-chat__textarea {
            width: 100%;
            min-height: 2.35rem;
            resize: none;
            overflow: hidden;
            border: 0;
            background: transparent;
            padding: 0.35rem 0;
            color: #0f172a;
            font-size: 0.95rem;
            line-height: 1.3;
            outline: none;
        }

        .quick-chat__textarea:focus {
            box-shadow: none;
        }

        .quick-chat__selected-files {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.45rem;
        }

        .quick-chat__selected-file {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            max-width: 12rem;
            border-radius: 999px;
            background: rgba(14, 165, 233, 0.12);
            padding: 0.25rem 0.5rem;
            color: #075985;
            font-size: 0.74rem;
            font-weight: 750;
        }

        .quick-chat__selected-file span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quick-chat__composer-tools {
            display: none;
        }

        .quick-chat__icon-spacer,
        .quick-chat__file-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border: 0;
            border-radius: 999px;
            background: transparent;
            padding: 0;
            color: #8a94a6;
            font-size: 0.92rem;
            cursor: pointer;
        }

        .quick-chat__icon-spacer {
            visibility: hidden;
            cursor: default;
        }

        .quick-chat__file-label:hover,
        .quick-chat__file-label:focus-within {
            background: rgba(14, 165, 233, 0.08);
            color: #075985;
            outline: none;
        }

        .quick-chat__file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
        }

        .quick-chat__uploading {
            color: #64748b;
            font-size: 0.76rem;
            font-weight: 750;
        }

        .quick-chat__send {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 2.65rem;
            height: 2.65rem;
            border: 0;
            border-radius: 999px;
            background: transparent;
            padding: 0;
            color: #0ea5e9;
            cursor: pointer;
        }

        .quick-chat__send:hover,
        .quick-chat__send:focus-visible {
            background: rgba(14, 165, 233, 0.08);
            color: #0284c7;
            outline: none;
        }

        .quick-chat__send:disabled {
            cursor: default;
            opacity: 0.58;
        }

        .quick-chat__empty {
            display: grid;
            place-items: center;
            min-height: 100%;
            padding: 2rem;
            color: #64748b;
            font-size: 0.92rem;
            text-align: center;
        }

        .quick-chat__error {
            margin-top: 0.35rem;
            color: #dc2626;
            font-size: 0.78rem;
            font-weight: 800;
        }

        html.dark .quick-chat__drawer,
        html.dark .quick-chat__search,
        html.dark .quick-chat__close {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark .quick-chat__header,
        html.dark .quick-chat__list,
        html.dark .quick-chat__chat-head,
        html.dark .quick-chat__composer {
            background: rgba(15, 23, 42, 0.92);
        }

        html.dark .quick-chat__title,
        html.dark .quick-chat__item-title,
        html.dark .quick-chat__chat-title,
        html.dark .quick-chat__bubble,
        html.dark .quick-chat__textarea {
            color: #f8fafc;
        }

        html.dark .quick-chat__item:hover {
            background: rgba(148, 163, 184, 0.12);
        }

        html.dark .quick-chat__item--selected {
            background: rgba(14, 165, 233, 0.22);
        }

        html.dark .quick-chat__item--candidate {
            outline-color: rgba(56, 189, 248, 0.32);
            background: rgba(14, 165, 233, 0.1);
        }

        html.dark .quick-chat__item--candidate.quick-chat__item--selected {
            background: rgba(14, 165, 233, 0.22);
        }

        html.dark .quick-chat__bubble,
        html.dark .quick-chat__textarea {
            background: #111827;
        }

        html.dark .quick-chat__attachment {
            border-color: rgba(148, 163, 184, 0.16);
            background: rgba(15, 23, 42, 0.42);
        }

        html.dark .quick-chat__attachment-name {
            color: #f8fafc;
        }

        html.dark .quick-chat__attachment-meta {
            color: #94a3b8;
        }

        html.dark .quick-chat__chip {
            border-color: rgba(56, 189, 248, 0.22);
            background: rgba(14, 165, 233, 0.16);
            color: #bae6fd;
        }

        html.dark .quick-chat__file-label,
        html.dark .quick-chat__selected-file {
            background: rgba(30, 41, 59, 0.82);
            color: #bae6fd;
        }

        html.dark .quick-chat__bubble--own {
            background: rgba(22, 101, 52, 0.82);
        }

        @media (prefers-reduced-motion: reduce) {
            .quick-chat__launcher .quick-chat__badge,
            .quick-chat__backdrop,
            .quick-chat__drawer {
                animation: none;
            }
        }

        @media (max-width: 1023px) {
            .quick-chat__launcher {
                right: 1rem;
            }

            .quick-chat__drawer {
                width: 100vw;
            }
        }

        @media (max-width: 760px) {
            .quick-chat__layout {
                grid-template-columns: minmax(0, 1fr);
                grid-template-rows: auto 1fr;
            }

            .quick-chat__list {
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            }

            .quick-chat__items {
                grid-auto-flow: row;
                grid-auto-columns: auto;
                max-height: min(34dvh, 18rem);
                overflow-x: hidden;
                overflow-y: auto;
                padding-bottom: 0.75rem;
            }

            .quick-chat__thread {
                min-height: 0;
            }

            .quick-chat__bubble {
                max-width: 88%;
            }
        }
    </style>

    <button type="button" class="quick-chat__launcher" x-on:click="syncPageContext()" wire:click="openDrawer" aria-label="Открыть диалоги">
        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-5 w-5" />
        <span>Диалоги</span>
        @if ($unreadCount > 0)
            <span class="quick-chat__badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($isOpen)
        <div class="quick-chat__backdrop" x-on:click="document.documentElement.classList.remove('quick-chat-open')" wire:click="closeDrawer"></div>

        <aside class="quick-chat__drawer" role="dialog" aria-modal="true" aria-label="Диалоги" x-init="document.documentElement.classList.add('quick-chat-open')">
            <header class="quick-chat__header">
                <div>
                    <h2 class="quick-chat__title">Диалоги</h2>
                    <div class="quick-chat__subtitle">Сообщения сотрудников и обращения арендаторов</div>
                </div>

                <button type="button" class="quick-chat__close" x-on:click="document.documentElement.classList.remove('quick-chat-open')" wire:click="closeDrawer" aria-label="Закрыть">
                    <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                </button>
            </header>

            <div class="quick-chat__layout">
                <section class="quick-chat__list" aria-label="Список диалогов">
                    <div class="quick-chat__search-wrap">
                        <input
                            type="search"
                            class="quick-chat__search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="Поиск"
                        >
                    </div>

                    <div class="quick-chat__items">
                        @forelse ($recentChats as $chat)
                            @php
                                $key = $chat['type'] . ':' . $chat['id'];
                                $isSelected = $selectedKey === $key;
                                $isCandidate = (bool) ($chat['is_candidate'] ?? false);
                            @endphp

                            <button
                                type="button"
                                wire:key="quick-chat-item-{{ $key }}"
                                wire:click.prevent="selectChat(@js($chat['type']), {{ (int) $chat['id'] }})"
                                class="quick-chat__item {{ $isSelected ? 'quick-chat__item--selected' : '' }} {{ $isCandidate ? 'quick-chat__item--candidate' : '' }}"
                            >
                                <span class="quick-chat__avatar quick-chat__avatar--{{ $chat['type'] }}">
                                    @if ($isCandidate)
                                        <x-filament::icon icon="heroicon-o-user-plus" class="h-5 w-5" />
                                    @elseif ($chat['type'] === 'ai')
                                        <img class="quick-chat__giga-logo" src="{{ asset('images/gigachat-logo.png') }}" alt="" aria-hidden="true" loading="lazy">
                                    @elseif ($chat['type'] === 'staff')
                                        <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                                    @else
                                        <x-filament::icon icon="heroicon-o-building-storefront" class="h-5 w-5" />
                                    @endif
                                </span>

                                <span style="min-width: 0;">
                                    <span class="quick-chat__item-title">{{ $chat['title'] }}</span>
                                    <span class="quick-chat__item-meta">
                                        <span style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $chat['subtitle'] }}</span>
                                        @if ($chat['meta'])
                                            <span>·</span>
                                            <span>{{ $chat['meta'] }}</span>
                                        @endif
                                        @if (($chat['unread_count'] ?? 0) > 0)
                                            <span class="quick-chat__count">{{ $chat['unread_count'] > 99 ? '99+' : $chat['unread_count'] }}</span>
                                        @endif
                                    </span>
                                    @if ($chat['preview'])
                                        <span class="quick-chat__item-preview">{{ $chat['preview'] }}</span>
                                    @endif
                                </span>
                            </button>
                        @empty
                            <div class="quick-chat__empty">Диалогов пока нет.</div>
                        @endforelse
                    </div>
                </section>

                <section
                    class="quick-chat__thread"
                    aria-label="Переписка"
                    x-data="{ scroll() { this.$nextTick(() => { const el = this.$refs.messages; if (el) el.scrollTop = el.scrollHeight }) } }"
                    x-init="scroll()"
                    x-on:quick-chat-updated.window="scroll()"
                >
                    @if ($selectedChat)
                        @php
                            $isAiChat = ($selectedChat['type'] ?? null) === 'ai';
                        @endphp

                        <div class="quick-chat__chat-head">
                            <div class="quick-chat__chat-title">{{ $selectedChat['title'] }}</div>
                            <div class="quick-chat__chat-meta">
                                {{ $selectedChat['subtitle'] }}
                                @if ($selectedChat['meta'])
                                    · {{ $selectedChat['meta'] }}
                                @endif
                                · {{ $selectedChat['count'] }} в ленте
                            </div>
                            @if ($selectedChat['description'])
                                <div class="quick-chat__chat-description">{{ \Illuminate\Support\Str::limit($selectedChat['description'], 220) }}</div>
                            @endif
                        </div>

                        <div class="quick-chat__messages" x-ref="messages">
                            @php $lastDateKey = null; @endphp

                            @forelse ($messages as $message)
                                @if ($message['date_key'] !== $lastDateKey)
                                    @php $lastDateKey = $message['date_key']; @endphp
                                    <div class="quick-chat__date"><span>{{ $message['date_label'] }}</span></div>
                                @endif

                                <div class="quick-chat__bubble-row {{ $message['is_own'] ? 'quick-chat__bubble-row--own' : '' }}" wire:key="quick-chat-message-{{ $message['id'] }}">
                                    <div class="quick-chat__bubble {{ $message['is_own'] ? 'quick-chat__bubble--own' : '' }}">
                                        <div class="quick-chat__bubble-meta">
                                            <span>{{ $message['user_name'] }}</span>
                                            @if ($message['created_at'])
                                                <span>·</span>
                                                <span>{{ $message['created_at'] }}</span>
                                            @endif
                                        </div>
                                        @if (filled($message['body_html'] ?? $message['body']))
                                            <div class="quick-chat__bubble-text">{!! $message['body_html'] ?? e($message['body']) !!}</div>
                                        @endif

                                        @if (! empty($message['chips']))
                                            <div class="quick-chat__chips">
                                                @foreach ($message['chips'] as $chip)
                                                    @php
                                                        $chipLabel = trim((string) ($chip['label'] ?? ''));
                                                        $chipUrl = trim((string) ($chip['url'] ?? ''));
                                                    @endphp

                                                    @if ($chipLabel !== '' && $chipUrl !== '')
                                                        <a class="quick-chat__chip" href="{{ $chipUrl }}" target="_blank" rel="noopener noreferrer">{{ $chipLabel }}</a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif

                                        @if (! empty($message['suggestions']))
                                            <div class="quick-chat__suggestions">
                                                @foreach ($message['suggestions'] as $suggestion)
                                                    <button
                                                        type="button"
                                                        class="quick-chat__suggestion"
                                                        wire:click="useAiSuggestion(@js($suggestion))"
                                                    >
                                                        {{ $suggestion }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if (! empty($message['pending_action']))
                                            @php
                                                $action = $message['pending_action'];
                                                $actionStatus = $action['status'] ?? 'pending';
                                            @endphp

                                            <div class="quick-chat__action-card quick-chat__action-card--{{ $actionStatus }}">
                                                <div class="quick-chat__action-head">
                                                    <div class="quick-chat__action-title">{{ $action['title'] ?? 'Действие агента' }}</div>
                                                    <div class="quick-chat__action-status">{{ $action['status_label'] ?? 'Ожидает подтверждения' }}</div>
                                                </div>

                                                @if (! empty($action['summary']))
                                                    <div class="quick-chat__action-summary">
                                                        @foreach ($action['summary'] as $row)
                                                            <div class="quick-chat__action-row">
                                                                <div class="quick-chat__action-label">{{ $row['label'] }}</div>
                                                                <div class="quick-chat__action-value">{{ $row['value'] }}</div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                @if ($actionStatus === 'pending')
                                                    <div class="quick-chat__action-controls">
                                                        <button
                                                            type="button"
                                                            class="quick-chat__action-button quick-chat__action-button--primary"
                                                            wire:click="confirmAiAction('{{ $message['id'] }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="confirmAiAction('{{ $message['id'] }}'),cancelAiAction('{{ $message['id'] }}')"
                                                        >
                                                            {{ $action['confirm_label'] ?? 'Подтвердить' }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="quick-chat__action-button quick-chat__action-button--secondary"
                                                            wire:click="cancelAiAction('{{ $message['id'] }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="confirmAiAction('{{ $message['id'] }}'),cancelAiAction('{{ $message['id'] }}')"
                                                        >
                                                            {{ $action['cancel_label'] ?? 'Отменить' }}
                                                        </button>
                                                    </div>
                                                @elseif (! empty($action['result_message']))
                                                    <div class="quick-chat__action-result">{{ $action['result_message'] }}</div>
                                                @endif
                                            </div>
                                        @endif

                                        @if (! empty($message['attachments']))
                                            <div class="quick-chat__attachments">
                                                @foreach ($message['attachments'] as $attachment)
                                                    <a class="quick-chat__attachment" href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                        @if (($attachment['is_image'] ?? false) && ! empty($attachment['preview_url']))
                                                            <img class="quick-chat__attachment-thumb" src="{{ $attachment['preview_url'] }}" alt="{{ $attachment['name'] }}" loading="lazy">
                                                        @else
                                                            <span class="quick-chat__attachment-icon">
                                                                <x-filament::icon icon="heroicon-o-document" class="h-5 w-5" />
                                                            </span>
                                                        @endif

                                                        <span style="min-width: 0;">
                                                            <span class="quick-chat__attachment-name">{{ $attachment['name'] }}</span>
                                                            <span class="quick-chat__attachment-meta">
                                                                {{ $attachment['mime'] }}@if (! empty($attachment['size_label'])) В· {{ $attachment['size_label'] }}@endif
                                                            </span>
                                                        </span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="quick-chat__empty">В этом диалоге пока нет сообщений.</div>
                            @endforelse

                            @if ($isAiChat && $isAiReplyPending)
                                <div class="quick-chat__bubble-row" wire:key="quick-chat-ai-typing">
                                    <div class="quick-chat__bubble">
                                        <div class="quick-chat__bubble-meta">
                                            <span>ИИ-консультант</span>
                                        </div>
                                        <div class="quick-chat__typing" aria-label="ИИ-консультант печатает">
                                            <span class="quick-chat__typing-dot"></span>
                                            <span class="quick-chat__typing-dot"></span>
                                            <span class="quick-chat__typing-dot"></span>
                                            <span>печатает</span>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <form class="quick-chat__composer" wire:submit.prevent="sendMessage">
                            <div class="quick-chat__composer-row">
                                <div class="quick-chat__composer-main">
                                    <div class="quick-chat__input-shell">
                                        @unless ($isAiChat)
                                            <label class="quick-chat__file-label" title="До 5 файлов, до 20 МБ каждый.">
                                                <x-filament::icon icon="heroicon-o-paper-clip" class="h-6 w-6" />
                                                <input class="quick-chat__file-input" type="file" wire:model="messageAttachments" multiple>
                                            </label>
                                        @else
                                            <span class="quick-chat__icon-spacer" aria-hidden="true"></span>
                                        @endunless

                                        <textarea
                                            class="quick-chat__textarea"
                                            wire:model.live.debounce.250ms="messageBody"
                                            placeholder="{{ $isAiChat ? 'Спросите по данным рынка...' : 'Сообщение...' }}"
                                            rows="1"
                                            x-data
                                            x-init="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                                            x-on:input="$el.style.height = 'auto'; const height = Math.min($el.scrollHeight, 160); $el.style.height = height + 'px'; $el.style.overflowY = $el.scrollHeight > 160 ? 'auto' : 'hidden'"
                                        ></textarea>
                                    </div>

                                    @error('messageBody')
                                        <div class="quick-chat__error">{{ $message }}</div>
                                    @enderror

                                    @error('messageAttachments')
                                        <div class="quick-chat__error">{{ $message }}</div>
                                    @enderror

                                    @error('messageAttachments.*')
                                        <div class="quick-chat__error">{{ $message }}</div>
                                    @enderror

                                    @if ($messageAttachments !== [])
                                        <div class="quick-chat__selected-files">
                                            @foreach ($messageAttachments as $file)
                                                @if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                                    <span class="quick-chat__selected-file" title="{{ $file->getClientOriginalName() }}">
                                                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4" />
                                                        <span>{{ $file->getClientOriginalName() }}</span>
                                                    </span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif

                                    <span class="quick-chat__uploading" wire:loading wire:target="messageAttachments">Загрузка...</span>
                                </div>

                                <button
                                    type="submit"
                                    class="quick-chat__send"
                                    wire:loading.attr="disabled"
                                    wire:target="sendMessage,completeAiReply,messageAttachments"
                                    @disabled($isAiChat && $isAiReplyPending)
                                    aria-label="{{ $isAiChat ? 'Спросить' : 'Отправить' }}"
                                >
                                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-7 w-7" />
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="quick-chat__empty">Выберите диалог слева.</div>
                    @endif
                </section>
            </div>
        </aside>
    @endif
</div>
