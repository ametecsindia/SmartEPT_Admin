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

REM --- locate PHP, the same way INSTALL.bat does (needed by the verify gate) ---
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found - the package cannot be verified. & pause & exit /b 1 )

echo [1/5] Staging files (excluding dev-only files, caches, logs and secrets)...
if exist "%STAGE%" rmdir /s /q "%STAGE%"
REM 19-Aug-2026: the old exclusion list let our own docs\, tests\, DB backups, stored
REM screenshots, employee archive ZIPs, build-machine bootstrap caches, commit scratch files
REM and .machine_fp ship to every on-prem client. This list is kept in step with the SKIP_*
REM lists in deployment\make-clientside.php - change both together.
REM Bare names in /XD match at ANY depth, so they are used ONLY where a hit inside vendor would
REM also be correct. .github / .idea / .vscode / tests / docs are full paths on purpose: bare
REM names stripped .github out of vendor packages too, which the verifier then (rightly) reported
REM as an incomplete vendor tree.
robocopy "%SRC%" "%STAGE%" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 ^
  /XD ".git" "node_modules" "_to_delete" "_cloudsync" ^
      "%SRC%\.github" "%SRC%\.idea" "%SRC%\.vscode" ^
      "%SRC%\tests" "%SRC%\docs" "%SRC%\individual" ^
      "%SRC%\storage\logs" "%SRC%\storage\framework\cache" "%SRC%\storage\framework\sessions" ^
      "%SRC%\storage\framework\views" "%SRC%\storage\app\backups" "%SRC%\storage\app\smartept" ^
      "%SRC%\storage\app\evidence" "%SRC%\storage\app\private" "%SRC%\storage\app\public" ^
      "%SRC%\storage\app\tmp" "%SRC%\bootstrap\cache" "%SRC%\public\storage" ^
  /XF ".env" ".env.bak" ".env.backup" ".env.production" "license.lic" ".machine_fp" ^
      "licence-off.key" "auth.json" "individual" "phpunit.xml" ".phpunit.result.cache" ^
      "*.fuse_hidden*" "commit-*.txt" "commit-*.php" "commit-*.bat" "*.commit.txt" >nul
if %errorlevel% geq 8 ( echo [ERROR] Staging via robocopy failed. & pause & exit /b 1 )

REM deployment\ ships ONLY install-helper.php - INSTALL.bat and install-linux.sh both run it.
REM Everything else in there is our build tooling and internal notes.
for %%f in ("rebuild-server-zip.bat" "RUN-BUILD-LOGGED.bat" "make-clientside.php" ^
            "verify-package.php" "build-log.txt" "INSTALL-GUIDE.md") do (
  if exist "%STAGE%\deployment\%%~f" del /f /q "%STAGE%\deployment\%%~f"
)

REM keep the empty runtime folders so Laravel boots on the client
for %%d in ("storage\logs" "storage\framework\cache" "storage\framework\sessions" ^
            "storage\framework\views" "storage\app\private" "storage\app\public" ^
            "bootstrap\cache") do (
  if not exist "%STAGE%\%%~d" mkdir "%STAGE%\%%~d"
)

echo.
echo [2/5] Verifying the staged package...
REM Runs from SRC because the verifier is deliberately not staged. A damaged vendor tree or a
REM confidential file in the staging area stops the build HERE, not at a client's install.
"%PHP%" "%SRC%\deployment\verify-package.php" --dist "%STAGE%"
if errorlevel 1 (
  echo.
  echo [ERROR] The staged package failed verification - see the list above.
  echo         Nothing was written to %OUTDIR%.
  pause & exit /b 1
)

echo.
echo [3/5] Compressing to ZIP (this can take a minute)...
if exist "%OUT%" del /f /q "%OUT%"
set "TMPZIP=%TEMP%\%NAME%-Setup-%VER%.zip"
if exist "%TMPZIP%" del /f /q "%TMPZIP%"

REM 19-Aug-2026: `tar -a -c -f "C:\...\out.zip"` fails with "Cannot connect to C: resolve
REM failed" - bsdtar reads any argument containing a colon as host:path (scp style), and it has
REM no --force-local. So build the archive under %TEMP% with a RELATIVE name (no colon anywhere)
REM and move the finished file into the downloads folder afterwards.
where tar >nul 2>nul
if %errorlevel%==0 (
  REM Windows built-in tar - bsdtar - handles long vendor paths reliably.
  pushd "%TEMP%"
  tar -a -c -f "%NAME%-Setup-%VER%.zip" "%NAME%"
  popd
)
if not exist "%TMPZIP%" (
  echo    tar produced no archive - falling back to PowerShell Compress-Archive...
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Compress-Archive -Path '%STAGE%' -DestinationPath '%TMPZIP%' -Force"
)
if not exist "%TMPZIP%" ( echo [ERROR] ZIP was not created. See messages above. & pause & exit /b 1 )
move /y "%TMPZIP%" "%OUT%" >nul
if not exist "%OUT%" ( echo [ERROR] Could not move the ZIP into %OUTDIR%. & pause & exit /b 1 )

echo.
echo [4/5] Verifying the built ZIP...
REM Proves the archive is a REAL zip, not a tar that merely ends in .zip - GNU tar's -a only
REM understands compression suffixes, so on a machine where `tar` is GNU rather than the Windows
REM bsdtar this step is the only thing standing between that mistake and a client download.
REM It also re-runs every content check against the artefact that will actually be published.
"%PHP%" "%SRC%\deployment\verify-package.php" --zip "%OUT%"
if errorlevel 1 (
  echo.
  echo [ERROR] The BUILT ZIP failed verification - deleting it so it cannot be served.
  del /f /q "%OUT%"
  pause & exit /b 1
)

echo.
echo [5/5] Cleaning up staging...
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
