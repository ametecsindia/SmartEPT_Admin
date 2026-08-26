@echo off
setlocal enabledelayedexpansion
title SmartEPT - Build the complete client package

REM ===========================================================================
REM  SmartEPT - one command, one complete client package.
REM
REM      latest source  ->  build  ->  server + enforcer + agent  ->  package
REM
REM  Produces a dated folder under C:\laragon\www\_ClientPackages containing
REM  everything an installer person needs and nothing they do not.
REM
REM  DESIGN NOTE, and the reason this script is longer than it looks like it
REM  should be: it REFUSES to package a binary that is older than its source.
REM  A stale enforcer.exe inside a package is invisible - the client installs
REM  it, it runs, it reports healthy, and it enforces last week's rules. That
REM  is the same class of failure as a policy that says "applied" and blocks
REM  nothing, and it is the one this whole product exists to stop shipping.
REM ===========================================================================

cd /d "%~dp0.."
set "SRC=%cd%"

set "SRC_ENF=C:\laragon\www\smartept-enforcer"
set "SRC_AGENT=C:\Users\MPS\Documents\Claude\Projects\SmartEPT\agent"
set "OUTROOT=C:\laragon\www\_ClientPackages"

REM Date stamp as YYYY-MM-DD, locale-independent (the "date" command's format
REM changes with Windows region settings and has produced folders called
REM 22-08-2026 and 08/22/2026 on the same estate).
for /f "usebackq tokens=*" %%D in (`powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"`) do set "STAMP=%%D"
if not defined STAMP set "STAMP=undated"

set "PKG=%OUTROOT%\SmartEPT-Client-Package-%STAMP%"

echo ===============================================================
echo   SmartEPT - BUILD CLIENT PACKAGE
echo ===============================================================
echo   Server  : %SRC%
echo   Enforcer: %SRC_ENF%
echo   Agent   : %SRC_AGENT%
echo   Output  : %PKG%
echo.

REM ---------------------------------------------------------------
REM  0. Locate PHP the same way INSTALL.bat does.
REM ---------------------------------------------------------------
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /d %%p in ("C:\laragon\bin\php\php*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP (
  echo  [X] PHP not found. This script needs it to build and verify the server ZIP.
  pause & exit /b 1
)

if not exist "%SRC%\artisan" ( echo  [X] %SRC% is not the SmartEPT server. & pause & exit /b 1 )
if not exist "%SRC%\vendor\autoload.php" ( echo  [X] vendor is missing. Run: composer install --no-dev & pause & exit /b 1 )

REM ===========================================================================
REM  1. SERVER
REM ===========================================================================
echo [1/6] Building the server package ...
echo.
call "%SRC%\deployment\rebuild-server-zip.bat" 1.1
if errorlevel 1 (
  echo.
  echo  [X] The server package failed to build. Nothing else was produced.
  pause & exit /b 1
)

set "SERVER_ZIP=C:\laragon\www\smartept-central\storage\app\downloads\SmartEPT-Admin-Server-Setup-1.1.zip"
if not exist "%SERVER_ZIP%" (
  echo  [X] Expected %SERVER_ZIP% and it is not there.
  pause & exit /b 1
)

REM ===========================================================================
REM  2. ENFORCER  - the component that actually blocks anything
REM ===========================================================================
echo.
echo [2/6] Checking the enforcement service ...

set "ENF_EXE=%SRC_ENF%\smarteptsvc.exe"
set "ENF_SETUP=%SRC_ENF%\installer\SmartEPT-Enforcer-Test-Setup.exe"

REM Rebuild from source when Go is available. When it is not, the committed
REM binary is used - but only after the staleness gate below agrees it is not
REM older than the code it claims to be.
where go >nul 2>nul
if %errorlevel%==0 (
  echo       Go found - rebuilding from source.
  pushd "%SRC_ENF%"
  go test ./... || ( echo  [X] Enforcer tests FAILED. Not packaging a build that does not pass its own tests. & popd & pause & exit /b 1 )
  go build -trimpath -ldflags="-s -w" -o smarteptsvc.exe ./cmd/smarteptsvc || ( echo  [X] Enforcer build failed. & popd & pause & exit /b 1 )
  popd
  where makensis >nul 2>nul
  if !errorlevel!==0 (
    pushd "%SRC_ENF%\installer"
    copy /y "..\smarteptsvc.exe" . >nul
    makensis setup.nsi >nul || ( echo  [X] Enforcer installer build failed. & popd & pause & exit /b 1 )
    del /q smarteptsvc.exe >nul 2>nul
    popd
  ) else (
    echo       NSIS not found - keeping the existing installer EXE.
  )
) else (
  echo       Go not installed - using the committed binaries.
)

if not exist "%ENF_EXE%"   ( echo  [X] %ENF_EXE% is missing.   & pause & exit /b 1 )
if not exist "%ENF_SETUP%" ( echo  [X] %ENF_SETUP% is missing. & pause & exit /b 1 )

REM --- staleness gate -------------------------------------------------------
REM  Is any .go file newer than the installer we are about to ship?
call :NEWER_THAN "%ENF_SETUP%" "%SRC_ENF%" "*.go" STALE_ENF
if "!STALE_ENF!"=="1" (
  echo.
  echo  [X] STOP. Enforcer source is NEWER than the installer.
  echo      %ENF_SETUP%
  echo      would ship code that is not what is in smartept-enforcer right now.
  echo.
  echo      Rebuild it first ^(needs Go + NSIS^), or ask for a fresh build.
  echo      Nothing was written to %OUTROOT%.
  pause & exit /b 1
)
echo       OK - installer is current.

REM ===========================================================================
REM  3. AGENT
REM ===========================================================================
echo.
echo [3/6] Checking the employee agent ...

set "AGENT_SETUP="
for %%F in ("%SRC_AGENT%\dist\SmartEPT Agent Setup *.exe") do set "AGENT_SETUP=%%F"
if not defined AGENT_SETUP (
  echo  [X] No agent installer in %SRC_AGENT%\dist
  echo      Build it with:  npm run dist    ^(needs Developer Mode ON^)
  pause & exit /b 1
)
echo       Using: !AGENT_SETUP!

call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%\src" "*.js" STALE_AGENT
if "!STALE_AGENT!"=="1" (
  echo.
  echo  [X] STOP. Agent source is NEWER than the installer.
  echo      Rebuild with:  cd /d %SRC_AGENT%  ^&^&  npm run dist
  pause & exit /b 1
)
call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%" "main.js" STALE_AGENT2
if "!STALE_AGENT2!"=="1" (
  echo.
  echo  [X] STOP. main.js is NEWER than the agent installer. Rebuild it.
  pause & exit /b 1
)
echo       OK - installer is current.

REM ===========================================================================
REM  4. ASSEMBLE
REM ===========================================================================
echo.
echo [4/6] Assembling %PKG% ...

if exist "%PKG%" rmdir /s /q "%PKG%"
mkdir "%PKG%\1-SERVER"   2>nul
mkdir "%PKG%\2-ENFORCER" 2>nul
mkdir "%PKG%\3-AGENT"    2>nul
mkdir "%PKG%\DOCS"       2>nul

copy /y "%SERVER_ZIP%" "%PKG%\1-SERVER\" >nul       || goto :COPYFAIL
copy /y "%ENF_SETUP%"  "%PKG%\2-ENFORCER\" >nul     || goto :COPYFAIL
copy /y "%ENF_EXE%"    "%PKG%\2-ENFORCER\" >nul     || goto :COPYFAIL
copy /y "!AGENT_SETUP!" "%PKG%\3-AGENT\" >nul       || goto :COPYFAIL

for %%F in ("%SRC_ENF%\docs\SmartEPT-*.md" "%SRC_ENF%\docs\SmartEPT-*.pdf") do (
  if exist "%%~F" copy /y "%%~F" "%PKG%\DOCS\" >nul
)

REM README-FIRST goes at the ROOT, not in DOCS. Whoever opens this folder at a
REM client site reads exactly one file, and it has to be the one that says the
REM enforcer is not optional.
if exist "%SRC_ENF%\docs\README-FIRST.txt" copy /y "%SRC_ENF%\docs\README-FIRST.txt" "%PKG%\" >nul

if not exist "%PKG%\DOCS\SmartEPT-Client-Installation.md" (
  echo  [X] The installation guide is missing from %SRC_ENF%\docs.
  echo      A package without its instructions is not a deliverable.
  rmdir /s /q "%PKG%"
  pause ^& exit /b 1
)

REM ===========================================================================
REM  5. MANIFEST  - what is in the box, and what it is made of
REM ===========================================================================
echo.
echo [5/6] Writing the manifest ...

REM Written by PowerShell, not a cmd loop. A `goto` label inside a
REM parenthesised for-block does not work in cmd - the first version of this
REM silently produced a manifest with one entry, which is worse than none.
powershell -NoProfile -Command ^
  "$pkg='%PKG%';" ^
  "$out=Join-Path $pkg 'MANIFEST.txt';" ^
  "$lines=@('SmartEPT client package','Built %STAMP% on %COMPUTERNAME%','',
             'Every file below with its SHA256. Verify one on the client with:',
             '    certutil -hashfile <file> SHA256','');" ^
  "Get-ChildItem -LiteralPath $pkg -Recurse -File ^| Where-Object { $_.Name -ne 'MANIFEST.txt' } ^| Sort-Object FullName ^| ForEach-Object {" ^
  "  $rel=$_.FullName.Substring($pkg.Length+1);" ^
  "  $h=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLower();" ^
  "  $lines += $rel; $lines += ('    {0:N0} bytes' -f $_.Length); $lines += ('    '+$h); $lines += '' };" ^
  "Set-Content -LiteralPath $out -Value $lines -Encoding UTF8"

if not exist "%PKG%\MANIFEST.txt" (
  echo  [X] The manifest was not written. A package nobody can verify is not shippable.
  pause & exit /b 1
)

REM ===========================================================================
REM  6. DONE
REM ===========================================================================
echo.
echo [6/6] Done.
echo.
echo ===============================================================
echo   PACKAGE READY
echo   %PKG%
echo.
echo   1-SERVER    the Laravel app + vendor + INSTALL.bat
echo   2-ENFORCER  the service that actually blocks. REQUIRED.
echo   3-AGENT     the employee client
echo   DOCS        installation and deployment guides
echo   MANIFEST    every file with its checksum
echo.
echo   Install order on a client site:  SERVER, then AGENT, then
echo   ENFORCER on each PC. See DOCS\SmartEPT-Client-Installation.md
echo ===============================================================
start "" "%PKG%"
pause
exit /b 0

:COPYFAIL
echo  [X] A component could not be copied. The package is incomplete and has been removed.
if exist "%PKG%" rmdir /s /q "%PKG%"
pause & exit /b 1

REM ---------------------------------------------------------------------------
REM  :NEWER_THAN <builtFile> <sourceDir> <pattern> <resultVar>
REM
REM  Sets <resultVar> to 1 when any file matching <pattern> under <sourceDir>
REM  is newer than <builtFile>.
REM
REM  Uses PowerShell rather than comparing "date" strings: cmd has no date
REM  arithmetic, and every hand-rolled attempt at it in this codebase has
REM  eventually got a month boundary wrong. One line of PowerShell is boring
REM  and correct.
REM ---------------------------------------------------------------------------
:NEWER_THAN
setlocal
set "BUILT=%~1"
set "DIR=%~2"
set "PAT=%~3"
set "RES=0"
if exist "%DIR%" (
  for /f "usebackq tokens=*" %%R in (`powershell -NoProfile -Command ^
    "$b=(Get-Item -LiteralPath '%BUILT%').LastWriteTimeUtc;" ^
    "$n=Get-ChildItem -LiteralPath '%DIR%' -Filter '%PAT%' -Recurse -File -ErrorAction SilentlyContinue ^| Where-Object { $_.FullName -notmatch '\\(node_modules^|dist^|vendor^|\.git)\\' } ^| Sort-Object LastWriteTimeUtc -Descending ^| Select-Object -First 1;" ^
    "if ($n -and $n.LastWriteTimeUtc -gt $b) { '1' } else { '0' }"`) do set "RES=%%R"
)
endlocal & set "%~4=%RES%"
exit /b 0
