@echo off
REM ===========================================================================
REM SmartEPT LiveView Relay — one-time service install (15-Sep-2026).
REM
REM Safe to run on an ALREADY-CONFIGURED admin console: unlike INSTALL.bat,
REM this touches ONLY the LiveView relay. It does NOT run migrations, does NOT
REM re-seed anything, and — critically — does NOT run "artisan key:generate",
REM which would rotate your existing APP_KEY and break already-encrypted data.
REM This is exactly step [9/9] of INSTALL.bat, pulled out on its own so it can
REM be run by itself, any time, without re-running the rest of that installer.
REM
REM What it does:
REM   1. Reads the CURRENT APP_KEY out of .env (never changes it).
REM   2. Registers a Scheduled Task ("SmartEPT LiveView Relay") that launches
REM      the relay at boot, as SYSTEM — no login required, no terminal, no
REM      npm, ever again — via a small VBScript watchdog that relaunches it
REM      if it ever crashes. Also opens the Windows Firewall for the relay's
REM      port, read from .env's RELAY_PORT (default 8098) — a background task
REM      with no interactive session can't click an "Allow access" prompt, so
REM      without this an Agent on another PC connects fine to this machine's
REM      web server but never reaches the relay port itself (found 15-Sep-2026:
REM      LiveView worked for a browser/Agent on the SAME machine as the relay,
REM      but a genuinely different PC sat on "Waiting for the Agent..." forever).
REM   3. Starts it immediately (no reboot needed).
REM
REM 15-Sep-2026: this used to register relay\dist\smartept-relay-win.exe
REM directly as a Windows SERVICE via sc.exe (the same way the Agent's own
REM enforcement service does). That registers fine but sc.exe start then
REM fails with error 1053 every time — the Agent's service is a Go binary
REM that implements the Service Control Manager protocol; this relay is a
REM plain pkg-compiled Node console app that doesn't, and never will without
REM a much bigger rewrite. A Scheduled Task has no such requirement — it just
REM launches a process — so that's what actually works for this binary.
REM
REM Re-runnable any time (e.g. after rebuilding the relay) — it always
REM removes any old registration (task OR the old sc.exe service, if this
REM machine still has one from before this fix) and recreates it.
REM ===========================================================================

REM --- needs an administrator token for sc.exe create; relaunch elevated if not ---
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo Requesting administrator access — click Yes on the prompt...
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)

setlocal enabledelayedexpansion
cd /d "%~dp0"
title SmartEPT - LiveView Relay Service Install
echo ============================================================
echo   SmartEPT LiveView Relay - Service Install
echo ============================================================
echo.

if not exist "relay\dist\smartept-relay-win.exe" (
  echo [ERROR] relay\dist\smartept-relay-win.exe not found.
  echo         Build it once from the relay folder:  npm install ^&^& npm run build
  pause
  exit /b 1
)

if not exist ".env" (
  echo [ERROR] .env not found in %CD% - run this from the smartept folder.
  pause
  exit /b 1
)

echo [1/3] Reading APP_KEY from .env...
for /f "tokens=1,* delims==" %%a in ('findstr /b "APP_KEY=" .env') do (
  >relay\relay-secret.txt echo %%b
  >relay\dist\relay-secret.txt echo %%b
)
echo    [ok] Secret written for both the dev entry point and the compiled binary.

set "RELAY_PORT=8098"
for /f "tokens=1,* delims==" %%a in ('findstr /b "RELAY_PORT=" .env') do set "RELAY_PORT=%%b"

echo.
echo [2/3] Registering the startup task...
REM Clean up any previous registration before recreating — including a leftover
REM sc.exe SERVICE from before 15-Sep-2026's fix (harmless if none exists).
taskkill /F /IM smartept-relay-win.exe >nul 2>nul
sc.exe stop "SmartEPT LiveView Relay" >nul 2>nul
sc.exe delete "SmartEPT LiveView Relay" >nul 2>nul
schtasks /Delete /F /TN "SmartEPT LiveView Relay" >nul 2>nul
schtasks /Create /F /TN "SmartEPT LiveView Relay" /SC ONSTART /RU SYSTEM /RL HIGHEST /TR "\"%WINDIR%\System32\wscript.exe\" \"%CD%\relay\daemon\relay-watchdog.vbs\""
if not "%errorlevel%"=="0" (
  echo    [ERROR] Could not create the scheduled task - see the message above.
  pause
  exit /b 1
)
echo    [ok] Task registered - launches the relay at boot, as SYSTEM, no login needed.

REM Open the port for other PCs on the LAN — a SYSTEM-run background task has no
REM one there to click a Windows Firewall "Allow access" prompt, so without this
REM rule the relay is only reachable from this same machine.
netsh advfirewall firewall delete rule name="SmartEPT LiveView Relay" >nul 2>nul
netsh advfirewall firewall add rule name="SmartEPT LiveView Relay" dir=in action=allow protocol=TCP localport=%RELAY_PORT% >nul 2>nul
echo    [ok] Firewall opened for port %RELAY_PORT% - other PCs on the network can now reach it.

echo.
echo [3/3] Starting it now...
schtasks /Run /TN "SmartEPT LiveView Relay"
if not "%errorlevel%"=="0" (
  echo    [WARN] Task registered but did not start - check the Windows Event Log,
  echo           or run:  schtasks /Query /TN "SmartEPT LiveView Relay" /V
) else (
  echo    [ok] LiveView relay is running.
)

echo.
echo ============================================================
echo   DONE. You never need to run "npm start" for the relay again.
echo   It will keep running after this window closes, after you log
echo   out, and after every reboot - automatically, with no one
echo   signed in required.
echo ============================================================
pause
