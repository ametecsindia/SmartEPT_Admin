' SmartEPT LiveView Relay -- one-time setup.
' Double-click this ONE file ONCE. It does two things:
'   1. Adds a shortcut to your Windows Startup folder so the relay launches
'      automatically, silently, every time you log in -- no terminal, no
'      "npm start", ever again.
'   2. Starts the relay right now too, so you don't have to log out and back
'      in to see it working.
' Safe to double-click more than once -- it just re-creates the same shortcut.
Dim shell, fso, startupFolder, shortcutPath, watchdogPath
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

watchdogPath = fso.GetParentFolderName(WScript.ScriptFullName) & "\relay-watchdog.vbs"
startupFolder = shell.SpecialFolders("Startup")
shortcutPath = startupFolder & "\SmartEPT LiveView Relay.lnk"

Dim link
Set link = shell.CreateShortcut(shortcutPath)
link.TargetPath = "wscript.exe"
link.Arguments = "//B """ & watchdogPath & """"
link.WindowStyle = 7 ' minimized/hidden
link.Description = "Starts the SmartEPT LiveView relay silently at login"
link.Save

' Start it immediately too, in the background, without waiting for it to exit.
shell.Run "wscript.exe //B """ & watchdogPath & """", 0, False

MsgBox "Done." & vbCrLf & vbCrLf & _
  "The SmartEPT LiveView relay now starts automatically every time you log in to Windows." & vbCrLf & _
  "It has also been started just now." & vbCrLf & vbCrLf & _
  "You never need to run 'npm start' for the relay again. If you ever want to stop the " & _
  "auto-start, delete this shortcut from your Startup folder:" & vbCrLf & shortcutPath, _
  vbInformation, "SmartEPT LiveView Relay"
