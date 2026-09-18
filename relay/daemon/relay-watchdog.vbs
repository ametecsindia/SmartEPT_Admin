' SmartEPT LiveView Relay -- silent auto-restart launcher.
' Runs the already-built relay binary (dist\smartept-relay-win.exe) hidden,
' with no console window, and relaunches it automatically if it ever exits
' (crash, Windows update, etc). Launched by a Scheduled Task at boot, as
' SYSTEM (see INSTALL-LIVEVIEW-RELAY.bat) -- NOT a Windows service, because
' the compiled relay binary is a plain pkg-compiled Node console app, not a
' program that speaks the Service Control Manager protocol (unlike the
' Agent's Go-based enforcement service, which does): registering it with
' sc.exe creates the service fine but sc.exe start then fails with error
' 1053 ("did not respond to the start... request"), every time, found
' 15-Sep-2026. A Scheduled Task just launches a process and doesn't care
' whether it talks back to the SCM, so it works for any executable.
Dim fso, shell, exePath
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
' Relative to this script's own folder (relay\daemon\), not hard-coded to any
' one machine's install path -- this ships inside INSTALL.bat to every client.
exePath = fso.BuildPath(fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)), "dist\smartept-relay-win.exe")

Do While True
  If fso.FileExists(exePath) Then
    ' 0 = fully hidden window, True = wait here until it exits before looping
    shell.Run """" & exePath & """", 0, True
  End If
  WScript.Sleep 5000 ' brief pause before relaunching -- avoids a tight crash loop
Loop
