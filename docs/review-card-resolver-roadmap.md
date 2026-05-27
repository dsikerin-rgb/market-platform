# Review Card Resolver roadmap

Status: planned. Do not start until the current bugfix for `ОС1 7, 8, У` is finished.

## Goal

Move review-card scenario selection from Blade/JS into a backend resolver/service.

The UI should not decide which scenario has priority between duplicate, tenant switch, contract override, group mismatch, shared-use source-space and similar cases. Backend should return a prepared card model, and the UI should render it.

## Target resolver output

The resolver should return:

- `case_type`
- `primary_action`
- `allowed_actions`
- `blocked_actions`
- `explanation`
- `related_spaces`

Example meaning:

- `case_type`: `duplicate_identity`
- `primary_action`: `resolve_duplicate`
- `allowed_actions`: open source space, open canonical space, resolve duplicate
- `blocked_actions`: tenant switch is blocked until duplicate is resolved
- `explanation`: why this case must be treated as duplicate first

## Base priority order

```text
duplicate/identity
> group mismatch
> shared-use/source-space
> missing shape
> tenant switch
> contract override
```

If the identity of the place is not confirmed, the UI must not offer actions that assume the canonical place is already known.

## PR plan

### PR 1. Introduce resolver for action priority

Create `MarketMapReviewCardResolver` or `SpaceReviewConflictResolver`.

Return the minimal action-priority model:

- `case_type`
- `primary_action`
- `allowed_actions`
- `blocked_actions`
- `explanation`
- `related_spaces`

Add a regression test for `ОС1 7, 8, У`: duplicate/identity must have higher priority than tenant switch.

Do not rewrite the whole card in this PR.

### PR 2. Render actions from resolver

Move review-card buttons to `allowed_actions` and `blocked_actions`.

Blade/JS should render the resolver result instead of guessing the primary action.

### PR 3. Render warnings and explanations from resolver

Move warnings and user explanations to resolver output.

Example: tenant switch is not available until the duplicate place is resolved.

### PR 4. Remove scattered priority logic

After test coverage is in place, remove old scattered `if/else` priority logic from Blade/JS.

## Restrictions

- Do not rewrite the full review card in one PR.
- Do not change the database.
- Do not change decision enums without a separate task.
- Do not touch AI, 1C, debt, accruals, contracts or finance logic.
- Do not mix this roadmap/refactor with active bugfix work.
- No deploy is needed for this docs-only roadmap entry.

## First-stage acceptance criteria

- Resolver/service exists with a minimal action-priority model.
- Duplicate/identity has a regression test proving it outranks tenant switch.
- Existing review-card cases keep working.
- Priority ownership starts moving from UI to backend.
