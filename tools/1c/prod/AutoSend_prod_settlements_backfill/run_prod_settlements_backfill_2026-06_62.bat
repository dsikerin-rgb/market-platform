@echo off
REM ============================================================
REM One-time production launcher for 1C settlement balances.
REM
REM Use this with AutoSend_prod_settlements_backfill.epf only.
REM Startup parameter shape:
REM   AUTO;SETTLEMENTS;YYYY-MM;ACCOUNT
REM
REM First controlled run: June 2026, account 62.
REM Do not add 76.* accounts until their tenant analytics are confirmed in 1C.
REM ============================================================

set "ONEC_EXE=C:\Program Files\1cv8\common\1cestart.exe"
REM Fallback if needed:
REM set "ONEC_EXE=C:\Program Files\1cv8\8.3.27.1989\bin\1cv8.exe"

set "BASE_PATH=C:\8base\VDNH"
set "EPF_PATH=C:\8base\VDNH\AutoSend_prod_settlements_backfill.epf"
set "ONEC_USER=CRM"
set "ONEC_PASSWORD=ВАШ_ПАРОЛЬ_1С"
set "SETTLEMENTS_PERIOD=2026-06"
set "SETTLEMENTS_ACCOUNT=62"
set "LOG_PATH=C:\8base\VDNH\auto_log_prod_settlements_%SETTLEMENTS_PERIOD%_%SETTLEMENTS_ACCOUNT%.txt"

"%ONEC_EXE%" ENTERPRISE ^
  /F"%BASE_PATH%" ^
  /Execute "%EPF_PATH%" ^
  /C"AUTO;SETTLEMENTS;%SETTLEMENTS_PERIOD%;%SETTLEMENTS_ACCOUNT%" ^
  /N"%ONEC_USER%" ^
  /P"%ONEC_PASSWORD%" ^
  /Out "%LOG_PATH%"

exit /b %errorlevel%
