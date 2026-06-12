# 1C Settlements Backfill

This folder contains the first manual 1C settlement balance export for the
Market Platform production environment.

## Scope

Use this exporter for OSV-like settlement balances by account.

The first production run must use only account `62` or a confirmed `62.*`
subaccount. Do not include `76.*` in the first release: `76.06` and `76.07`
were checked in 1C and did not contain tenant security deposit data for the
reviewed periods.

## Endpoint

```text
POST /api/1c/settlements
```

Startup parameter shape:

```text
AUTO;SETTLEMENTS;YYYY-MM;ACCOUNT
```

Example:

```text
AUTO;SETTLEMENTS;2026-06;62
```

The API snapshot is scoped by `account`, so importing account `62` will not
delete rows from other accounts.

## Files

- `AutoSend_prod_settlements_backfill_form_module.bsl` - form module text to
  paste into a dedicated external processing file.
- `run_prod_settlements_backfill_2026-06_62.bat` - one-time launcher example.
- `..\run_prod.bat` - daily production launcher that runs settlements for the
  current and previous month after the regular exchange and payments.

Build/save the external processing file on the 1C host as:

```text
C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf
```

Then run the BAT after setting the real 1C password.

## Daily Automation

The production daily launcher runs this EPF after:

1. the regular `AutoSend_prod.epf` exchange;
2. current-month payments;
3. previous-month payments.

It exports account `62` for:

- current month;
- previous month.

This mirrors the payments window and catches late accountant corrections after
month close. Do not add `76.*` accounts to the daily launcher until their tenant
analytics are confirmed in 1C.
