@echo off
REM ===========================================================================
REM  EMPOWER - double-click this, or run  run  from a terminal.
REM
REM  Windows ships with PowerShell's execution policy set to Restricted, which
REM  refuses to run any .ps1 file:
REM
REM      .\start.ps1 : File ... cannot be loaded because running scripts is
REM      disabled on this system.
REM
REM  That is the usual reason start.ps1 appears not to work on a fresh machine.
REM  Named run.cmd, not start.cmd: "start" is a cmd.exe built-in, so a file of
REM  that name is shadowed and typing it opens a blank window instead.
REM
REM  This wrapper passes -ExecutionPolicy Bypass for this one command only, so
REM  nothing about the machine's security settings has to be changed - which
REM  also means a groupmate cloning the repository can run it straight away.
REM
REM  Arguments are passed through:
REM      run            API + frontend + OCR
REM      run -NoOcr     skip the OCR service
REM      run -Stop      stop everything
REM ===========================================================================

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start.ps1" %*

REM Only pause when something went wrong, and only when double-clicked -
REM otherwise the window would sit open after every successful start.
if errorlevel 1 (
    echo.
    echo Startup failed. The message above says why.
    pause
)
