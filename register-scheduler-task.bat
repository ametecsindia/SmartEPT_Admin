@echo off
:: Registers the SmartEPT background scheduler as a Windows Scheduled Task on
:: THIS machine. Fixes "auto sign-out / meeting auto-close / biometric sync /
:: nightly reports don't run" when `php artisan smartept:why-no-signout`
:: shows link [1] BACKGROUND SCHEDULER - NOT RUNNING.
::
:: Self-elevates (UAC prompt) because /RU SYSTEM requires Administrator.
:: Safe to double-click directly - no need to open a terminal yourself.

net session >nul 2>&1
if %errorLevel% neq 0 (
    echo This needs Administrator access - relaunching with a prompt...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo Registering "SmartEPT Scheduler" task...
schtasks /Create /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM /TR "\"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe\" \"C:\laragon\www\smartept\artisan\" schedule:run" /F

echo.
echo Verifying...
schtasks /Query /TN "SmartEPT Scheduler"

echo.
echo Done. Wait 2-3 minutes, then run:  php artisan smartept:why-no-signout
echo Link [1] should show green.
echo.
pause
