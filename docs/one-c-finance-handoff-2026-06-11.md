# 1C Finance Handoff, 2026-06-11

This document records the current state of the Market Platform 1C finance
integration after the June 2026 accrual/payment/settlement work.

## Source Of Truth

- Production data is the source of truth for live 1C integration behavior.
- Staging is only for code and smoke checks. It does not prove live 1C data
  completeness.
- 1C is the primary source for current financial documents.
- Old CSV/table data is a historical reference layer, not the current authority.

## Deployed State

The following work has been merged and deployed to staging and production:

- 1C accrual deduplication.
- Snapshot deletion for accruals missing from the current 1C export.
- Tenant payments import from 1C.
- Tenant payments UI on tenant pages.
- Dashboard payment widgets and cleanup.
- 1C document journal for accruals and payments.
- Daily production launcher change for payments:
  - regular `AutoSend_prod.epf` run;
  - payments export for the current month;
  - payments export for the previous month.
- Russian labels for 1C exchange Telegram notifications.
- Settlement balance import endpoint:
  - `POST /api/1c/settlements`;
  - table `tenant_settlement_balances`;
  - deployed with migration on staging and production.

Production and staging were updated to commit `31e6801` after PR #897.

## Current 1C Entities

### Contracts

- Imported by the regular daily 1C exchange.
- Telegram label: `Договоры`.

### Debts

- Imported by the regular daily 1C exchange.
- Telegram label: `Долги`.
- Current model stores `account`, `organization_external_id`, and
  `organization_name`.
- Debt status/map logic treats calculation accounts as:
  - exact: `62`, `76.07`;
  - prefixes: `62.*`.
- Security deposit accounts are currently defined separately as:
  - `76.06`.

### Accruals

- Imported by the regular daily 1C exchange.
- Telegram label: `Начисления`.
- Historical backfill was run for earlier 2026 periods.
- Import is snapshot-based by period: rows missing from the current 1C snapshot
  can be deleted from our system for that imported period.
- Repeated imports should not create duplicates.

### Payments

- Imported from `AutoSend_prod_payments_backfill.epf`.
- Telegram label: `Оплаты`.
- The production `run_prod.bat` now launches payments for:
  - current month;
  - previous month.
- This is intentional to catch late bank postings and accountant corrections.
- Payments import stores payment purpose, document number, document date,
  credit account, debit account, organization, tenant, and contract link.

### Settlement Balances

- New endpoint exists: `POST /api/1c/settlements`.
- Telegram label: `Расчеты/сальдо`.
- Data is stored in `tenant_settlement_balances`.
- The endpoint requires a top-level `account`.
- Snapshot upsert/delete is scoped by:
  - market;
  - account;
  - period_from;
  - period_to.
- This is required so importing account `62.01` cannot delete balances from
  `76.06`, `76.07`, or any other account.
- No settlement balance data has been imported yet after deployment. The table
  currently exists but is empty on production.

## Important Finding About Security Deposit

We previously recorded the security deposit account in code as `76.06`.

However, current production data does not contain rows in `contract_debts` for:

- `76`;
- `76.06`;
- `76.07`.

Conclusion: security deposit is not currently arriving through the existing
daily debt import.

Most likely reason: the current debt export in `AutoSend_prod.epf` filters the
account by the 1C plan account hierarchy for customer settlements
(`РасчетыСПокупателями`). The security deposit account `76.06` is outside that
export scope.

Therefore, the application is ready to display/use the security deposit account,
but the current 1C export does not send it.

## Why We Are Moving To OSV/Settlement Export

The accrual/payment journal answers the question:

> Which documents came from 1C for a selected period?

It does not fully answer:

> What is the accountant-approved balance/debt at the beginning and end of a
> period?

For that we need an OSV-like settlement export by account, tenant, contract, and
settlement document.

The target logic should be similar to the 1C account turnover/balance statement:

- period from;
- period to;
- account;
- counterparty;
- contract;
- settlement document;
- opening debit/credit;
- debit turnover;
- credit turnover;
- closing debit/credit.

This is the correct source for the future "Расчеты с арендаторами 1С" screen and
for later debt/map color decisions.

## Current Product Screens

### 1C Document Journal

The existing page shows imported documents for a date period:

- accrual documents;
- payment documents;
- document type chips;
- search;
- pagination.

This page is for document inspection, not final debt accounting.

### Tenant Card

Tenant card now has 1C-related blocks for:

- payments;
- current debt/settlement summaries.

The security deposit block exists conceptually, but it needs data from account
`76.06` to become useful.

### Dashboard

Dashboard keeps summary widgets only.

The detailed reconciliation table was removed from the dashboard because it was
too dense and belongs on a dedicated page.

## Next Plan

### Step 1. Confirm 1C Account Scope

In 1C, confirm the exact account(s) that must be exported:

- rent/customer settlements: likely `62.01` or the whole `62` hierarchy;
- security deposit: recorded in our code as `76.06`;
- another calculation account already recognized by our model: `76.07`.

Do not assume only account 62 is enough.

### Step 2. Patch/Create 1C Settlement EPF

Create or patch an EPF that exports OSV/settlement balances for a supplied:

- period start;
- period end;
- account.

The export must send one API call per account, with top-level `account`.

Example payload shape:

```json
{
  "calculated_at": "2026-06-11 10:00:00",
  "period_from": "2026-06-01",
  "period_to": "2026-06-30",
  "account": "62.01",
  "items": [
    {
      "tenant_external_id": "...",
      "tenant_name": "...",
      "contract_external_id": "...",
      "contract_name": "...",
      "settlement_document_external_id": "...",
      "settlement_document_name": "...",
      "organization_external_id": "...",
      "organization_name": "...",
      "opening_debit": 0,
      "opening_credit": 0,
      "turnover_debit": 0,
      "turnover_credit": 0,
      "closing_debit": 0,
      "closing_credit": 0,
      "currency": "RUB"
    }
  ]
}
```

If a row has no item-level `account`, the API will use the top-level account.

### Step 3. First Manual Imports

Run the settlement export manually for a controlled period, probably June 2026:

- account `62.01` or the confirmed 62 scope;
- account `76.06`;
- account `76.07` if 1C uses it for relevant tenant settlements.

After each run, verify:

- Telegram notification says `Расчеты/сальдо`;
- `tenant_settlement_balances` row count increases;
- `one_c_import_logs` contains account and period metadata;
- importing account `62.01` does not delete `76.06` rows.

### Step 4. Build The Accounting Screen

After data is confirmed, build a dedicated screen:

- tenant;
- contract;
- account;
- settlement document;
- opening balance;
- debit turnover;
- credit turnover;
- closing balance;
- filters by date range and account;
- search by tenant/contract/document.

This screen should be the accountant-facing "Расчеты с арендаторами 1С".

### Step 5. Only Then Update Debt/Map Logic

Do not use month-only accrual minus payment logic for final map colors.

After settlement balances are confirmed, decide how the map should use:

- account `62.*` debt;
- account `76.06` security deposit;
- account `76.07` if applicable;
- old/manual review debt tails.

## What Not To Do

- Do not treat staging as proof of live 1C financial completeness.
- Do not mix all accounts in one snapshot without `account` scope.
- Do not let account 62 imports delete account 76 balances.
- Do not use the document journal as the final debt screen.
- Do not rely on old CSV/table data as the current financial truth.
- Do not change map debt logic until OSV/settlement balances are confirmed.

## Current Open Question

The exact 1C account for security deposit must be reconfirmed in 1C. The app
currently uses `76.06`, but production imports have not yet delivered any rows
for that account.
