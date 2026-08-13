#!/usr/bin/env bash
# =============================================================================
#  SmartEPT Admin Server — macOS installer
#  Run from the unzipped package folder:   bash install-macos.sh
#  Needs: PHP 8.2+ (brew install php) and MySQL (brew install mysql && brew services start mysql)
#  © 2026 Ametecs India Private Limited. All rights reserved.
# =============================================================================
set -e
cd "$(dirname "$0")"
APP_DIR="$(pwd)"

echo "============================================================"
echo "  SmartEPT Admin Server — macOS install"
echo "  Folder: $APP_DIR"
echo "============================================================"

if ! command -v php >/dev/null 2>&1; then
  echo "[ERROR] PHP not found. Install with Homebrew:  brew install php mysql && brew services start mysql"
  exit 1
fi
PHPV=$(php -r 'echo PHP_VERSION;')
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || {
  echo "[ERROR] PHP $PHPV found — SmartEPT needs PHP 8.2 or newer (brew upgrade php)."; exit 1; }
MISSING=""
for ext in pdo_mysql openssl mbstring curl zip gd fileinfo; do
  php -m | grep -qi "^$ext$" || MISSING="$MISSING $ext"
done
[ -n "$MISSING" ] && { echo "[ERROR] Missing PHP extensions:$MISSING (Homebrew PHP normally includes all — brew reinstall php)"; exit 1; }
echo "[ok] PHP $PHPV with all required extensions"

# --- environment + database (shared helper: MySQL, auto-creates the DB) ------
php deployment/install-helper.php
php artisan key:generate --force
php artisan migrate --force --seed
chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true
echo "[ok] application installed"

# --- auto-start at login (launchd) -------------------------------------------
PLIST="$HOME/Library/LaunchAgents/com.ametecs.smartept-admin.plist"
mkdir -p "$HOME/Library/LaunchAgents"
cat > "$PLIST" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.ametecs.smartept-admin</string>
  <key>ProgramArguments</key><array>
    <string>$(command -v php)</string><string>artisan</string>
    <string>serve</string><string>--host=0.0.0.0</string><string>--port=8080</string>
  </array>
  <key>WorkingDirectory</key><string>$APP_DIR</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
</dict></plist>
XML
launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "[ok] launch agent installed — the console starts automatically at login"

IP=$(ipconfig getifaddr en0 2>/dev/null || echo localhost)
echo "============================================================"
echo "  DONE. SmartEPT console:  http://$IP:8080/admin"
echo "  1) Sign in: admin@ametecs.io / password  — CHANGE IT IMMEDIATELY."
echo "  2) 7-day evaluation starts now, full features."
echo "  3) To license: open http://$IP:8080/activate — copy the machine"
echo "     fingerprint, send it to Ametecs, upload the .lic you receive."
echo "     Activation is instant and fully offline."
echo "  Help: sales@ametecsindia.com · WhatsApp 90000 98877"
echo "============================================================"
