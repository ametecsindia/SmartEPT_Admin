@echo off
setlocal
title SmartEPT - Build with full log
cd /d "%~dp0"
echo ============================================================
echo   Running the package build with FULL LOG capture.
echo   This window stays open. The build can take several
echo   minutes (vendor has thousands of files) - please wait.
echo ============================================================
call rebuild-server-zip.bat 1.1 < nul > build-log.txt 2>&1
echo.
echo Build finished (or stopped). Opening the log...
start notepad "%~dp0build-log.txt"
echo Log file: %~dp0build-log.txt
pause
