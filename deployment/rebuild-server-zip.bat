@echo off
setlocal enableextensions
title SmartEPT - Rebuild Server/Admin Setup ZIP

REM Run from anywhere; this script lives in  smartept\deployment\  and packages the
REM product (its parent folder) into the Central downloads folder the portal serves.
cd /d "%~dp0.."
set "SRC=%cd%"

REM ---- config (version can be passed as the first argument, default 1.0) ----
set "VER=%~1"
if "%VER%"=="" set "VER=1.1"
set "NAME=SmartEPT-Admin-Server"
set "STAGE=%TEMP%\%NAME%"
set "OUTDIR=C:\laragon\www\smartept-central\storage\app\downloads"
set "OUT=%OUTDIR%\%NAME%-Setup-%VER%.zip"

echo ============================================================
echo   SmartEPT - Rebuild Server/Admin Setup ZIP
echo ============================================================
echo   Source : %SRC%
echo   Output : %OUT%
echo.

if not exist "%OUTDIR%" mkdir "%OUTDIR%"

echo [1/3] Staging files (excluding dev-only files, caches, logs and secrets)...
if exist "%STAGE%" rmdir /s /q "%STAGE%"
robocopy "%SRC%" "%STAGE%" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 ^
  /XD ".git" "node_modules" "_to_delete" "_cloudsync" ^
      "%SRC%\storage\logs" "%SRC%\storage\framework\cache" "%SRC%\storage\framework\sessions" ^
      "%SRC%\storage\framework\views" "%SRC%\storage\app\evidence" "%SRC%\storage\app\private" "%SRC%\storage\app\public" ^
  /XF ".env" ".env.bak" ".env.backup" "*.fuse_hidden*" "individual" "license.lic" ".machine_fp" >nul
if %errorlevel% geq 8 ( echo [ERROR] Staging (robocopy) failed. & pause & exit /b 1 )

REM keep the empty runtime folders so Laravel boots on the client
for %%d in ("storage\logs" "storage\framework\cache" "storage\framework\sessions" "storage\framework\views" "bootstrap\cache") do (
  if not exist "%STAGE%\%%~d" mkdir "%STAGE%\%%~d"
)

echo [2/3] Compressing to ZIP (this can take a minute)...
if exist "%OUT%" del /f /q "%OUT%"
where tar >nul 2>nul
if %errorlevel%==0 (
  REM Windows' built-in tar (bsdtar) handles long vendor paths reliably.
  pushd "%TEMP%"
  tar -a -c -f "%OUT%" "%NAME%"
  popd
) else (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Compress-Archive -Path '%STAGE%' -DestinationPath '%OUT%' -Force"
)
if not exist "%OUT%" ( echo [ERROR] ZIP was not created. See messages above. & pause & exit /b 1 )

echo [3/3] Cleaning up staging...
rmdir /s /q "%STAGE%"

for %%A in ("%OUT%") do set "SZ=%%~zA"
echo.
echo ============================================================
echo   DONE.
echo   File : %OUT%
echo   Size : %SZ% bytes
echo.
echo   Includes the latest product code + INSTALL.bat + vendor
echo   (so the client needs no Composer). Excludes .env, .git,
echo   logs, caches and scratch files.
echo.
echo   The Client Portal download now serves this new build - no
echo   database change needed (same file path).
echo.
echo   Installers inside the ZIP: INSTALL.bat (Windows),
echo   install-linux.sh (systemd), install-macos.sh (launchd).
echo.
echo   BEFORE WIDE DISTRIBUTION (SmartPRS2 standard): SourceGuardian-
echo   encode app\Services\LicenseFile.php + app\Http\Middleware\
echo   EnsureLicensed.php in the STAGED copy so the embedded public
echo   key and licence checks cannot be edited on the client.
echo ============================================================
pause
