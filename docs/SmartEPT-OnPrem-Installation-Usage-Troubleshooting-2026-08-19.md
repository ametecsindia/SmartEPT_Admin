# On-premise installation, usage & troubleshooting

**SmartEPT by Ametecs · 19 August 2026 · Client-side build · Windows and Linux**

---

## 1 · What you are installing

The **client-side build** — the pure SmartEPT application, generated from the product by
`deployment/make-clientside.php`. It contains the application, its dependencies (`vendor/`) and
the database migrations. There is **nothing to compile**, no Node build, and **no internet
connection is required at any point** once the folder reaches the server.

It deliberately contains **no installer scripts** — no `.bat`, no `.sh`. Every step below is
manual and explicit. It also contains none of our documentation, no test suite, no build scripts,
no `.env`, no licence file, and none of our own data.

### Before you travel to site

On the build machine:

```
cd C:\laragon\www\smartept
composer install --no-dev --optimize-autoloader
php deployment\make-clientside.php C:\laragon\www\smartept-clientside --sync
composer install
```

Take the resulting folder (~7,600 files, ~38 MB). If the build refuses because `vendor/` holds
dev packages, that guard is correct — run the first line and try again rather than overriding it.

**Take with you:** the folder (on a USB stick or as a ZIP you make yourself), the PHP installer
for the target platform, and this guide. Assume the site has no internet.

---

## 2 · Server requirements — both platforms

| | Requirement |
|---|---|
| PHP | **8.2 or 8.3** with `pdo_mysql`, `openssl`, `mbstring`, `curl`, `zip`, `gd`, `fileinfo` |
| Database | MySQL 8 or MariaDB 10.6+ |
| Web server | IIS, Apache or Nginx |
| RAM | 4 GB minimum; 8 GB if more than ~50 agents |
| Disk | Application ~40 MB. Size the rest for screenshots — estimate per employee, per day, against the agreed retention period, and add 30% |

> **Do not install on PHP 8.5.** The product is tested on 8.2/8.3. If the build has been
> SourceGuardian-encoded, the loader must match the PHP **minor** version exactly, and 8.5 loaders
> lag well behind release. Run `php -v` and confirm before you start.

Decide the database credentials before you begin, and do not use the MySQL root account for the
application.

---

## 3 · Windows installation

Two routes. **3A (IIS)** is the production route — serves on port 80/443, starts with Windows, no
console window. **3B** is a quick route for a pilot or a small site.

### 3A · Windows Server with IIS — production

#### Step 1 · IIS with CGI

Server Manager → *Add Roles and Features* → **Web Server (IIS)** → Role Services → Application
Development → tick **CGI**.

On Windows 10/11 Pro: *Turn Windows features on or off* → Internet Information Services →
Application Development Features → **CGI**.

#### Step 2 · URL Rewrite module

Install from `https://www.iis.net/downloads/microsoft/url-rewrite`. **This is required** — the
shipped `public\web.config` depends on it. Download it before you travel.

#### Step 3 · PHP 8.3 NTS x64

1. Unzip the **Non-Thread-Safe x64** build from `windows.php.net/download` to `C:\PHP`.
2. Copy `php.ini-production` to `php.ini`.
3. Edit `php.ini`:

```ini
extension_dir = "ext"
extension=pdo_mysql
extension=openssl
extension=mbstring
extension=curl
extension=zip
extension=gd
extension=fileinfo

memory_limit = 512M
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 120
date.timezone = Asia/Kolkata
```

4. Install the matching **Visual C++ Redistributable** if PHP complains on start.
5. Add `C:\PHP` to the system PATH.
6. Verify: `php -v` then `php -m` — every extension above must be listed.

#### Step 4 · Handler mapping in IIS

IIS Manager → server node → **Handler Mappings** → *Add Module Mapping*:

| Field | Value |
|---|---|
| Request path | `*.php` |
| Module | `FastCgiModule` |
| Executable | `C:\PHP\php-cgi.exe` |
| Name | `PHP_via_FastCGI` |

#### Step 5 · MySQL

Install MySQL 8 or MariaDB. Then:

```sql
CREATE DATABASE smartept CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'smartept'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON smartept.* TO 'smartept'@'localhost';
FLUSH PRIVILEGES;
```

#### Step 6 · Copy the application and create the site

Copy the folder to e.g. `C:\inetpub\smartept`.

IIS Manager → Sites → **Add Website**:

- Site name: `SmartEPT`
- **Physical path: `C:\inetpub\smartept\public`** — the `public` folder, **never** the folder above it
- Binding: http, port 80 (add https later with the client's certificate)

#### Step 7 · Turn WebDAV OFF

**Do not skip this — it bites every time.** IIS enables WebDAV by default and it grabs the `PUT`
and `DELETE` verbs before PHP sees them. The console loads and creates records perfectly, and then
**every Edit → Save and every Delete returns HTTP 405**.

The shipped `public\web.config` removes it per-site, which is enough on most servers. If 405s
persist, remove the feature: Server Manager → Remove Roles and Features → Web Server (IIS) →
Common HTTP Features → untick **WebDAV Publishing** (Windows 10/11: Turn Windows features on/off →
IIS → World Wide Web Services → Common HTTP Features → WebDAV Publishing), then `iisreset`.

#### Step 8 · Permissions

Grant **Modify** to the application pool identity — `IIS AppPool\SmartEPT` — on:

- `C:\inetpub\smartept\storage` (and everything under it)
- `C:\inetpub\smartept\bootstrap\cache`

Everything else can stay read-only.

#### Step 9 · Configure and prepare

```
cd C:\inetpub\smartept
copy .env.example .env
```

Edit `.env` — see §5. Then, one line at a time:

```
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan storage:link
php artisan smartept:client-provision
```

#### Step 10 · Firewall and scheduled tasks

Allow inbound TCP 80/443 for the agents.

SmartEPT runs nightly jobs (attendance completion, purging, alerts). Add a Windows **Scheduled
Task**, every minute, running:

```
C:\PHP\php.exe C:\inetpub\smartept\artisan schedule:run
```

Run it as an account with Modify on `storage`. Without this, nightly attendance completion and
alert emails will not happen.

#### Step 11 · Verify

Open `http://<server>/admin` and sign in.

---

### 3B · Windows quick route (pilot / small site)

Copy the folder to e.g. `C:\smartept`, then follow **Steps 3, 5 and 9** above, and serve with:

```
php artisan serve --host=0.0.0.0 --port=8080
```

Console at `http://<server>:8080/admin`. Still add the scheduled task from Step 10.

This is fine for a pilot. It does not restart with the server and has no HTTPS, so move to 3A
before the site goes live.

---

## 4 · Linux installation

Shown for **Ubuntu/Debian with Nginx**. RHEL/Alma differences are noted at each step.

#### Step 1 · PHP 8.3 and extensions

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-curl \
                    php8.3-zip php8.3-gd php8.3-xml php8.3-bcmath
php -v && php -m
```

RHEL/Alma: `sudo dnf module enable php:8.3 -y && sudo dnf install -y php-fpm php-mysqlnd
php-mbstring php-gd php-xml php-zip`

Edit `/etc/php/8.3/fpm/php.ini`:

```ini
memory_limit = 512M
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 120
date.timezone = Asia/Kolkata
```

Then `sudo systemctl restart php8.3-fpm`.

#### Step 2 · MySQL / MariaDB

```bash
sudo apt install -y mariadb-server
sudo mysql -e "CREATE DATABASE smartept CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'smartept'@'localhost' IDENTIFIED BY '<strong-password>';"
sudo mysql -e "GRANT ALL PRIVILEGES ON smartept.* TO 'smartept'@'localhost'; FLUSH PRIVILEGES;"
```

#### Step 3 · Copy the application

```bash
sudo mkdir -p /var/www/smartept
sudo cp -r /media/usb/smartept-clientside/. /var/www/smartept/
cd /var/www/smartept
```

#### Step 4 · Ownership and permissions

```bash
sudo chown -R www-data:www-data /var/www/smartept
sudo find /var/www/smartept -type d -exec chmod 755 {} \;
sudo find /var/www/smartept -type f -exec chmod 644 {} \;
sudo chmod -R ug+rw storage bootstrap/cache
```

RHEL/Alma use `apache:apache` (or `nginx:nginx`). **With SELinux enforcing**, also:

```bash
sudo setsebool -P httpd_can_network_connect 1
sudo chcon -R -t httpd_sys_rw_content_t /var/www/smartept/storage \
                                        /var/www/smartept/bootstrap/cache
```

Skipping the SELinux step produces a blank page with permission errors in the log — a very common
cause of a "white screen" on RHEL.

#### Step 5 · Nginx site

`/etc/nginx/sites-available/smartept`:

```nginx
server {
    listen 80;
    server_name smartept.local;              # or the server's IP
    root /var/www/smartept/public;           # the public folder, never the one above

    index index.php;
    charset utf-8;
    client_max_body_size 20M;                # screenshot uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/smartept /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**Apache instead of Nginx:** point the `DocumentRoot` at `/var/www/smartept/public`, `a2enmod
rewrite`, and allow `.htaccess` with `AllowOverride All` on that directory. The application ships
a `public/.htaccess`.

#### Step 6 · Configure and prepare

```bash
cp .env.example .env
# edit .env — see §5
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=RolePermissionSeeder --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan smartept:client-provision
```

Run these **as the web user** — running as root creates log and cache files that the web server
then cannot write.

#### Step 7 · Scheduler

```bash
sudo crontab -u www-data -e
```

Add:

```
* * * * * cd /var/www/smartept && php artisan schedule:run >> /dev/null 2>&1
```

Without this, nightly attendance completion, data purging and alert emails do not run.

#### Step 8 · Firewall

```bash
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
```

RHEL: `sudo firewall-cmd --add-service=http --permanent && sudo firewall-cmd --reload`

#### Step 9 · Verify

`http://<server>/admin`

---

## 5 · The `.env` file — both platforms

```ini
APP_NAME=SmartEPT
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<server-name-or-ip>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartept
DB_USERNAME=smartept
DB_PASSWORD=<strong-password>

SESSION_DRIVER=file
CACHE_STORE=file

SMARTEPT_ONPREM=true
SMARTEPT_LICENCE_ENFORCE=true
```

> **`SMARTEPT_ONPREM=true` is not optional.** Without it the activation page shows a "managed by
> Ametecs" notice instead of the licence upload form and the activation endpoint returns 404 —
> **the client cannot activate at all.**

`APP_DEBUG=false` matters: with it true, a stack trace on any error exposes paths, configuration
and query fragments to anyone who can reach the console.

Add the client's SMTP settings if they want alert emails. After any `.env` change:
`php artisan config:clear`.

---

## 6 · Licensing and activation

1. Open **`http://<server>/activate`**. The page shows this machine's **fingerprint**.
2. Send the fingerprint to Ametecs (phone, email, photo of the screen — any channel).
3. Ametecs issues a **`.lic`** locked to that machine.
4. Upload it on the same page. No internet needed at any point.

- The licence is **node-locked**. If the server is replaced, Ametecs shifts the licence and issues
  a new `.lic`; the old one stops working.
- A licence file is **self-authoritative** — the install does not phone home to validate it, so a
  fully offline server stays licensed indefinitely with no daily delay.
- With no licence, a new install runs a **7-day evaluation**, then blocks agent syncing. The
  console stays reachable so an administrator can enter the licence.
- A **perpetual** licence has no expiry and never lapses.

---

## 7 · Day-to-day usage — the parts engineers get asked about

### 7.1 Attendance source

**Organisation → Attendance source.**

- **With biometric device** — door punches merge with agent sessions; Gate-to-PC available.
- **Without biometric device** — attendance comes purely from agent login/logout; the Biometric
  and Gate Exclusions screens hide themselves, because there is no door to punch at.

### 7.2 Gate-to-PC

**Biometric → Gate-to-PC.** When on, an agent will not start a work session until that employee's
door punch reaches SmartEPT. Modes: *Auto* (on when a punch device is registered), *Always ON*,
*OFF*.

### 7.3 Gate Exclusions

**Manage → Gate Exclusions** — who may sign in without a punch, and until when. Levels resolve
**most specific first**: Machine → Employee → Team → Department → Branch. Each is *Inherit*,
*Excluded* or *Required* (must punch in even if a level above is excluded).

**Always set an end date** unless the reason is permanent — a dated exclusion re-arms the gate by
itself; an undated one stays until someone remembers.

Use it when: the reader is down (Branch, dated) · one person's punch is rejected (Employee, dated)
· **the link is down so punches are stuck on the device** (Branch/Department, dated) · night shift
or field staff who never pass a reader (Team, undated).

### 7.4 Adding punches without a reader

**Biometric → Import punches (CSV)** — for correcting attendance after a reader outage:

```
biometric_employee_id,punch_type,punched_at
TEST-A,IN,2026-08-19 09:05:00
```

The device ID must first be linked under **Map biometric ID → employee**.

---

## 8 · Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **Every Save and Delete returns 405**, console otherwise fine | IIS WebDAV intercepting `PUT`/`DELETE` | §3A Step 7, then `iisreset` |
| **White screen, nothing in the log** (Linux) | SELinux, or `storage` not writable by the web user | §4 Step 4. Check `/var/log/audit/audit.log` |
| **White screen** (either platform) | Missing PHP extension, or an encoded build with no matching SourceGuardian loader | `php -v` and `php -m`. Loader must match the PHP **minor** exactly |
| **403 or directory listing** at the root | Document root points at the folder instead of `public` | Repoint to `.../public` |
| **404 on every page except the home page** | URL Rewrite module missing (IIS) or `mod_rewrite`/`try_files` missing | §3A Step 2 / §4 Step 5 |
| Agent shows **"Punch in at the door to start"** and never releases | Gate-to-PC on, no punch arrived | **Manage → Gate Exclusions** — the top card says whether the gate is on. Import the punch, add a dated exclusion, or switch to *Without biometric device*. Releases within ~15 s, no restart |
| Agent still walled **after** excluding them | Exclusion is **Scheduled** or **Expired** (Status column), or a more specific level says *Required* | Fix the dates, or check the Machine/Employee level |
| Whole site walled after moving to *Without biometric device* | Fixed 19-Aug-2026 | Use this release or later |
| **`/activate` shows "managed by Ametecs"** | `SMARTEPT_ONPREM` not set | Set it in `.env`, then `php artisan config:clear` |
| Console blocked: **licence** message | Evaluation expired, or licence revoked/expired | Upload a valid `.lic`. Administrators are never blocked at sign-in — they are the rescue route |
| **XLSX export returns 503** | `phpoffice/phpspreadsheet` missing from `vendor/` | Fixed 19-Aug-2026. Older build: `composer require phpoffice/phpspreadsheet` |
| **`php artisan optimize` / `route:cache` breaks the site** | The application registers closure routes, which cannot be cached | Never run those. Use `optimize:clear` |
| `.env` changes have no effect | Config cached | `php artisan config:clear` |
| Nightly attendance never completes; no alert emails | Scheduler not running | §3A Step 10 / §4 Step 7 |
| Screenshots stop, everything else fine | Storage quota reached, or screenshot policy off for that person | Audit & Ops → storage usage; then the employee's policy |
| Employee shows offline but their PC is on | Agent not running, unbound, or tracking mode EXCLUDED | Devices screen, then tracking mode |
| Attendance times ~5 hours out | Company or branch timezone wrong | **Organisation → Company time zone** (branches can override) |
| Agents cannot reach the server | Firewall, or `APP_URL` wrong | §3A Step 10 / §4 Step 8; confirm `APP_URL` matches what agents are pointed at |

### Escalating to Ametecs

Send the exact on-screen wording, the time to the minute, the employee/branch/PC involved, plus
`storage/logs/laravel.log` and the output of `php artisan smartept:about`.

---

## 9 · Updating an existing installation

1. **Back up the database** and take a copy of `.env` and `license.lic`.
2. Replace the application files, **keeping** `.env`, `license.lic` and `storage/`.
3. `php artisan migrate --force`
4. `php artisan optimize:clear`
5. Confirm the console loads and one agent reconnects.

Migrations are additive and guarded, so re-running them is safe. On Linux, re-apply ownership
(§4 Step 4) after copying files as root.

---

*Ametecs India — internal. SmartEPT · Employee Productivity Tracking.*
