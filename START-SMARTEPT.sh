#!/usr/bin/env bash
# Start the SmartEPT Admin Server in the foreground (Linux/macOS).
# For auto-start use install-linux.sh (systemd) or install-macos.sh (launchd).
cd "$(dirname "$0")"
echo "SmartEPT console starting — open http://localhost:8080/admin  (Ctrl+C stops it)"
exec php artisan serve --host=0.0.0.0 --port=8080
