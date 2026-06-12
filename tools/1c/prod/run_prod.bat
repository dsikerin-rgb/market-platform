@echo off
REM ============================================================
REM Production 1C launcher for the customer environment.
REM
REM Runs:
REM 1) regular daily AutoSend_prod.epf: debts, contracts, accruals;
REM 2) payments export for current month;
REM 3) payments export for previous month;
REM 4) settlement balances (OSV) export for current month, account 62;
REM 5) settlement balances (OSV) export for previous month, account 62.
REM
REM The BAT file can live in C:\CRM.
REM EPF files are expected in C:\8base\VDNH.
REM Keep the real 1C password only on the 1C host copy.
REM ============================================================

setlocal EnableExtensions

set "ONEC_EXE=C:\Program Files\1cv8\common\1cestart.exe"
REM Fallback if needed:
REM set "ONEC_EXE=C:\Program Files\1cv8\8.3.27.1989\bin\1cv8.exe"

set "BASE_PATH=C:\8base\VDNH"
set "EPF_PATH=C:\8base\VDNH\AutoSend_prod.epf"
set "PAYMENTS_EPF_PATH=C:\8base\VDNH\AutoSend_prod_payments_backfill.epf"
set "SETTLEMENTS_EPF_PATH=C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf"
set "SETTLEMENTS_ACCOUNT=62"
set "ONEC_USER=CRM"
set "ONEC_PASSWORD=YOUR_1C_PASSWORD"
set "LOG_PATH=%~dp0auto_log_prod.txt"

"%ONEC_EXE%" ENTERPRISE ^
  /F"%BASE_PATH%" ^
  /Execute "%EPF_PATH%" ^
  /C"AUTO" ^
  /N"%ONEC_USER%" ^
  /P"%ONEC_PASSWORD%" ^
  /Out "%LOG_PATH%"

set "MAIN_EXIT_CODE=%errorlevel%"
if not "%MAIN_EXIT_CODE%"=="0" exit /b %MAIN_EXIT_CODE%

for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).ToString('yyyy-MM')"') do set "CURRENT_PERIOD=%%i"
for /f %%i in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).AddMonths(-1).ToString('yyyy-MM')"') do set "PREVIOUS_PERIOD=%%i"

call :RunPaymentsForPeriod "%CURRENT_PERIOD%"
set "PAYMENTS_CURRENT_EXIT_CODE=%errorlevel%"
if not "%PAYMENTS_CURRENT_EXIT_CODE%"=="0" exit /b %PAYMENTS_CURRENT_EXIT_CODE%

call :RunPaymentsForPeriod "%PREVIOUS_PERIOD%"
set "PAYMENTS_PREVIOUS_EXIT_CODE=%errorlevel%"
if not "%PAYMENTS_PREVIOUS_EXIT_CODE%"=="0" exit /b %PAYMENTS_PREVIOUS_EXIT_CODE%

call :RunSettlementsForPeriod "%CURRENT_PERIOD%" "%SETTLEMENTS_ACCOUNT%"
set "SETTLEMENTS_CURRENT_EXIT_CODE=%errorlevel%"
if not "%SETTLEMENTS_CURRENT_EXIT_CODE%"=="0" exit /b %SETTLEMENTS_CURRENT_EXIT_CODE%

call :RunSettlementsForPeriod "%PREVIOUS_PERIOD%" "%SETTLEMENTS_ACCOUNT%"
set "SETTLEMENTS_PREVIOUS_EXIT_CODE=%errorlevel%"
if not "%SETTLEMENTS_PREVIOUS_EXIT_CODE%"=="0" exit /b %SETTLEMENTS_PREVIOUS_EXIT_CODE%

exit /b 0

:RunPaymentsForPeriod
set "PAYMENTS_PERIOD=%~1"
if "%PAYMENTS_PERIOD%"=="" exit /b 1

set "PAYMENTS_LOG_PATH=%~dp0auto_log_prod_payments_%PAYMENTS_PERIOD%.txt"

"%ONEC_EXE%" ENTERPRISE ^
  /F"%BASE_PATH%" ^
  /Execute "%PAYMENTS_EPF_PATH%" ^
  /C"AUTO;PAYMENTS;%PAYMENTS_PERIOD%" ^
  /N"%ONEC_USER%" ^
  /P"%ONEC_PASSWORD%" ^
  /Out "%PAYMENTS_LOG_PATH%"

exit /b %errorlevel%

:RunSettlementsForPeriod
set "SETTLEMENTS_PERIOD=%~1"
set "SETTLEMENTS_ACCOUNT_ARG=%~2"
if "%SETTLEMENTS_PERIOD%"=="" exit /b 1
if "%SETTLEMENTS_ACCOUNT_ARG%"=="" exit /b 1

set "SETTLEMENTS_LOG_PATH=%~dp0auto_log_prod_settlements_%SETTLEMENTS_PERIOD%_%SETTLEMENTS_ACCOUNT_ARG%.txt"

"%ONEC_EXE%" ENTERPRISE ^
  /F"%BASE_PATH%" ^
  /Execute "%SETTLEMENTS_EPF_PATH%" ^
  /C"AUTO;SETTLEMENTS;%SETTLEMENTS_PERIOD%;%SETTLEMENTS_ACCOUNT_ARG%" ^
  /N"%ONEC_USER%" ^
  /P"%ONEC_PASSWORD%" ^
  /Out "%SETTLEMENTS_LOG_PATH%"

exit /b %errorlevel%
