'use strict';
// Foreground window + process detection with no native modules.
//
// v2 (17-Jul, after the first live run): ONE persistent PowerShell helper that
// compiles the user32 P/Invoke type ONCE and then answers each poll instantly
// over stdin/stdout. The old code spawned a fresh powershell through the
// fragile `-Command -` stdin path every 5 seconds, recompiling Add-Type each
// time and swallowing every error — on real machines it quietly returned
// nulls, so app-usage and website-usage stayed permanently empty (the "—" app
// name on screenshot cards was the visible symptom).
//
// On non-Windows (or if PowerShell is unavailable) it degrades gracefully to
// nulls — the agent still tracks active/idle time.

const { spawn } = require('child_process');
const os = require('os');

// The helper loops forever: read a line → write "process.exe|Window title".
// Pipes/newlines are stripped from titles so the one-line protocol can't break.
const HELPER_PS = `
$ErrorActionPreference = 'SilentlyContinue'
Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class W {
  [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
  [DllImport("user32.dll")] public static extern int GetWindowThreadProcessId(IntPtr h, out int pid);
  [DllImport("user32.dll")] public static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
}
"@
while ($true) {
  $line = [Console]::In.ReadLine()
  if ($null -eq $line) { break }
  $out = '|'
  try {
    $h = [W]::GetForegroundWindow()
    $sb = New-Object System.Text.StringBuilder 512
    [void][W]::GetWindowText($h, $sb, 512)
    $procId = 0
    [void][W]::GetWindowThreadProcessId($h, [ref]$procId)
    $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
    $name = if ($p) { $p.ProcessName + '.exe' } else { '' }
    $title = ($sb.ToString() -replace '[\\r\\n|]', ' ').Trim()
    $out = $name + '|' + $title
  } catch { $out = '|' }
  [Console]::Out.WriteLine($out)
}
`;

let proc = null;
let buf = '';
let waiters = [];
let failedUntil = 0; // back-off after a spawn failure so we never spawn-storm

function parseLine(line) {
  const i = line.indexOf('|');
  if (i < 0) return { app: null, title: null };
  const app = line.slice(0, i).trim();
  const title = line.slice(i + 1).trim();
  return { app: app || null, title: title || null };
}

function resetProc(backoffMs) {
  if (proc) { try { proc.kill(); } catch { /* already gone */ } }
  proc = null;
  buf = '';
  const pending = waiters.splice(0, waiters.length);
  pending.forEach((w) => { clearTimeout(w.timer); w.resolve({ app: null, title: null }); });
  failedUntil = Date.now() + (backoffMs || 15_000);
}

function ensureProc() {
  if (proc || os.platform() !== 'win32' || Date.now() < failedUntil) return;

  // -EncodedCommand avoids every quoting/stdin-parsing pitfall of `-Command -`.
  const encoded = Buffer.from(HELPER_PS, 'utf16le').toString('base64');
  try {
    proc = spawn('powershell.exe',
      ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', encoded],
      { windowsHide: true, stdio: ['pipe', 'pipe', 'ignore'] });
  } catch {
    proc = null;
    failedUntil = Date.now() + 60_000;
    return;
  }

  proc.stdout.setEncoding('utf8');
  proc.stdout.on('data', (chunk) => {
    buf += chunk;
    let nl;
    while ((nl = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, nl).replace(/\r$/, '');
      buf = buf.slice(nl + 1);
      const w = waiters.shift();
      if (w) { clearTimeout(w.timer); w.resolve(parseLine(line)); }
    }
  });
  proc.on('exit', () => resetProc());
  proc.on('error', () => resetProc());
}

function getActiveWindow() {
  return new Promise((resolve) => {
    if (os.platform() !== 'win32') { resolve({ app: null, title: null }); return; }
    ensureProc();
    if (!proc) { resolve({ app: null, title: null }); return; }

    const w = {
      resolve,
      // First call includes the one-time Add-Type compile (~1–2s); later calls
      // answer in milliseconds. A timeout means the helper is wedged — kill it
      // so request/response pairing can never skew, and let it respawn.
      timer: setTimeout(() => {
        const idx = waiters.indexOf(w);
        if (idx >= 0) waiters.splice(idx, 1);
        resolve({ app: null, title: null });
        resetProc(5_000);
      }, 8_000),
    };
    waiters.push(w);

    try { proc.stdin.write('p\n'); } catch { /* exit handler cleans up */ }
  });
}

function stopActiveWindowHelper() { resetProc(0); failedUntil = 0; }

module.exports = { getActiveWindow, stopActiveWindowHelper };
