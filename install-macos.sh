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
php artisan migrate --force
# SmartPRS2 rule: CLIENT servers get roles only — never the demo seeder.
php artisan db:seed --class='Database\Seeders\RolePermissionSeeder' --force
php artisan storage:link >/dev/null 2>&1 || true
chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true
echo "[ok] application installed"

# --- provision the client workspace (SmartPRS2 client:provision, as-is) ------
echo
echo "Create your company's admin login (clean workspace — no demo data):"
read -r -p "  Company name: " CP_COMPANY
read -r -p "  Admin email:  " CP_EMAIL
read -r -s -p "  Admin password (min 8 chars): " CP_PASS; echo
php artisan smartept:client-provision --company="$CP_COMPANY" --email="$CP_EMAIL" --password="$CP_PASS" \
  || { echo "[ERROR] Provisioning failed — fix the input above and re-run: php artisan smartept:client-provision ..."; exit 1; }

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

# --- 5) background scheduler (cron, every minute) ----------------------------
# 2-Sep-2026. Everything the product does in the background runs through
# `artisan schedule:run`: post-shift auto sign-out, meeting auto-close, biometric
# auto-sync, the nightly attendance sheet, licence phone-home, backups, retention
# purge. This was a manual step in INSTALL-GUIDE.md and it was being skipped, so
# all of them were dead while every screen still looked correct. Idempotent: the
# grep -v drops any previous line before re-adding it.
if command -v crontab >/dev/null 2>&1; then
  ( crontab -l 2>/dev/null | grep -v '# smartept-scheduler' ; \
    echo "* * * * * cd $APP_DIR && $(command -v php) artisan schedule:run >/dev/null 2>&1 # smartept-scheduler" ) \
    | crontab - \
    && echo "[ok] background scheduler registered in cron (every minute)" \
    || echo "[WARN] could not write crontab — background jobs will NOT run until it is added"
else
  echo "[WARN] cron not found. Background jobs (auto sign-out, biometric sync, nightly"
  echo "       attendance) will NOT run. Add this to a scheduler yourself:"
  echo "       * * * * * cd $APP_DIR && php artisan schedule:run >/dev/null 2>&1"
fi

# --- LiveView relay (launchd) -------------------------------------------------
# 14-Sep-2026, rebuilt as a real production step (no Node.js dependency) 15-Sep-2026:
# relay/dist/smartept-relay-macos is a SELF-CONTAINED compiled binary (built with
# `npm run build` from relay/server.js — see relay/package.json), so this needs no
# Node.js/npm at all, not even to run it as a launch agent. Same "install once,
# invisible forever" contract as the console's own launch agent above. The shared
# secret is copied straight from the .env APP_KEY this install just generated. A
# missing binary is a note, not a failure — LiveView is optional.
if [ -f relay/dist/smartept-relay-macos ]; then
  chmod +x relay/dist/smartept-relay-macos
  grep '^APP_KEY=' .env | cut -d= -f2- > relay/relay-secret.txt

  # HTTPS for LiveView (optional - only needed if this site's admin console is
  # opened over https://). 18-Sep-2026: browsers refuse an insecure ws:// socket
  # from an https:// page. Rather than every client hand-editing a reverse-proxy
  # config, the relay speaks TLS itself when told which certificate to use -
  # asked ONCE, here. Blank = skip = plain ws:// (today's behaviour, unchanged).
  echo
  echo "HTTPS for LiveView (optional - blank skips it, relay stays on ws://):"
  if [ -f relay/relay-tls-cert-path.txt ]; then
    echo "  Already configured - press Enter on both lines below to keep it."
  fi
  read -r -p "  Certificate file for this site's HTTPS (blank = skip): " RELAY_TLS_CERT
  if [ -n "$RELAY_TLS_CERT" ] && [ -f "$RELAY_TLS_CERT" ]; then
    read -r -p "  Matching private key file: " RELAY_TLS_KEY
    if [ -n "$RELAY_TLS_KEY" ] && [ -f "$RELAY_TLS_KEY" ]; then
      echo "$RELAY_TLS_CERT" > relay/relay-tls-cert-path.txt
      echo "$RELAY_TLS_CERT" > relay/dist/relay-tls-cert-path.txt
      echo "$RELAY_TLS_KEY" > relay/relay-tls-key-path.txt
      echo "$RELAY_TLS_KEY" > relay/dist/relay-tls-key-path.txt
      echo "[ok] LiveView will use wss:// - same certificate as the main site."
    else
      echo "[WARN] Key file not found - LiveView will use ws:// for now."
    fi
  elif [ -n "$RELAY_TLS_CERT" ]; then
    echo "[WARN] Certificate file not found - LiveView will use ws:// for now."
  elif [ -f relay/relay-tls-cert-path.txt ]; then
    echo "[ok] Keeping the existing certificate - still wss://."
  else
    echo "[ok] Skipped - LiveView uses ws:// (fine for http:// / LAN-only sites)."
  fi

  RELAY_PLIST="$HOME/Library/LaunchAgents/com.ametecs.smartept-relay.plist"
  cat > "$RELAY_PLIST" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.ametecs.smartept-relay</string>
  <key>ProgramArguments</key><array>
    <string>$APP_DIR/relay/dist/smartept-relay-macos</string>
  </array>
  <key>WorkingDirectory</key><string>$APP_DIR/relay</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
</dict></plist>
XML
  launchctl unload "$RELAY_PLIST" 2>/dev/null || true
  launchctl load "$RELAY_PLIST"
  echo "[ok] LiveView relay installed as a launch agent — starts automatically at login"
else
  echo "[note] relay/dist/smartept-relay-macos not found — LiveView will not be available."
fi

IP=$(ipconfig getifaddr en0 2>/dev/null || echo localhost)
echo "============================================================"
echo "  DONE. SmartEPT console:  http://$IP:8080/admin"
echo "  1) Sign in with the admin email + password you just chose."
echo "  2) 7-day evaluation starts now, full features."
echo "  3) To license: open http://$IP:8080/activate — copy the machine"
echo "     fingerprint, send it to Ametecs, upload the .lic you receive."
echo "     Activation is instant and fully offline."
echo "  Help: sales@ametecsindia.com · WhatsApp 90000 98877"
echo "============================================================"
