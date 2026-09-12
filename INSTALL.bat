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
echo [1/8] Preparing environment (.env) and database...
"%PHP%" deployment\install-helper.php
if errorlevel 1 ( echo. & echo [ERROR] Environment/database step failed - see messages above. & pause & exit /b 1 )

echo.
if defined NEED_COMPOSER (
  echo [2/8] Installing PHP dependencies with Composer...
  where composer >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] The 'vendor' folder is missing and Composer was not found.
    echo         Install Composer, or use a build that already includes 'vendor'.
    pause & exit /b 1
  )
  call composer install --no-dev --optimize-autoloader
  if errorlevel 1 ( echo [ERROR] composer install failed - see messages above. & pause & exit /b 1 )
) else (
  echo [2/8] Dependencies present ^(vendor found^) - skipping Composer.
)

echo.
echo [3/8] Generating application key...
"%PHP%" artisan key:generate --force

echo.
echo [4/8] Creating database tables (migrate)...
"%PHP%" artisan migrate --force
if errorlevel 1 ( echo. & echo [ERROR] Migration failed - see messages above. & pause & exit /b 1 )

echo.
echo [5/8] Seeding roles ^& permissions...
"%PHP%" artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force

echo.
echo [6/8] Linking storage ^& clearing caches...
"%PHP%" artisan storage:link >nul 2>nul
"%PHP%" artisan optimize:clear

echo.
echo [7/8] Creating your company's admin login (clean workspace - no demo data)...
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
echo [8/8] Registering the background scheduler (every minute)...
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
