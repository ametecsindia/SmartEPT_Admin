#!/usr/bin/env bash
# =============================================================================
#  SmartEPT Admin Server — Linux installer (Ubuntu/Debian/RHEL-family)
#  Run from the unzipped package folder:   sudo bash install-linux.sh
#  © 2026 Ametecs India Private Limited. All rights reserved.
# =============================================================================
set -e
cd "$(dirname "$0")"
APP_DIR="$(pwd)"

echo "============================================================"
echo "  SmartEPT Admin Server — Linux install"
echo "  Folder: $APP_DIR"
echo "============================================================"

# --- 1) PHP present + right version -----------------------------------------
if ! command -v php >/dev/null 2>&1; then
  echo "[ERROR] PHP not found. Install PHP 8.2+ first, e.g.:"
  echo "  Ubuntu/Debian: sudo apt install php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-xml"
  echo "  RHEL/Alma:     sudo dnf install php php-cli php-mysqlnd php-mbstring php-gd php-zip php-xml"
  exit 1
fi
PHPV=$(php -r 'echo PHP_VERSION;')
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || {
  echo "[ERROR] PHP $PHPV found — SmartEPT needs PHP 8.2 or newer."; exit 1; }
echo "[ok] PHP $PHPV"

# --- 2) required extensions ---------------------------------------------------
MISSING=""
for ext in pdo_mysql openssl mbstring curl zip gd fileinfo; do
  php -m | grep -qi "^$ext$" || MISSING="$MISSING $ext"
done
if [ -n "$MISSING" ]; then
  echo "[ERROR] Missing PHP extensions:$MISSING"
  echo "  Ubuntu/Debian: sudo apt install $(for e in $MISSING; do printf 'php8.3-%s ' "${e/pdo_mysql/mysql}"; done)"
  exit 1
fi
echo "[ok] all required PHP extensions present"

# --- 3) environment + database (shared helper: MySQL, auto-creates the DB) ---
php deployment/install-helper.php
php artisan key:generate --force
php artisan migrate --force --seed
chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true
echo "[ok] application installed"

# --- 4) auto-start service (systemd) -----------------------------------------
if [ "$(id -u)" = "0" ] && command -v systemctl >/dev/null 2>&1; then
  cat > /etc/systemd/system/smartept-admin.service <<UNIT
[Unit]
Description=SmartEPT Admin Server (on-premises)
After=network.target mysql.service mariadb.service

[Service]
WorkingDirectory=$APP_DIR
ExecStart=$(command -v php) artisan serve --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable --now smartept-admin
  echo "[ok] service 'smartept-admin' installed and started (survives reboot)"
else
  echo "[note] Not root or no systemd — start manually with:  bash START-SMARTEPT.sh"
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo "============================================================"
echo "  DONE. SmartEPT console:  http://${IP:-localhost}:8080/admin"
echo "  1) Sign in: admin@ametecs.io / password  — CHANGE IT IMMEDIATELY."
echo "  2) 7-day evaluation starts now, full features."
echo "  3) To license: open http://${IP:-localhost}:8080/activate — copy the"
echo "     machine fingerprint, send it to Ametecs, upload the .lic you receive."
echo "     Activation is instant and fully offline."
echo "  Help: sales@ametecsindia.com · WhatsApp 90000 98877"
echo "============================================================"
