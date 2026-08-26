# SmartEPT Admin Console — Installation Guide

**Version 1.1 · 19 August 2026 · Ametecs India Private Limited**

This guide takes a SmartEPT on-premise server from a bare machine to a licensed, running
console with employees loaded and agents reporting. It covers Windows (both the quick
Laragon route and a production IIS deployment), Linux and macOS.

Everything here refers to the package **`SmartEPT-Admin-Server-Setup-1.1.zip`**.

---

## 1. What you are installing

SmartEPT has three parts. This guide installs the first.

| Part | What it is | Where it runs |
|---|---|---|
| **Admin Console (this package)** | The server: database, reports, policies, the `/admin` web console | One server inside the company |
| **Desktop Agent** | The tracking client that reports activity, breaks and attendance | Every employee PC |
| **SmartEPT Central** | Ametecs' licensing and client portal | Hosted by Ametecs — nothing to install |

The agents talk to the Admin Console over your own network. Once licensed, an on-premise
install **never needs the internet** — activation is offline and no data leaves the company.

---

## 2. Before you begin

### 2.1 Server requirements

| | Minimum | Recommended |
|---|---|---|
| CPU | 2 cores | 4 cores |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB | 100 GB+ (screenshots and webcam photos are the growth driver) |
| OS | Windows 10/11 Pro, Windows Server 2019+, Ubuntu 20.04+, RHEL 8+, macOS 12+ | — |
| PHP | **8.2 or newer** | **8.3** |
| Database | MySQL 8 or MariaDB 10.5+ | MySQL 8 |

> **Use PHP 8.3.** PHP 8.5 is not recommended: several dependencies have not been validated
> against it, and SourceGuardian loaders lag well behind. Keep client servers on 8.3.

### 2.2 Required PHP extensions

`pdo_mysql`, `openssl`, `mbstring`, `curl`, `zip`, `gd`, `fileinfo`

The Linux and macOS installers check these for you and print the exact `apt` / `dnf` / `brew`
command for anything missing. On Windows, enable them in `php.ini`.

### 2.3 Network

| Direction | Port | Why |
|---|---|---|
| Employee PCs → server | TCP 80 / 443 (or 8080) | Agents report activity and fetch policy |
| Admin browsers → server | TCP 80 / 443 (or 8080) | The `/admin` console |
| Server → internet | **not required** | Only if you choose online licence validation |

Give the server a **fixed IP or DNS name** before you install — it is baked into the agent
configuration, and changing it later means reconfiguring every PC.

### 2.4 Decide these before you start

- The **company name** exactly as it should appear in reports
- The **admin email** for the first login
- Whether the company uses **biometric attendance** or **agent-only** attendance
- Your **shift timings** and break allowance

---

## 3. Windows — quick install (Laragon)

Best for a pilot, a small office, or an evaluation.

1. Install **Laragon Full** (bundles Apache, PHP and MySQL) and open it.
2. Click **Start All**. Confirm the traffic lights go green.
3. Unzip `SmartEPT-Admin-Server-Setup-1.1.zip` into `C:\laragon\www\`, giving you
   `C:\laragon\www\SmartEPT-Admin-Server\`.
4. Open that folder and double-click **`INSTALL.bat`**.
5. When prompted, enter your **admin email**, **company name** and a **password**
   (leave the password blank to have one generated and printed).
6. Open **`http://smartept.test/admin`** and sign in.

That is the whole install. Section 6 explains what those seven steps did.

---

## 4. Windows Server — production install with IIS

Use this when the console must run on port 80/443, start with Windows, and have no console
window.

### A. Prepare the server (once)

1. **IIS with CGI** — Server Manager → Add Roles and Features → *Web Server (IIS)* →
   Application Development → tick **CGI**.
   *(Windows 10/11 Pro: Turn Windows features on/off → IIS → Application Development
   Features → CGI.)*
2. **URL Rewrite module** — install from `https://www.iis.net/downloads/microsoft/url-rewrite`.
   This is not optional: the package ships a `public\web.config` that depends on it.
3. **PHP 8.3 NTS x64** — unzip from `windows.php.net/download` to `C:\PHP`, copy
   `php.ini-production` to `php.ini`, set `extension_dir = "ext"`, enable the seven
   extensions from §2.2, and add `C:\PHP` to the system PATH. Install the matching
   *Visual C++ Redistributable* if PHP complains on startup.
4. **Turn WebDAV OFF.** Do not skip this one — it bites every single time. IIS enables
   WebDAV by default and it intercepts the `PUT` and `DELETE` verbs before PHP sees them,
   so the console loads and creates records perfectly but **every Edit → Save and every
   Delete returns HTTP 405**. The shipped `web.config` removes it per-site, which is enough
   on most servers. If 405s persist, remove the feature: Server Manager → Remove Roles and
   Features → Web Server (IIS) → Common HTTP Features → untick **WebDAV Publishing** →
   then `iisreset`.
5. **MySQL 8 / MariaDB** — install it and note the credentials.

### B. Install the application

6. Unzip the package to **`C:\smartept`**. Keep it **out of** `C:\inetpub\wwwroot` — only
   the `public` subfolder is ever exposed to the web.
7. Open an **administrator** Command Prompt in `C:\smartept` and run **`INSTALL.bat`**.
   If MySQL has a root password, edit `DB_USERNAME` / `DB_PASSWORD` in `.env` first and
   re-run.

### C. Create the IIS site

8. IIS Manager → **Handler Mappings** (server level) → *Add Module Mapping*:
   Request path `*.php` · Module `FastCgiModule` · Executable `C:\PHP\php-cgi.exe` ·
   Name `PHP-FastCGI` → OK → "create FastCGI application" → **Yes**.
9. **Sites → Add Website:** name `SmartEPT`, physical path **`C:\smartept\public`** — the
   `public` folder, never the app root. Port 80, plus an HTTPS binding with the company
   certificate if you have one. Application pool: **No Managed Code**.
10. **Permissions** — grant `IIS_IUSRS` Modify on the two writable folders (below).
11. In `.env` set `APP_URL=http://<server-name-or-ip>`, then run `php artisan config:clear`
    and `php artisan storage:link`.

Step 10 in full:

```
cd C:\smartept
icacls storage /grant "IIS_IUSRS:(OI)(CI)M" /T
icacls bootstrap\cache /grant "IIS_IUSRS:(OI)(CI)M" /T
```

> **Security check before go-live:** browse to `http://<server>/.env`. If it downloads, the
> site is pointed at the app root instead of `public\`. Fix it immediately.

---

## 5. Linux and macOS

### Linux (Ubuntu / Debian / RHEL family)

```
unzip SmartEPT-Admin-Server-Setup-1.1.zip
cd SmartEPT-Admin-Server
sudo bash install-linux.sh
```

The installer checks PHP and its extensions, prepares `.env`, creates the database, migrates,
seeds roles, asks for your company and admin login, and — when run as root on a systemd
system — installs and starts a **`smartept-admin`** service that survives reboot.

Console: `http://<server-ip>:8080/admin`

Useful commands afterwards:

```
sudo systemctl status smartept-admin
sudo systemctl restart smartept-admin
sudo journalctl -u smartept-admin -f
```

For a production Linux deployment, put nginx or Apache in front with the document root at
`<app>/public` and a TLS certificate, rather than exposing port 8080 directly.

### macOS

```
unzip SmartEPT-Admin-Server-Setup-1.1.zip
cd SmartEPT-Admin-Server
bash install-macos.sh
```

Prerequisites: `brew install php mysql && brew services start mysql`. The installer adds a
launchd agent so the console starts at login. Console: `http://<mac-ip>:8080/admin`.

---

## 6. What the installer actually does

All three installers run the same seven steps through the same shared helper. Knowing them
makes any failure easy to place.

| Step | Action | Why it matters |
|---|---|---|
| 1 | Prepare `.env`, create the MySQL database | The stock config points at SQLite with no database file. This switches it to MySQL and creates the schema. |
| 2 | `composer install` — **only if `vendor/` is missing** | The package already ships `vendor/`, so this is normally skipped and no internet is needed. |
| 3 | `php artisan key:generate` | **Without an app key every page is HTTP 500.** The single most common cause of a failed setup. |
| 4 | `php artisan migrate` | Creates all 88 database tables. |
| 5 | Seed roles and permissions | Roles must exist before any admin can. Client servers get roles **only** — never demo data. |
| 6 | `storage:link` and clear caches | Links stored evidence and starts from a clean cache. |
| 7 | Create the first admin login | Without it there are no credentials to sign in with. |

Step 1 also sets the on-premise licence posture: `SMARTEPT_ONPREM=true` and
`SMARTEPT_LICENCE_ENFORCE=true`, which starts the 7-day evaluation and enables the
`/activate` page.

---

## 7. The background scheduler — required

Several core features are **scheduled jobs, not live code**. Without the scheduler running,
nightly attendance never completes, productivity summaries are never built, biometric punches
never sync, meetings never auto-close, and the new post-shift auto sign-out never fires.

**Windows** — one Task Scheduler job, every minute, as SYSTEM:

```
schtasks /Create /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM ^
  /TR "C:\PHP\php.exe C:\smartept\artisan schedule:run"
```

**Linux / macOS** — one crontab line:

```
* * * * * cd /path/to/SmartEPT-Admin-Server && php artisan schedule:run >> /dev/null 2>&1
```

**Verify it:** in the console, **Help → Troubleshooting**. The scheduler heartbeat turns red
within minutes if `schedule:run` is not being called. Check it on every install.

### What runs, and when

| Job | Schedule | Purpose |
|---|---|---|
| `smartept:auto-logout` | every 5 min | **New:** signs out agents that never signed out, at shift end + N minutes |
| `smartept:biometric-sync` | every 5 min | Imports punches from cloud biometric devices |
| `smartept:close-meetings` | every minute | Ends meeting sessions at their scheduled end |
| `smartept:mark-attendance` | 00:15 | Completes yesterday's attendance sheet |
| `smartept:daily-summary` | 00:30 | Builds productivity summaries |
| `smartept:validate-license` | 01:00 | Daily licence check |
| `smartept:backup-database` | 01:30 | Nightly gzipped backup (keeps 14) |
| `smartept:purge-expired` | 02:00 | Applies your retention policy |

---

## 8. Licensing and activation

1. Installation starts a **7-day evaluation** automatically — full features, no key needed.
2. To license, open **`http://<server>/activate`**. No sign-in is required.
3. Copy the **machine fingerprint** shown there and send it to Ametecs.
4. Ametecs issues a **`.lic`** file for that fingerprint. Upload it on the same page.
   Activation is **instant and fully offline** — the server never contacts the internet.
5. The licence is **node-locked**: it works only on the machine whose fingerprint it carries.
   If the server is replaced, Ametecs shifts the licence and issues a new `.lic`; the old
   machine stops validating and the history keeps both.
6. If the evaluation ends with no licence, monitoring stops but the console and `/activate`
   stay reachable, so you can license at any time.

> Take the fingerprint **from the machine that will run in production**. A fingerprint from a
> test VM produces a licence that will not work on the real server.

---

## 9. First sign-in and initial setup

Work through this order — later steps depend on earlier ones.

### 9.1 Company

**Organisation → Company.** Set the company name, **timezone** (this drives every report
boundary — get it right first), and the attendance source:

- **With biometric device** — the Gate-to-PC rule applies: an employee must punch at the
  door before their PC starts tracking.
- **Without biometric device** — agent login alone establishes attendance.

### 9.2 Organisation structure

Create in this order: **Branches → Departments → Teams → Designations → Shifts**. Bulk
employee import creates any of these by name automatically, so if you are importing a
prepared sheet you can skip ahead.

**Shifts** carry the fields the productivity report depends on:

| Field | Notes |
|---|---|
| Start / End | Drives late arrival and early logout |
| Grace (min) | Late-arrival tolerance |
| Break allowed (min) | Pro-rated to actual presence in the report |
| **Auto sign-out after shift end (min)** | **New in 1.1** — see §9.3 |

### 9.3 Post-shift auto sign-out — new in 1.1

If an employee never signs out, their session stays open and the productivity report has no
sound end time to work from. Version 1.1 lets the server close the session itself.

Set it in **either** place:

- **Organisation → Shifts → Auto sign-out after shift end (min)** — per shift
- **Policies → Attendance → Auto sign-out after working end time (min)** — the fallback

The **shift value wins** when both are set. Blank in both means the feature is off, which is
the default, so nothing changes until you configure it.

A sensible starting point is **60 minutes**. The sign-out is stamped at *shift end + N*, not
at the moment the job runs, so a PC left on overnight is never credited the extra hours. A
real punch-out or a manual HR correction always takes precedence.

### 9.4 Attendance policy

**Policies → Attendance:** late grace, early-logout grace, minimum working hours, attendance
sources, and the auto sign-out fallback above. Assign the policy to the company (or to a
branch/department/team for a narrower rule).

### 9.5 Monitoring, screenshot and webcam policies

**Policies → Monitoring / Screenshot / Webcam.** Assign each to the company, or lower down
the tree for exceptions. Most specific wins:
**Device > Employee > Team > Department > Branch > Company.**

**Webcam presence and webcam photos are two separate settings:**

| Setting | What it does | Where it shows |
|---|---|---|
| `presence_enabled` | Detects presence locally and reports **numbers only** — never an image | Webcam tab → *Webcam presence detected* |
| `photo_enabled` | Captures and uploads actual photos | Webcam tab → *Webcam presence photos* |

The shipped default is **presence on, photos off**. If you expect photos, tick
`photo_enabled` explicitly.

> **Important:** tracking mode **PRESENCE_ONLY** switches webcam presence **off**, despite the
> name. It means "presence at work" (attendance only), not face presence. For webcam presence
> the employee's tracking mode must be **FULL**.

### 9.6 Employees

**Employees → Download CSV template**, fill it, then **Bulk import (CSV)**.

Columns (in order):

```
employee_code, first_name, last_name, email, mobile, department, team, branch,
designation, shift, date_of_joining, biometric_id, employment_status, password
```

- Only `employee_code` and `first_name` are required.
- Department, team, branch, designation and shift are matched **by name** and created if new.
- **`password` (new in 1.1)** sets that employee's own login password, minimum 8 characters.
  Leave it blank to generate a temporary one that must be changed at first sign-in.
- Use **Preview (dry run)** first — it validates every row and changes nothing.
- **Export employees (CSV)** (new in 1.1) produces exactly these columns, so an export can be
  edited and re-imported. Passwords export blank, because they are stored hashed.

### 9.7 Users and roles

**Users** creates console logins (Company Admin, HR Admin, Branch Admin, Manager, Team Leader,
Auditor, Compliance Officer). Employee self-service logins come from the import; console
logins are separate and are created here.

---

## 10. Installing the desktop agent

1. From the client portal, download **`SmartEPT-Agent-Setup-<version>.exe`**.
2. Run it on each employee PC (per-machine install — administrator rights needed).
3. On first launch, enter the **server URL** — `http://<server>` or `http://<server>:8080`.
4. The employee signs in with the credentials from §9.6.
5. Confirm the PC appears under **Devices** in the console within a minute or two.

For a wide rollout, deploy the installer through your usual software-distribution tool and
pre-seed the server URL with a `server.json` file beside the executable.

---

## 11. Upgrading an existing installation

1. **Back up first** — the database and the whole app folder.
2. Stop the site (IIS: stop the site; Linux: `sudo systemctl stop smartept-admin`).
3. Unzip the new package **over** the existing folder. Your `.env`, `license.lic` and
   `storage/` contents are not in the package and are left untouched.
4. Run the migrations (below). On Windows you can double-click `migrate.bat`, then `cache.bat`.
5. Start the site again and confirm **Help → Troubleshooting → System Health** is all green.

Step 4 in full:

```
php artisan migrate --force
php artisan optimize:clear
```

Version 1.1 adds one migration (post-shift auto sign-out) and is backward compatible — the
new columns default to "off".

---

## 12. Backups

A nightly gzipped database backup runs automatically at 01:30 into `storage/app/backups`,
keeping the newest 14. That protects the database only. **Also back up:**

- `.env` — your configuration and app key. **Without the app key, encrypted data is
  unrecoverable.**
- `license.lic` — your licence file
- `storage/app/` — stored screenshots, webcam photos and employee archives

Copy these off the server. A backup that lives only on the machine it protects is not a backup.

---

## 13. Troubleshooting

Start at **Help → Troubleshooting → System Health** inside the console. One click shows which
component is red, with a fix link. **Help → Application log** has "Copy for developer" for
support tickets.

| Symptom | Cause | Fix |
|---|---|---|
| Every page is HTTP 500, "No application encryption key" | App key missing | Run `INSTALL.bat`, or `php artisan key:generate` then `cache.bat` |
| HTTP 405 on every Edit/Save and Delete, but pages load and Create works | **WebDAV** is intercepting PUT/DELETE | §4 step 4, then `iisreset` |
| HTTP 500.19 | URL Rewrite module missing | §4 step 2 |
| PHP files download instead of running | Handler mapping missing | §4 step 8 |
| "Could not connect / create the database" | MySQL down or wrong credentials | Start MySQL; check `DB_USERNAME` / `DB_PASSWORD` in `.env` |
| "pdo_mysql extension is not enabled" | Extension off | Enable `extension=pdo_mysql` in `php.ini`, restart |
| Login works but no data | `.env` still on SQLite | Set `DB_CONNECTION=mysql`, `DB_DATABASE=smartept`, re-run `INSTALL.bat` |
| "table doesn't exist" after an upgrade | Migrations not run | `migrate.bat`, or `php artisan migrate --force` |
| Cannot sign in on a brand-new install | Admin never created | `php artisan smartept:make-admin admin@company.com --company="Your Company"` |
| Changes never take effect | PHP OPcache frozen | Set `opcache.validate_timestamps=1` in `php.ini`, then a full **Stop All → Start All** |
| Attendance and reports stop updating overnight | Scheduler not running | §7 — check the heartbeat in Help → Troubleshooting |
| Agent shows "punch in at the door" | Biometric gate is on | Organisation → Company → attendance source, or Gate Exclusion policy |
| Webcam presence shows nothing | Presence off, or tracking mode not FULL | §9.5. On the PC, check `%APPDATA%\smartept-agent\presence.log` |
| `/storage/...` images 403 or 404 | `storage:link` not run | `php artisan storage:link` |

### Reset an admin password

```
php artisan smartept:make-admin someone@company.com --company="Your Company"
```

Prints a fresh temporary password. Add `--password=YourPass` to set a specific one, or
`--super` for a super-admin.

---

## 14. Support

**Ametecs India Private Limited**
Email `sales@ametecsindia.com` · WhatsApp **90000 98877**

When reporting a problem, include: the SmartEPT version, the operating system and PHP version,
what you were doing, and the output of **Help → Application log → Copy for developer**.

---

*© 2026 SmartEPT, developed by Ametecs India Private Limited. All rights reserved.*
