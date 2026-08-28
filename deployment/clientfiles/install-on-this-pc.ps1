# WITHDRAWN 27-Aug-2026. Do not use. See INSTALL-ON-THIS-PC.bat beside this file.
#
# The agent installer already contains the policy service and installs it
# (agent\build\installer.nsh). This script installed the RETIRED standalone
# enforcer, which that same installer then deletes.
#
# The whole employee-PC install is: run the setup in 2-AGENT.

Write-Host ''
Write-Host '  This script has been withdrawn.' -ForegroundColor Yellow
Write-Host ''
Write-Host '  The SmartEPT Agent setup in the 2-AGENT folder installs everything this PC'
Write-Host '  needs - the agent AND the service that blocks. Run that instead.'
Write-Host ''
Read-Host 'Press Enter to close'
exit 1
