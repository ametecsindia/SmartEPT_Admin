<#
    SmartEPT - write MANIFEST.txt for an assembled client package.

    This is a separate .ps1 on purpose. It used to be an inline
    `powershell -NoProfile -Command "..."` inside BUILD-CLIENT-PACKAGE.bat and it
    broke on 27-Aug-2026 with:

        Missing expression after ','.
        ''Every' is not recognized as an internal or external command

    Two faults, both cmd quoting rather than PowerShell:
      1. the $lines=@(...) array wrapped onto further lines of the .bat without a
         trailing ^ , so cmd ended the command at the comma and then tried to RUN
         the remaining lines as batch commands;
      2. `^|` inside a double-quoted argument is not an escape - cmd passes the
         caret through, so PowerShell received a literal `^|` and could not parse it.

    A .ps1 has neither problem: cmd never has to escape anything, and the file can
    be read and edited like normal code. Same reason make-clientside.php is plain
    PHP - one language per file, no nesting.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)] [string] $Package,
    [string] $Stamp = (Get-Date -Format 'yyyy-MM-dd')
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Package -PathType Container)) {
    Write-Error "Package folder not found: $Package"
    exit 1
}

$pkg = (Resolve-Path -LiteralPath $Package).Path.TrimEnd('\')
$out = Join-Path $pkg 'MANIFEST.txt'

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add('SmartEPT client package')
$lines.Add("Built $Stamp on $env:COMPUTERNAME")
$lines.Add('')
$lines.Add('Every file below with its SHA256. Verify one on the client with:')
$lines.Add('    certutil -hashfile <file> SHA256')
$lines.Add('')

$files = Get-ChildItem -LiteralPath $pkg -Recurse -File |
    Where-Object { $_.Name -ne 'MANIFEST.txt' } |
    Sort-Object FullName

if (-not $files) {
    Write-Error "No files under $pkg - refusing to write an empty manifest."
    exit 1
}

foreach ($f in $files) {
    $rel = $f.FullName.Substring($pkg.Length + 1)
    $h = (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash.ToLower()
    $lines.Add($rel)
    $lines.Add(('    {0:N0} bytes' -f $f.Length))
    $lines.Add('    ' + $h)
    $lines.Add('')
}

Set-Content -LiteralPath $out -Value $lines -Encoding UTF8

Write-Host ("      {0} file(s) hashed into MANIFEST.txt" -f $files.Count)
exit 0
