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
echo [1/7] Preparing environment (.env) and database...
"%PHP%" deployment\install-helper.php
if errorlevel 1 ( echo. & echo [ERROR] Environment/database step failed - see messages above. & pause & exit /b 1 )

echo.
if defined NEED_COMPOSER (
  echo [2/7] Installing PHP dependencies with Composer...
  where composer >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] The 'vendor' folder is missing and Composer was not found.
    echo         Install Composer, or use a build that already includes 'vendor'.
    pause & exit /b 1
  )
  call composer install --no-dev --optimize-autoloader
  if errorlevel 1 ( echo [ERROR] composer install failed - see messages above. & pause & exit /b 1 )
) else (
  echo [2/7] Dependencies present ^(vendor found^) - skipping Composer.
)

echo.
echo [3/7] Generating application key...
"%PHP%" artisan key:generate --force

echo.
echo [4/7] Creating database tables (migrate)...
"%PHP%" artisan migrate --force
if errorlevel 1 ( echo. & echo [ERROR] Migration failed - see messages above. & pause & exit /b 1 )

echo.
echo [5/7] Seeding roles ^& permissions...
"%PHP%" artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force

echo.
echo [6/7] Linking storage ^& clearing caches...
"%PHP%" artisan storage:link >nul 2>nul
"%PHP%" artisan optimize:clear

echo.
echo [7/7] Creating the first admin login...
set "ADMIN_EMAIL="
set /p ADMIN_EMAIL="   Admin email (e.g. admin@yourcompany.com): "
if "%ADMIN_EMAIL%"=="" set "ADMIN_EMAIL=admin@smartept.local"
set "ADMIN_COMPANY="
set /p ADMIN_COMPANY="   Company name (e.g. Your Company Pvt Ltd): "
"%PHP%" artisan smartept:make-admin "%ADMIN_EMAIL%" --company="%ADMIN_COMPANY%"

echo.
echo ============================================================
echo   INSTALL COMPLETE.
echo.
echo   Open the console in a browser:
echo       http://smartept.test/admin
echo     (or  https://your-server-address/admin  in production)
echo.
echo   Sign in with the email + temporary password shown just above.
echo   You'll be asked to set a new password on first login.
echo.
echo   If a page shows an error, run  cache.bat  then try again, and use
echo   Help ^> Application log inside the console to copy details for support.
echo ============================================================
pause
