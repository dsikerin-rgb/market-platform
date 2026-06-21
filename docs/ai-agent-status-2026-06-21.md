# AI agent status, 2026-06-21

This note records the current implementation state so the AI-agent work does not loop back into already completed tasks.

## In main

- AI consultant is embedded into the existing quick chat drawer and has a separate launcher below the staff rail.
- GigaChat-backed settings page exists at `/admin/ai-agent-settings`; access is limited to `super-admin`.
- Settings are split into tabs and include prompt, model parameters, read-only SQL, business/action tools, role permissions, and knowledge view.
- Conversations and AI messages are persisted. The agent receives conversation history and current page context.
- Context is budgeted through `AiContextBudgeter`; history, page context, profile, and knowledge are compacted before sending to the model.
- Read-only SQL is available through a guarded tool with allowed tables and market scoping.
- Business tools exist for debt leaders, rent-rate extremes, vacant spaces, tenant summary, open requests, expiring contracts, and resource links.
- User-facing links are rendered as chips. Obsolete tenant links like `/admin/tenants/view/{id}` are normalized to `/admin/tenants/{id}/edit`.
- The prompt tells the agent not to expose IDs, table names, raw URLs, or technical field names.
- Agent actions with consequences are prepared as confirmation cards before execution.
- Supported prepared actions include creating tasks, reminders, market events, profile updates, resource links, and knowledge entries.
- AI reply delay and typing indicator are already implemented through `isAiReplyPending`, `completeAiReply`, and the `quick-chat-ai-reply-queued` browser event.
- Page help nudge exists near the AI launcher. It hides the normal dialog launcher while visible and opens AI chat with a page-scoped greeting.
- Page nudge dismissal is page-scoped/cooldown based, not global forever.
- Priority page nudges consider overdue tasks, open requests, tenant debt page context, rejected topics, and communication status.
- AI user profile exists with job title, department, responsibility scope, birthday, phone, contact channels, notification channels, communication status, rejected topics, onboarding status, and preferred name.
- Staff profile edit page has tabs and includes AI profile resources/knowledge for `super-admin`.
- The agent can save "call me ..." style preferred names through profile update flow.
- Lightweight onboarding exists: initial offer, snooze on "later", a 3-step wizard, and final confirmation before saving profile data.
- Knowledge entries exist as agent dictionaries with confidence and source authority. The agent should not treat self-proclaimed authority as a high-confidence fact.
- The agent can learn responsibility facts from conversations, including when one user says another employee owns a topic.

## Open / not merged

- PR #1139: "Keep AI onboarding optional for work planning".
  - Branch: `codex/ai-work-priority-over-onboarding`.
  - Status: open draft.
  - Purpose: when a user asks broadly "what shall we do next?", the agent should first offer useful work directions; onboarding should be only one optional path, not the only answer.

## Do not redo

- Do not reimplement AI typing delay or "agent is typing" UX; it is already in `main` from PR #1133.
- Do not reimplement chip normalization for tenant links; it is already in `main` from PR #1116.
- Do not reimplement the basic onboarding wizard; it is already in `main` from PR #1138.
- Do not reimplement super-admin-only AI settings; it is already in `main` from PR #1129.

## Remaining sensible next work

- Merge or replace PR #1139 so broad work-planning questions do not collapse into onboarding.
- Verify the deployed instance is actually running the current `main`; if behavior differs, investigate deployment/cache rather than writing the same feature again.
- Add a small regression checklist for AI chat UX after deploy: typing indicator, chip links, onboarding offer, page nudge, profile update confirmation.
- Improve the agent's answer quality for broad "what next" questions with tests around concrete Russian prompts and expected suggestions.
- Add admin tools for reviewing and correcting learned knowledge/profile facts without database access.
