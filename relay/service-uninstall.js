'use strict';
// Superseded 15-Sep-2026 — see service-install.js. To remove the service by hand:
//   Windows:      sc stop "SmartEPT LiveView Relay"  &&  sc delete "SmartEPT LiveView Relay"
//   Linux:        sudo systemctl disable --now smartept-relay
//   macOS:        launchctl unload ~/Library/LaunchAgents/com.ametecs.smartept-relay.plist
console.error('[smartept-relay] service-uninstall.js is no longer used — see the comment in this file.');
process.exit(1);
