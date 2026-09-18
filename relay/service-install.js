'use strict';
// Superseded 15-Sep-2026: the relay is now shipped as a compiled, dependency-free
// binary (npm run build -> dist/smartept-relay-win.exe / -linux / -macos), and
// INSTALL.bat registers it as a Windows service directly with the OS's own sc.exe
// (same pattern the Agent's own background service uses — see
// agent/build/installer.nsh) instead of through node-windows + this script.
// install-linux.sh / install-macos.sh do the equivalent with systemd / launchd.
//
// Kept only so a stray `npm run service:install` fails loudly instead of silently:
console.error('[smartept-relay] service-install.js is no longer used.');
console.error('Re-run INSTALL.bat (Windows) or install-linux.sh / install-macos.sh —');
console.error('they now register relay/dist/smartept-relay-* directly with the OS.');
process.exit(1);
