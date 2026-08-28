@echo off
REM ===========================================================================
REM  WITHDRAWN 27-Aug-2026. Do not use. Do not put this in a client package.
REM
REM  This ran two installers in sequence: the standalone enforcer, then the agent.
REM  That was wrong, and it had been wrong since the day before it was written.
REM
REM  The agent installer ALREADY contains the service that does the blocking. See
REM  agent\build\installer.nsh: it unpacks the service to
REM  resources\service\SmartEPTAgentService.exe, registers it, starts it, enables
REM  AppIDSvc, adds the Start Menu escape hatch - and DELETES any standalone
REM  SmartEPTEnforcer it finds, because that is the retired product.
REM
REM  So this script installed the old service and then handed control to an
REM  installer whose job is to remove it. Which one won depended on ordering.
REM
REM  THE WHOLE EMPLOYEE-PC INSTALL IS:  run the setup in 2-AGENT.
REM
REM  Kept as a stub rather than deleted so that a copy already sitting in
REM  somebody's package folder says this instead of doing damage.
REM ===========================================================================
echo.
echo  This script has been withdrawn.
echo.
echo  The SmartEPT Agent setup in the 2-AGENT folder installs everything this PC
echo  needs - the agent AND the service that blocks. Run that instead. There is
echo  no separate enforcer to install.
echo.
pause
exit /b 1
