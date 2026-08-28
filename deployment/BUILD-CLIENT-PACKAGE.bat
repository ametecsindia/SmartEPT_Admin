@echo off
setlocal enabledelayedexpansion
title SmartEPT - Build the complete client package

REM ===========================================================================
REM  SmartEPT - one command, one complete client package.
REM
REM      latest source  ->  build  ->  server + agent  ->  package
REM
REM  TWO things a client installs: the server, once; the agent, on each PC. The agent
REM  setup already contains the service that does the blocking - there is no third thing.
REM
REM  Produces a dated folder under C:\laragon\www\_ClientPackages containing
REM  everything an installer person needs and nothing they do not.
REM
REM  DESIGN NOTE, and the reason this script is longer than it looks like it
REM  should be: it REFUSES to package a binary that is older than its source.
REM  A stale service inside the agent installer is invisible - the client installs
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
echo   Docs    : %SRC_ENF%\docs
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
REM  2. THE POLICY SERVICE  -  inside the agent installer, not beside it
REM ===========================================================================
REM
REM  27-Aug-2026 (Ejaz): "I want the similar package for client - not with a separate
REM  enforcer. It should be Only 1 Agent setup file (all inclusive)."
REM
REM  It already is, and has been since 26-Aug. `agent\build\installer.nsh` ships the service
REM  inside the agent as `resources\service\SmartEPTAgentService.exe`, registers it, starts it,
REM  turns on AppIDSvc, adds the Start Menu escape hatch, and REMOVES the old standalone
REM  `SmartEPTEnforcer` product if it finds one. It enrols itself from a handoff token the
REM  first time an employee signs in, so there is no address and no token in the build and one
REM  file works at every client.
REM
REM  This script was still shipping the retired standalone installer in a 2-ENFORCER folder.
REM  That is worse than redundant: installing it puts back the very service the agent installer
REM  deletes, so whichever ran last won and the PC's state depended on install order. Both the
REM  folder and the INSTALL-ON-THIS-PC.bat wrapper are gone.
REM
REM  What is verified here instead: that the agent installer we are about to ship actually
REM  CONTAINS the service. An agent built from a tree with an empty build\service\ installs
REM  cleanly, reports activity, and blocks nothing - and its own .nsh says so in a message box
REM  nobody reads. Better to fail here.
echo.
echo [2/6] Checking the policy service inside the agent ...

set "SVC_EXE=%SRC_AGENT%\build\service\SmartEPTAgentService.exe"
if not exist "%SVC_EXE%" (
  echo.
  echo  [X] STOP. The policy service binary is missing from the agent tree:
  echo      %SVC_EXE%
  echo.
  echo      An agent built without it installs fine, reports activity, and blocks
  echo      NOTHING. That is the exact failure this product exists to stop shipping.
  pause & exit /b 1
)
for %%A in ("%SVC_EXE%") do echo       Service binary: %%~zA bytes, %%~tA

REM ===========================================================================
REM  3. AGENT  -  the one file that goes on an employee PC
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

REM The one that actually caught something. An agent installer older than the service binary
REM or older than installer.nsh is an installer that does not contain the current blocking
REM half - and it looks completely healthy on the PC afterwards.
call :NEWER_THAN "!AGENT_SETUP!" "%SRC_AGENT%\build" "*.*" STALE_AGENT3
if "!STALE_AGENT3!"=="1" (
  echo.
  echo  [X] STOP. The agent installer is OLDER than the policy service or installer.nsh.
  echo      It would ship without the current blocking half, install cleanly, and
  echo      block nothing.
  echo.
  echo      Rebuild with:  cd /d %SRC_AGENT%  ^&^&  build-agent.bat
  pause & exit /b 1
)
echo       OK - installer is current and contains the service.

REM ===========================================================================
REM  4. ASSEMBLE
REM ===========================================================================
echo.
echo [4/6] Assembling %PKG% ...

REM Two folders, not three. There is no separate enforcer to install any more - the agent
REM setup IS the whole employee-PC install.
if exist "%PKG%" rmdir /s /q "%PKG%"
mkdir "%PKG%\1-SERVER" 2>nul
mkdir "%PKG%\2-AGENT"  2>nul
mkdir "%PKG%\DOCS"     2>nul

copy /y "%SERVER_ZIP%"  "%PKG%\1-SERVER\" >nul      || goto :COPYFAIL
copy /y "!AGENT_SETUP!" "%PKG%\2-AGENT\"  >nul      || goto :COPYFAIL

REM --- DOCS: an ALLOW-list, for the same reason deployment\ is one ------------
REM
REM 27-Aug-2026 (Ejaz): "many other files here are old ones". He was right, and the cause was
REM the wildcard this replaces: `SmartEPT-*.md` shipped EVERYTHING in the enforcer's docs
REM folder. The 27-Aug package went out with our internal build report, session report,
REM handover note, test plan, "Monday plan", status note and the build runbook that explains
REM how we assemble the product. Eight documents no client should ever receive, and the list
REM grows by itself every time somebody writes a note in that folder.
REM
REM This is the same mistake the deployment\ loop below already learned: a deny-list means
REM anything NEW ships by default. Named files only. A document that belongs in the box gets
REM added here deliberately, by someone who decided a client should read it.
set "CLIENT_DOCS=SmartEPT-Client-Installation SmartEPT-Agent-Deployment"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Client-Guide-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Deployment-Guide-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Enforcement-Operations"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Business-Case-Client-2026-08-21"
set "CLIENT_DOCS=%CLIENT_DOCS% SmartEPT-Control-Summary-Auditor-2026-08-21"

set "DOCS_MISSING="
for %%D in (%CLIENT_DOCS%) do (
  set "GOT="
  if exist "%SRC_ENF%\docs\%%D.md"  ( copy /y "%SRC_ENF%\docs\%%D.md"  "%PKG%\DOCS\" >nul & set "GOT=1" )
  if exist "%SRC_ENF%\docs\%%D.pdf" ( copy /y "%SRC_ENF%\docs\%%D.pdf" "%PKG%\DOCS\" >nul & set "GOT=1" )
  if not defined GOT set "DOCS_MISSING=!DOCS_MISSING! %%D"
)

REM --- the customer's own manual set -----------------------------------------
REM
REM 27-Aug-2026 (Ejaz). These are the documents an actual END USER reads — the 288-page
REM Client User Manual, the quick start, the installation guide for their IT person, and the
REM printable first-day checklist. They were being written and never shipped, so every client
REM received the deployment notes and none of the manual.
REM
REM PDFs only, deliberately: the .md sources are ours and are where the next edit happens.
REM A client with both eventually reads whichever one is stale.
set "SRC_MANUAL=C:\Users\MPS\Documents\Claude\Projects\SmartEPT\docs\SmartEPT-Client-Manual-2026-08-24"
set "MANUAL_DOCS=SmartEPT-Client-User-Manual-2026-08-24 SmartEPT-Quick-Start-Guide-2026-08-24"
set "MANUAL_DOCS=%MANUAL_DOCS% SmartEPT-Agent-Installation-Guide-2026-08-24"
set "MANUAL_DOCS=%MANUAL_DOCS% SmartEPT-Admin-First-Day-Checklist-2026-08-24"

for %%D in (%MANUAL_DOCS%) do (
  if exist "%SRC_MANUAL%\%%D.pdf" (
    copy /y "%SRC_MANUAL%\%%D.pdf" "%PKG%\DOCS\" >nul
  ) else (
    set "DOCS_MISSING=!DOCS_MISSING! %%D.pdf"
  )
)

REM The INTERNAL-* files in that folder are never copied. They list 162 product defects and a
REM discovery audit; a wildcard here would put them in a client's hands, which is exactly the
REM accident the DOCS allow-list above exists to prevent.

if defined DOCS_MISSING (
  echo.
  echo  [X] These client documents are missing:
  echo     !DOCS_MISSING!
  echo.
  echo      Looked in:
  echo        %SRC_ENF%\docs          ^(the deployment documents^)
  echo        %SRC_MANUAL%   ^(the customer manual set^)
  echo.
  echo      Either write them, or take them off CLIENT_DOCS / MANUAL_DOCS in this
  echo      script. A name on those lists is a promise the client finds the file in the box.
  rmdir /s /q "%PKG%"
  pause & exit /b 1
)

REM README-FIRST goes at the ROOT, not in DOCS. Whoever opens this folder at a
REM client site reads exactly one file, and it has to be the one that says the
REM enforcer is not optional.
if exist "%SRC_ENF%\docs\README-FIRST.txt" copy /y "%SRC_ENF%\docs\README-FIRST.txt" "%PKG%\" >nul

REM Nothing else goes in the package root. There WAS an INSTALL-ON-THIS-PC.bat here that ran
REM two installers in sequence; it was removed on 27-Aug-2026 because the second of them was
REM the retired standalone enforcer, which the agent installer deletes. One file, one step.

if not exist "%PKG%\DOCS\SmartEPT-Client-Installation.md" (
  echo  [X] The installation guide is missing from %SRC_ENF%\docs.
  echo      A package without its instructions is not a deliverable.
  rmdir /s /q "%PKG%"
  REM was `pause ^& exit /b 1` - cmd un-escapes that to a literal & handed to
  REM pause, so the exit never ran and the build carried on past a fatal error.
  pause & exit /b 1
)

REM --- documents that describe a feature this build no longer has -------------
REM
REM This script already refuses to ship a binary older than its source, on the grounds that a
REM stale enforcer.exe reports healthy and enforces last week's rules. A document has the same
REM failure mode and is read by more people: "click Start learning, wait three to five days"
REM sends the client hunting for a button that was removed on 27-Aug-2026, and they conclude
REM the software is broken rather than the manual.
REM
REM Deliberately a hard stop, like the staleness gates. A doc that contradicts the product is
REM a support call with our name on it.
set "STALE_DOCS="
for %%D in (%CLIENT_DOCS%) do (
  if exist "%PKG%\DOCS\%%D.md" (
    findstr /i /c:"Start learning" "%PKG%\DOCS\%%D.md" >nul && set "STALE_DOCS=!STALE_DOCS! %%D"
  )
)
if exist "%PKG%\README-FIRST.txt" (
  findstr /i /c:"learning period" "%PKG%\README-FIRST.txt" >nul && set "STALE_DOCS=!STALE_DOCS! README-FIRST"
)

if defined STALE_DOCS (
  echo.
  echo  [X] STOP. These documents still describe the LEARNING period, which this
  echo      build does not have ^(SMARTEPT_ENFORCEMENT_LEARNING=false^):
  echo     !STALE_DOCS!
  echo.
  echo      They tell the client to press "Start learning" and wait days. There is no
  echo      such button. Enforcement is on or off.
  echo.
  echo      Update them in %SRC_ENF%\docs ^(the .md AND the .pdf^), then build again.
  echo      Nothing was written to %OUTROOT%.
  rmdir /s /q "%PKG%"
  pause & exit /b 1
)

REM ===========================================================================
REM  5. MANIFEST  - what is in the box, and what it is made of
REM ===========================================================================
echo.
echo [5/6] Writing the manifest ...

REM Written by PowerShell, not a cmd loop. A `goto` label inside a
REM parenthesised for-block does not work in cmd - the first version of this
REM silently produced a manifest with one entry, which is worse than none.
REM
REM 27-Aug-2026: it lived here as an inline -Command string and broke
REM ("Missing expression after ','" / "'Every' is not recognized"). A multi-line
REM PowerShell literal inside a .bat needs a trailing ^ on EVERY line, and `^|`
REM inside double quotes is passed through to PowerShell verbatim rather than
REM escaped. Both faults are cmd quoting, not logic, so the script moved to its
REM own file where cmd has nothing to escape.
powershell -NoProfile -ExecutionPolicy Bypass -File "%SRC%\deployment\write-manifest.ps1" -Package "%PKG%" -Stamp "%STAMP%"

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
echo   2-AGENT     ONE file. The employee agent AND the service that blocks,
echo               in a single setup. It enrols itself at first sign-in.
echo   DOCS        the customer manual set + the deployment guides
echo   MANIFEST    every file with its checksum
echo.
echo   Two steps at a client site:
echo     1. On the server: unzip 1-SERVER and run INSTALL.bat
echo     2. On each PC:    run the setup in 2-AGENT. Next, Finish. Nothing else.
echo.
echo   Check the FIRST PC before rolling out:
echo     "C:\Program Files\SmartEPT Agent\resources\service\SmartEPTAgentService.exe" -status
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
