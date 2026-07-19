@echo off
setlocal
title SmartEPT - Clear cache
cd /d "%~dp0"
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found under C:\laragon\bin\php & pause & exit /b 1 )
echo App:  %~dp0
echo PHP:  %PHP%
echo.
"%PHP%" artisan optimize:clear
echo.
echo ===== Done. =====
pause
