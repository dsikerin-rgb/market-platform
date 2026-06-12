# Daily 1C Payments And Settlements Export

This note describes the production launcher change needed for daily tenant
payments and settlement balances import.

## Goal

The regular 1C production job must keep exporting the existing daily entities
and additionally refresh tenant payments and OSV-like settlement balances every
day.

The application endpoint is:

```text
POST /api/1c/payments
```

The checked 1C external processing file is:

```text
C:\8base\vdnh\AutoSend_prod_payments_backfill.epf
```

The settlement balances endpoint is:

```text
POST /api/1c/settlements
```

The checked 1C external processing file is:

```text
C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf
```

## Daily Window

Run the payments export for two periods on every daily job:

- current month, for normal daily bank postings;
- previous month, to catch late bank postings and accountant corrections after
  month close.

The payments import is snapshot-synced per supplied period, so repeated exports
for the same period are expected and must not create duplicates.

Run the settlement balances export for the same two periods on every daily job:

- current month, for the current accounting picture;
- previous month, to catch late accountant corrections after month close.

The first automated account scope is `62`. Do not add `76.*` accounts until
their tenant analytics are confirmed in 1C.

## Production Launcher Shape

After the regular `AutoSend_prod.epf` call with `/C"AUTO"`, calculate:

```bat
for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).ToString('yyyy-MM')"') do set "CURRENT_PERIOD=%%i"
for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).AddMonths(-1).ToString('yyyy-MM')"') do set "PREVIOUS_PERIOD=%%i"
```

Then run the payments EPF twice:

```bat
/Execute "C:\8base\vdnh\AutoSend_prod_payments_backfill.epf" /C"AUTO;PAYMENTS;%CURRENT_PERIOD%"
/Execute "C:\8base\vdnh\AutoSend_prod_payments_backfill.epf" /C"AUTO;PAYMENTS;%PREVIOUS_PERIOD%"
```

Then run the settlements EPF twice:

```bat
/Execute "C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf" /C"AUTO;SETTLEMENTS;%CURRENT_PERIOD%;62"
/Execute "C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf" /C"AUTO;SETTLEMENTS;%PREVIOUS_PERIOD%;62"
```

Use separate log files:

```text
C:\CRM\auto_log_prod_payments_YYYY-MM.txt
C:\CRM\auto_log_prod_settlements_YYYY-MM_62.txt
```

If the main daily exchange fails, do not start payments. If either payments
or settlements period fails, return its non-zero exit code to the scheduler.

## First Run Checks

After the first scheduled run, verify:

- the two payments log files exist on the 1C host;
- the two settlements log files exist on the 1C host;
- the admin 1C document journal shows fresh payment documents for the current
  month;
- the admin 1C settlements page shows fresh account `62` rows for the current
  month;
- `integration_exchanges` has successful `payments` rows for both periods;
- `integration_exchanges` has successful `settlements` rows for both periods;
- no duplicate payment documents appear after running the same period again.
- re-running the same settlements period updates the snapshot without
  duplicating rows.
