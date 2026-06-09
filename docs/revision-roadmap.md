# Revision Roadmap

## Current Status

The current product stage is:

- `Map`
- `Revision`
- `Revision Results`

This stage is functionally complete on prod after live verification.

Closed prod checkpoint:

- debt/1C display model is explicit: place debt, tenant total debt, and shared-use neutral status are separated;
- unconfirmed financial-link cards no longer require reading long technical text;
- normal child places are not shown as financial-link problems when the contract must belong to the parent;
- group episodes exist as a read model and are used in contract history;
- current group episodes were backfilled;
- historical parent shape split into existing spaces is supported from the map editor;
- `СТ2-4` was split into existing spaces `СТ 2` and `СТ 3` without activating `СТ2-4`, creating wrong names, or overwriting target areas.

## Closed Product Layer

The current layer is:

- `1C` is the primary source of truth for contracts, debts, and accruals.
- `CSV` is reference-only.
- The map does not invent exact facts when the chain is not confirmed.
- Revision is a periodic reconciliation flow, not a replacement for `1C`.
- Revision results are exposed through a dedicated read-only super-admin page.

## Intermediate Operating Model Without Daily Flow

The system is allowed to operate without a full daily-flow module.

Working model:

- `1C` provides financial and contract truth.
- The map shows confirmed local state honestly.
- Revision is used to record exceptions and periodic checks.
- The `Needs Attention` tail is reviewed explicitly instead of forcing full manual daily updates.

This means `daily-flow` is not a blocker for the current stage.

## Required Rules

The roadmap assumes these rules:

1. Users should not be required to manually refresh all places every day.
2. Manual input should be limited to exceptions:
   current occupancy mismatch, free, service, not found, conflict.
3. Unconfirmed data must not pretend to be exact per-space truth.
4. Raw `Operation` must not return to the main user UX.
5. Revision must not mutate `1C`-owned financial truth.

## Freshness And Control

The operating model should make freshness visible:

- last `1C` import
- last revision per place
- current review status
- `Needs Attention` tail

Revision should be treated as a scheduled procedure:

- short revision: weekly
- full revision: monthly
- unscheduled revision: only for problem zones

## Next Product Stage

The next major product stage is:

- `Daily-flow` for places and tenants

This is a separate improvement layer for operational updates such as:

- a place becomes free
- a new tenant arrives
- observed occupancy changes
- safe local updates that must not bypass `1C`

The next thread should start from this layer, not from another map-shape refactor, unless a regression is found in the already deployed split/group functionality.

## Not A Blocker For This Stage

These items do not block closing the current product stage:

- testing PostgreSQL bootstrap issues
- minor UI polish
- cleanup of shared helper logic
