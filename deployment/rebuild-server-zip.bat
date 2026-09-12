@echo off
setlocal enableextensions
title SmartEPT - Rebuild Server/Admin Setup ZIP

REM Run from anywhere; this script lives in  smartept\deployment\  and packages the
REM product (its parent folder) into the Central downloads folder the portal serves.
cd /d "%~dp0.."
set "SRC=%cd%"

REM ---- config -------------------------------------------------------------
REM The version comes from the first argument (BUILD-CLIENT-PACKAGE.bat passes it).
REM Run standalone with no argument and it is read from version.json - the same file
REM the client reports to Central. It used to default to a hard-coded 1.1, which
REM silently rebuilt old-numbered zips over the real ones (1-Sep-2026).
set "VER=%~1"
set "NAME=SmartEPT-Admin-Server"
set "STAGE=%TEMP%\%NAME%"
set "OUTDIR=C:\laragon\www\smartept-central\storage\app\downloads"
set "UPDDIR=C:\laragon\www\smartept-central\storage\app\updates"

echo ============================================================
echo   SmartEPT - Rebuild Server/Admin Setup ZIP
echo ============================================================
echo   Source : %SRC%

if not exist "%OUTDIR%" mkdir "%OUTDIR%"

REM --- locate PHP, the same way INSTALL.bat does (needed by the verify gate) ---
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found - the package cannot be verified. & pause & exit /b 1 )

REM Resolve the version BEFORE the output names are built from it.
REM
REM 8-Sep-2026: 1.2 shipped on 1-Sep and version.json still said 1.2 a week later, so this
REM script was quietly rebuilding a version clients already had. The bump lives in
REM BUILD-CLIENT-PACKAGE.bat step [6/6] and is skipped entirely when this script is run on
REM its own - which is how it is usually run. So: whoever READ the number also bumps it.
REM Called with a version argument (from BUILD-CLIENT-PACKAGE.bat), the parent still owns
REM the bump and OWNBUMP stays empty - no double increment.
set "OWNBUMP="
if "%VER%"=="" (
  set "OWNBUMP=1"
  "%PHP%" "%SRC%\deployment\version.php" read > "%TEMP%\smartept-version.txt"
  if exist "%TEMP%\smartept-version.txt" set /p VER=<"%TEMP%\smartept-version.txt"
  del "%TEMP%\smartept-version.txt" 2>nul
)
if "%VER%"=="" ( echo [ERROR] No version given and version.json could not be read. & pause & exit /b 1 )

REM Two artifacts, one staging folder:
REM   OUT    - wrapped in a folder, for a HUMAN doing a fresh install
REM   UPDOUT - FLAT, for the updater. A wrapped zip published as an update makes
REM            the client create a folder and replace nothing, reporting success.
set "OUT=%OUTDIR%\%NAME%-Setup-%VER%.zip"
set "UPDOUT=%UPDDIR%\SmartEPT-Update-%VER%.zip"
echo   Version: %VER%
echo   Output : %OUT%
echo   Update : %UPDOUT%
echo.

REM A version that has already been published must never be rebuilt with different content:
REM clients that installed it would never be offered the new one. Only checked in standalone
REM mode - BUILD-CLIENT-PACKAGE.bat names the version deliberately and may be retrying.
if defined OWNBUMP if exist "%OUT%" (
  echo [ERROR] %NAME%-Setup-%VER%.zip already exists - version %VER% has shipped.
  echo         version.json was not bumped after that build. Set it to the next number
  echo         ^(php deployment\version.php bump^) or delete that zip if it never went out.
  pause & exit /b 1
)

echo [1/6] Staging files (excluding dev-only files, caches, logs and secrets)...
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
      "%SRC%\storage\app\updates" ^
      "%SRC%\storage\app\tmp" "%SRC%\bootstrap\cache" "%SRC%\public\storage" ^
  /XF ".env" ".env.bak" ".env.backup" ".env.production" "license.lic" ".machine_fp" ^
      "licence-off.key" "auth.json" "individual" "phpunit.xml" ".phpunit.result.cache" ^
      "*.fuse_hidden*" "commit-*.txt" "commit-*.php" "commit-*.bat" "*.commit.txt" >nul
if %errorlevel% geq 8 ( echo [ERROR] Staging via robocopy failed. & pause & exit /b 1 )

REM deployment\ ships ONLY install-helper.php - INSTALL.bat and install-linux.sh both run it.
REM An ALLOW-list, not a deny-list: the first version named the six build files to delete, which
REM meant anything NEW in that folder would ship by default. A stray www.lnk turned up there the
REM same day and proved the point. Whatever lands in deployment\ from now on stays behind.
for %%f in ("%STAGE%\deployment\*") do (
  if /i not "%%~nxf"=="install-helper.php" del /f /q "%%~f" >nul 2>nul
)
for /d %%d in ("%STAGE%\deployment\*") do rmdir /s /q "%%~d" >nul 2>nul

REM keep the empty runtime folders so Laravel boots on the client
for %%d in ("storage\logs" "storage\framework\cache" "storage\framework\sessions" ^
            "storage\framework\views" "storage\app\private" "storage\app\public" ^
            "bootstrap\cache") do (
  if not exist "%STAGE%\%%~d" mkdir "%STAGE%\%%~d"
)

echo.
echo [2/6] Verifying the staged package...
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
echo [3/6] Compressing to ZIP (this can take a minute)...
REM Built with PHP's ZipArchive, not `tar`. Two separate failures made `tar` untrustworthy here:
REM   - bsdtar reads "C:\..." as host:path and dies with "Cannot connect to C: resolve failed"
REM   - the `tar` first on PATH in Cmder/Laragon is GNU tar, whose -a only understands COMPRESSION
REM     suffixes, so `-a -c -f x.zip` silently writes a plain TAR file named .zip
REM PHP is already required by this script and ext-zip already ships for phpspreadsheet, so
REM ZipArchive gives the same real zip on every machine, with no PATH roulette.
"%PHP%" "%SRC%\deployment\make-zip.php" "%STAGE%" "%OUT%" "%NAME%"
if errorlevel 1 ( echo [ERROR] ZIP was not created. See messages above. & pause & exit /b 1 )
if not exist "%OUT%" ( echo [ERROR] ZIP was not created. See messages above. & pause & exit /b 1 )

echo.
echo [4/6] Verifying the built ZIP...
REM Defence in depth: make-zip.php already reopens what it wrote, but this checks the file that
REM is actually sitting in the downloads folder under its published name, and re-runs every
REM content rule against it. What the portal serves is what gets verified.
"%PHP%" "%SRC%\deployment\verify-package.php" --zip "%OUT%"
if errorlevel 1 (
  echo.
  echo [ERROR] The BUILT ZIP failed verification.
  echo         Renaming it to .REJECTED so it cannot be served but can still be inspected.
  if exist "%OUT%.REJECTED" del /f /q "%OUT%.REJECTED"
  move /y "%OUT%" "%OUT%.REJECTED" >nul
  pause & exit /b 1
)

echo.
echo.
echo [5/6] Building the UPDATE package (flat, for "Check for Update")...
if not exist "%UPDDIR%" mkdir "%UPDDIR%"
"%PHP%" "%SRC%\deployment\make-zip.php" "%STAGE%" "%UPDOUT%" -
if errorlevel 1 (
  echo [ERROR] The update package was not created. The installer ZIP above is still valid.
  pause & exit /b 1
)

echo.
echo [6/6] Cleaning up staging...
rmdir /s /q "%STAGE%"

REM Both packages are on disk and verified, so this number is spent. Bump it now - after the
REM build, never before, so a failed build does not consume a version.
set "NEXTVER="
if defined OWNBUMP (
  "%PHP%" "%SRC%\deployment\version.php" bump > "%TEMP%\smartept-nextver.txt"
  if exist "%TEMP%\smartept-nextver.txt" set /p NEXTVER=<"%TEMP%\smartept-nextver.txt"
  del "%TEMP%\smartept-nextver.txt" 2>nul
)

for %%A in ("%OUT%") do set "SZ=%%~zA"
echo.
echo ============================================================
echo   DONE.
echo   Installer : %OUT%
echo   Size      : %SZ% bytes
echo.
echo   Update    : %UPDOUT%
echo               Already in Central's updates folder - open Upload Update,
echo               choose it under "use a file already on the server", set the
echo               version to %VER% and publish. Clients get it from the
echo               Check for Update button on their Licence screen.
echo.
if defined NEXTVER echo   version.json is now %NEXTVER% - the NEXT build. This one is %VER%.
if defined OWNBUMP if not defined NEXTVER echo   [!] version.json could NOT be bumped - edit it by hand before the next build.
if defined NEXTVER echo.
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
