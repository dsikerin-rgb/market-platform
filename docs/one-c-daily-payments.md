# Daily 1C Payments Export

This note describes the production launcher change needed for daily tenant
payments import.

## Goal

The regular 1C production job must keep exporting the existing daily entities
and additionally refresh tenant payments every day.

The application endpoint is:

```text
POST /api/1c/payments
```

The checked 1C external processing file is:

```text
C:\8base\vdnh\AutoSend_prod_payments_backfill.epf
```

## Daily Window

Run the payments export for two periods on every daily job:

- current month, for normal daily bank postings;
- previous month, to catch late bank postings and accountant corrections after
  month close.

The payments import is snapshot-synced per supplied period, so repeated exports
for the same period are expected and must not create duplicates.

## Production Launcher Shape

After the regular `AutoSend_prod.epf` call with `/C"AUTO"`, calculate:

```bat
for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).ToString('yyyy-MM')"') do set "PAYMENTS_CURRENT_PERIOD=%%i"
for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).AddMonths(-1).ToString('yyyy-MM')"') do set "PAYMENTS_PREVIOUS_PERIOD=%%i"
```

Then run the payments EPF twice:

```bat
/Execute "C:\8base\vdnh\AutoSend_prod_payments_backfill.epf" /C"AUTO;PAYMENTS;%PAYMENTS_CURRENT_PERIOD%"
/Execute "C:\8base\vdnh\AutoSend_prod_payments_backfill.epf" /C"AUTO;PAYMENTS;%PAYMENTS_PREVIOUS_PERIOD%"
```

Use separate log files:

```text
C:\8base\vdnh\auto_log_prod_payments_YYYY-MM.txt
```

If the main daily exchange fails, do not start payments. If either payments
period fails, return its non-zero exit code to the scheduler.

## First Run Checks

After the first scheduled run, verify:

- the two payments log files exist on the 1C host;
- the admin 1C document journal shows fresh payment documents for the current
  month;
- `integration_exchanges` has successful `payments` rows for both periods;
- no duplicate payment documents appear after running the same period again.
