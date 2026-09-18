@echo off
setlocal enabledelayedexpansion
title SmartEPT - Install / Setup the Admin Console
cd /d "%~dp0"

echo ============================================================
echo   SmartEPT Admin Console  -  First-time Install / Repair
echo ============================================================
echo.

REM --- locate PHP (Laragon) ---
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found. Install and start Laragon first. & pause & exit /b 1 )
echo PHP:  %PHP%

REM --- composer only needed if dependencies are missing ---
set "NEED_COMPOSER="
if not exist "vendor\autoload.php" set "NEED_COMPOSER=1"

echo.
echo [1/9] Preparing environment (.env) and database...
"%PHP%" deployment\install-helper.php
if errorlevel 1 ( echo. & echo [ERROR] Environment/database step failed - see messages above. & pause & exit /b 1 )

echo.
if defined NEED_COMPOSER (
  echo [2/9] Installing PHP dependencies with Composer...
  where composer >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] The 'vendor' folder is missing and Composer was not found.
    echo         Install Composer, or use a build that already includes 'vendor'.
    pause & exit /b 1
  )
  call composer install --no-dev --optimize-autoloader
  if errorlevel 1 ( echo [ERROR] composer install failed - see messages above. & pause & exit /b 1 )
) else (
  echo [2/9] Dependencies present ^(vendor found^) - skipping Composer.
)

echo.
echo [3/9] Generating application key...
"%PHP%" artisan key:generate --force

echo.
echo [4/9] Creating database tables (migrate)...
"%PHP%" artisan migrate --force
if errorlevel 1 ( echo. & echo [ERROR] Migration failed - see messages above. & pause & exit /b 1 )

echo.
echo [5/9] Seeding roles ^& permissions...
"%PHP%" artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force

echo.
echo [6/9] Linking storage ^& clearing caches...
"%PHP%" artisan storage:link >nul 2>nul
"%PHP%" artisan optimize:clear

echo.
echo [7/9] Creating your company's admin login (clean workspace - no demo data)...
set "ADMIN_EMAIL="
set /p ADMIN_EMAIL="   Admin email (e.g. admin@yourcompany.com): "
if "%ADMIN_EMAIL%"=="" set "ADMIN_EMAIL=admin@smartept.local"
set "ADMIN_COMPANY="
set /p ADMIN_COMPANY="   Company name (e.g. Your Company Pvt Ltd): "
set "ADMIN_PASS="
set /p ADMIN_PASS="   Admin password (min 8 chars; blank = generate a temporary one): "
if "%ADMIN_PASS%"=="" (
  "%PHP%" artisan smartept:make-admin "%ADMIN_EMAIL%" --company="%ADMIN_COMPANY%"
) else (
  "%PHP%" artisan smartept:client-provision --company="%ADMIN_COMPANY%" --email="%ADMIN_EMAIL%" --password="%ADMIN_PASS%"
)

echo.
echo [8/9] Registering the background scheduler (every minute)...
REM ---------------------------------------------------------------------------
REM  2-Sep-2026. This was step 12 of INSTALL-GUIDE.md - a command a human had to
REM  type - and it was being skipped, silently, on real installs. Everything the
REM  product does in the background runs through `artisan schedule:run`: post-shift
REM  auto sign-out, meeting auto-close, biometric auto-sync, the nightly attendance
REM  sheet, licence phone-home, backups, the retention purge. With no task, ALL of
REM  them are dead while every screen still looks correct - which is exactly how
REM  "auto sign-out does not work" was reported three times.
REM
REM  /F overwrites an existing task, so re-running INSTALL.bat is safe and repairs
REM  a task pointing at an old PHP path. Needs an administrator prompt (the guide
REM  already says to use one); if it fails the install still succeeds and the line
REM  below tells the admin what to run.
REM ---------------------------------------------------------------------------
schtasks /Create /F /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"%PHP%\" \"%CD%\artisan\" schedule:run" >nul 2>nul
if errorlevel 1 (
  echo    [WARN] Could not create the scheduled task - are you in an ADMINISTRATOR prompt?
  echo           Background jobs ^(auto sign-out, biometric sync, nightly attendance^) will
  echo           NOT run until it exists. Re-run INSTALL.bat as administrator, or run:
  echo.
  echo           schtasks /Create /F /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"%PHP%\" \"%CD%\artisan\" schedule:run"
  echo.
) else (
  echo    [ok] Scheduled task "SmartEPT Scheduler" created - runs every minute as SYSTEM.
)

echo.
echo [9/9] Setting up the LiveView relay (background startup task)...
REM ---------------------------------------------------------------------------
REM  14-Sep-2026, rebuilt as a real production step (no Node.js dependency)
REM  15-Sep-2026: the relay used to need a person to "npm start" it in a
REM  terminal window forever, then (one step better) Node.js installed on the
REM  server just to run it as a service. Both are gone now: relay\dist\
REM  smartept-relay-win.exe is a SELF-CONTAINED compiled binary (built with
REM  `npm run build` from relay\server.js — see relay\package.json) with no
REM  Node.js/npm dependency on THIS machine at all.
REM
REM  15-Sep-2026 (later same day): this originally registered the exe as a
REM  Windows SERVICE via sc.exe, the same way the Agent's own enforcement
REM  service does (agent\build\installer.nsh). That was wrong: the Agent's
REM  service is a Go binary that implements the Service Control Manager
REM  protocol; this relay is a plain pkg-compiled Node console app that
REM  doesn't. sc.exe create succeeds, but sc.exe start then fails with error
REM  1053 every single time — found on a real install, would have silently
REM  shipped broken (LiveView never actually running) to every future client,
REM  since the old code swallowed sc.exe start's exit code. A Scheduled Task
REM  has no such requirement — it just launches a process — so that's what's
REM  used now: launches relay\daemon\relay-watchdog.vbs at boot, as SYSTEM
REM  (no login required), which is what actually relaunches the exe and
REM  restarts it if it ever crashes.
REM  The shared secret is copied straight out of the .env APP_KEY this install
REM  just generated. A missing binary is a WARN, not a failure: LiveView is
REM  optional, everything else in SmartEPT works without it.
REM
REM  15-Sep-2026 (later still): also opens the Windows Firewall for the
REM  relay's port (read from .env's RELAY_PORT, default 8098, never hard-coded).
REM  A SYSTEM-run background task has no interactive session to click an
REM  "Allow access" prompt, so an Agent on a genuinely different PC could reach
REM  this server for login/heartbeat but never reach the relay port itself -
REM  LiveView worked only when the browser/Agent happened to share the relay's
REM  own machine. Found on a real two-PC test the same day.
REM ---------------------------------------------------------------------------
if not exist "relay\dist\smartept-relay-win.exe" (
  echo    [WARN] relay\dist\smartept-relay-win.exe not found - LiveView will not be available.
  echo           This ships pre-built as part of the install package. If you're building it
  echo           yourself, run once from the relay folder:  npm install ^&^& npm run build
) else (
  for /f "tokens=1,* delims==" %%a in ('findstr /b "APP_KEY=" .env') do (
    >relay\relay-secret.txt echo %%b
    REM The compiled binary resolves its own folder (dist\) as APP_DIR, not
    REM relay\ - it never sees relay\relay-secret.txt, only a copy sitting
    REM next to the exe itself. Without this line a fresh install's relay
    REM refuses every connection with "RELAY_SECRET is not set" (found + fixed
    REM 15-Sep-2026).
    >relay\dist\relay-secret.txt echo %%b
  )

  echo.
  echo    HTTPS for LiveView ^(optional - needed only if this site's admin
  echo    console is opened over https://^) ...
  REM 18-Sep-2026: browsers refuse an insecure ws:// socket from an https:// page -
  REM found on a real client install. Rather than every client hand-editing a
  REM reverse-proxy config, the relay speaks TLS itself when told which certificate
  REM to use - asked ONCE, here, same as the admin email/company questions in step
  REM [7/9] above. Blank = skip = plain ws:// (today's behaviour, no change, nothing
  REM breaks). This whole step runs inside the "else" block opened above, which is
  REM parsed as one unit before any of it executes - so, same as RELAY_PORT further
  REM down, every variable set AND read in here must use !VAR!, not %VAR%.
  if exist "relay\relay-tls-cert-path.txt" (
    echo    Already configured - press Enter on both lines below to keep it, or
    echo    paste new paths to replace it.
  )
  set "RELAY_TLS_CERT="
  set /p RELAY_TLS_CERT="   Certificate file for this site's HTTPS (blank = skip, use ws://): "
  if not "!RELAY_TLS_CERT!"=="" (
    if exist "!RELAY_TLS_CERT!" (
      set "RELAY_TLS_KEY="
      set /p RELAY_TLS_KEY="   Matching private key file: "
      if exist "!RELAY_TLS_KEY!" (
        >relay\relay-tls-cert-path.txt echo !RELAY_TLS_CERT!
        >relay\dist\relay-tls-cert-path.txt echo !RELAY_TLS_CERT!
        >relay\relay-tls-key-path.txt echo !RELAY_TLS_KEY!
        >relay\dist\relay-tls-key-path.txt echo !RELAY_TLS_KEY!
        echo    [ok] LiveView will use wss:// - same certificate as the main site.
      ) else (
        echo    [WARN] Key file not found - LiveView will use ws:// for now.
      )
    ) else (
      echo    [WARN] Certificate file not found - LiveView will use ws:// for now.
    )
  ) else (
    if exist "relay\relay-tls-cert-path.txt" (
      echo    [ok] Keeping the existing certificate - still wss://.
    ) else (
      echo    [ok] Skipped - LiveView uses ws:// ^(fine for http:// / LAN-only sites^).
    )
  )
  echo.

  REM Always remove any previous registration then recreate — an install from
  REM before 15-Sep-2026 may have the old node-windows daemon, or the
  REM short-lived sc.exe SERVICE attempt, registered under this same name.
  REM Errors here are expected and harmless on a brand-new install.
  taskkill /F /IM smartept-relay-win.exe >nul 2>nul
  sc.exe stop "SmartEPT LiveView Relay" >nul 2>nul
  sc.exe delete "SmartEPT LiveView Relay" >nul 2>nul
  schtasks /Delete /F /TN "SmartEPT LiveView Relay" >nul 2>nul
  schtasks /Create /F /TN "SmartEPT LiveView Relay" /SC ONSTART /RU SYSTEM /RL HIGHEST /TR "\"%WINDIR%\System32\wscript.exe\" \"%CD%\relay\daemon\relay-watchdog.vbs\"" >nul 2>nul
  set "SVC_RC=%errorlevel%"
  if not "%SVC_RC%"=="0" (
    echo    [WARN] Could not install the relay startup task - are you in an ADMINISTRATOR prompt?
    echo           Re-run INSTALL.bat as administrator, or from an admin prompt run:
    echo           INSTALL-LIVEVIEW-RELAY.bat
  ) else (
    schtasks /Run /TN "SmartEPT LiveView Relay" >nul 2>nul
    set "RELAY_PORT=8098"
    for /f "tokens=1,* delims==" %%a in ('findstr /b "RELAY_PORT=" .env') do set "RELAY_PORT=%%b"
    REM enabledelayedexpansion is active in this script, and this is inside a
    REM parenthesized block, so %RELAY_PORT% here would expand at parse time
    REM (before the "set" above ever runs) - must use !RELAY_PORT! instead.
    netsh advfirewall firewall delete rule name="SmartEPT LiveView Relay" >nul 2>nul
    netsh advfirewall firewall add rule name="SmartEPT LiveView Relay" dir=in action=allow protocol=TCP localport=!RELAY_PORT! >nul 2>nul
    echo    [ok] LiveView relay installed as a startup task and firewall-opened on port !RELAY_PORT! - starts automatically on every boot.
  )
)

echo.
echo ============================================================
echo   INSTALL COMPLETE.
echo.
echo   Open the console in a browser:
echo       http://smartept.test/admin
echo     (or  https://your-server-address/admin  in production)
echo.
echo   Sign in with the admin email + password from the step above.
echo.
echo   LICENSING: the 7-day evaluation is running. To license, open
echo   /activate  -  copy the machine fingerprint, send it to Ametecs,
echo   then upload the .lic file you receive. Instant, fully offline.
echo.
echo   If a page shows an error, run  cache.bat  then try again, and use
echo   Help ^> Application log inside the console to copy details for support.
echo ============================================================
pause
