# SmartEPT Admin Console — Install & Troubleshooting Guide

For installing the SmartEPT **admin console** (the client's on-premise `/admin` panel) on
**Windows** (Laragon: Apache/Nginx + PHP + MySQL/MariaDB), **Linux** or **macOS**.

---

## Which installer do I run?

| Your server | Run | Auto-start |
|---|---|---|
| Windows | double-click **`INSTALL.bat`** | run `START-SMARTEPT.bat` / add to Startup |
| Linux (Ubuntu/Debian/RHEL) | `sudo bash install-linux.sh` | systemd service `smartept-admin` (installed for you) |
| macOS | `bash install-macos.sh` | launchd agent (installed for you, starts at login) |

All three do the same seven steps below, using the same shared helper — the only
difference is the operating-system wrapper. Prerequisites on Linux/macOS: **PHP 8.2+**
and **MySQL/MariaDB** running (the installer names the exact `apt`/`brew` commands if
anything is missing). After install the console is at **`http://<server>:8080/admin`**.

---

## Production install on Windows Server with IIS

Use this instead of `START-SMARTEPT.bat` when the client wants the console served by IIS
(port 80/443, auto-start with Windows, no console window).

**A. One-time server preparation**

1. **IIS + CGI:** Server Manager → Add Roles and Features → *Web Server (IIS)* →
   Application Development → tick **CGI**. (On Windows 10/11 Pro: Turn Windows features
   on/off → Internet Information Services → Application Development Features → CGI.)
2. **URL Rewrite module:** download and install from
   `https://www.iis.net/downloads/microsoft/url-rewrite` (required — the package ships a
   `public\web.config` that depends on it).
3. **PHP 8.2+ NTS x64:** unzip from `windows.php.net/download` to `C:\PHP`, copy
   `php.ini-production` → `php.ini`, set `extension_dir = "ext"` and enable:
   `pdo_mysql, openssl, mbstring, curl, zip, gd, fileinfo`. Install the matching
   *Visual C++ Redistributable* if PHP complains. Add `C:\PHP` to the system PATH.
4. **Turn WebDAV OFF** (do not skip — this one bites every time). IIS enables WebDAV by
   default and it grabs the `PUT` and `DELETE` verbs before PHP sees them, so the console
   loads and creates records fine but **every Edit → Save and every Delete answers
   HTTP 405**. The shipped `public\web.config` removes it per-site, which is enough on
   most servers; if 405s persist, remove the feature itself:
   Server Manager → Remove Roles and Features → Web Server (IIS) → Common HTTP Features →
   untick **WebDAV Publishing** (Windows 10/11: Turn Windows features on/off → IIS →
   World Wide Web Services → Common HTTP Features → WebDAV Publishing) → then `iisreset`.
5. **MySQL 8 / MariaDB:** install and note the root (or dedicated) credentials.

**B. Application install**

6. Unzip `SmartEPT-Admin-Server-Setup-<ver>.zip` to **`C:\smartept`** (keep it OUT of
   `C:\inetpub\wwwroot` — only the `public` subfolder is ever exposed).
7. Open an **administrator** Command Prompt in `C:\smartept` and run **`INSTALL.bat`** —
   it prepares `.env` (edit `DB_USERNAME`/`DB_PASSWORD` first if MySQL has a password),
   creates the database, migrates, seeds roles only, and asks for your company, admin
   email and password (clean workspace — no demo data).

**C. IIS site**

8. IIS Manager → **Handler Mappings** (server level) → *Add Module Mapping*:
   Request path `*.php` · Module `FastCgiModule` · Executable `C:\PHP\php-cgi.exe` ·
   Name `PHP-FastCGI` → OK → "create FastCGI application" → Yes.
9. **Sites → Add Website:** name `SmartEPT`, physical path **`C:\smartept\public`**
   (the `public` folder, never the app root), port 80 (add an HTTPS binding with the
   company certificate if available). Application pool: **No Managed Code**.
10. **Permissions:** grant `IIS_IUSRS` *Modify* on `C:\smartept\storage` and
   `C:\smartept\bootstrap\cache`:
   `icacls C:\smartept\storage /grant "IIS_IUSRS:(OI)(CI)M" /T` (same for bootstrap\cache).
11. In `.env` set `APP_URL=http://<server-name-or-ip>` then run
    `php artisan config:clear` and `php artisan storage:link`.

**D. Background scheduler (required — attendance, reports, alerts)**

12. Create a Task Scheduler job running **every minute** as SYSTEM:
    ```
    schtasks /Create /TN "SmartEPT Scheduler" /SC MINUTE /MO 1 /RU SYSTEM ^
      /TR "C:\PHP\php.exe C:\smartept\artisan schedule:run"
    ```

**E. Verify + license**

13. Browse `http://<server>/admin` → sign in with the admin created in step 7 →
    Help → Troubleshooting → **System Health**: everything green.
14. License via `http://<server>/activate` (fingerprint → Ametecs → upload `.lic`),
    exactly as in the Licensing section below. Firewall: allow TCP 80/443 inbound so
    employee PCs (agents) can reach the server.

*Troubleshooting IIS:*
- **HTTP 405 on every Edit/Save or Delete (but pages load and Create works)** = WebDAV is
  still enabled — the single most common IIS problem. Confirm with
  `curl.exe -i -X PUT http://<server>/api/org/branch/1`: a 405 with an `Allow:` header
  listing GET/POST/OPTIONS is WebDAV answering, not SmartEPT. Fix per step 4, then
  `iisreset`. A correct server answers `401 {"message":"Unauthenticated."}` to that curl.
- HTTP 500.19 = URL Rewrite module missing (step 2).
- PHP page downloads instead of running = handler mapping missing (step 8).
- 500 with blank page = run step 10 permissions + check `storage\logs\laravel.log`.
- 403/404 on `/storage/...` images = `php artisan storage:link` not run (step 11).
- **Site physical path must be the `public` subfolder, never the app root** — if you can
  download `http://<server>/.env`, the site is pointed at the wrong folder. Fix it before
  the server goes live.

---

## Licensing (all operating systems — SmartPRS2 standard)

1. Installation starts a **7-day evaluation** automatically — full features, no key needed.
2. To license: open **`http://<server>:8080/activate`** (no sign-in needed), copy the
   **machine fingerprint** shown there and send it to Ametecs.
3. Ametecs issues a **`.lic`** licence file for that fingerprint. Upload it on the same
   `/activate` page — activation is **instant and fully offline** (no internet ever needed).
4. The `.lic` is **node-locked**: it works only on the machine whose fingerprint it carries.
   Server died or replaced? Ametecs "shifts" the licence and issues a new `.lic` — the old
   machine stops validating, history keeps both.
5. When the evaluation ends with no licence, monitoring blocks until a valid `.lic` is
   uploaded — the console and `/activate` stay reachable.

---

## The easy way — one click

1. Make sure **Laragon is running** (open Laragon → **Start All**).
2. In the SmartEPT app folder (e.g. `C:\laragon\www\smartept`), double-click **`INSTALL.bat`**.
3. When asked, type an **admin email** and your **company name**.
4. When it finishes, it prints a **temporary password**. Open the console and sign in.

**Open the console:** `http://smartept.test/admin` (local) or `https://<your-server>/admin`.
Sign in with the email + temporary password shown by the installer. You will be asked to set
your own password on first login.

`INSTALL.bat` does all seven steps a fresh install needs — the earlier `migrate.bat` only did
step 4, which is why setup used to fail:

| Step | What it does | Why it matters |
|---|---|---|
| 1 | Prepare `.env` + create the MySQL database | Stock config uses SQLite with no DB — this switches it to MySQL and creates `smartept`. |
| 2 | `composer install` (only if `vendor` is missing) | The app can't run without its libraries. |
| 3 | `php artisan key:generate` | **Without an app key, every page is HTTP 500.** The #1 cause of a failed setup. |
| 4 | `php artisan migrate` | Creates all the database tables. |
| 5 | Seed roles & permissions | Needed before any admin can exist. |
| 6 | `storage:link` + clear caches | Evidence/files link + a clean cache. |
| 7 | Create the first admin login | **Without this there are no credentials to log in with.** |

---

## Why setup was failing (root cause)

A fresh copy ships Laravel's default `.env.example`, which has:

- `DB_CONNECTION=sqlite` — points at a database file that doesn't exist, and
- `APP_KEY=` (empty) — so the admin panel returns **HTTP 500 "No application encryption key"** on
  every page.

Running only `migrate.bat` never fixed those, never created the MySQL database, and never
created an admin login — so the panel either 500'd, connected to an empty database, or loaded
with no account to sign in with. `INSTALL.bat` closes all of those gaps.

---

## Common errors and their fixes

**"No application encryption key has been specified." / every page is a 500.**
The app key is missing. Fix: run `INSTALL.bat`, or manually `php artisan key:generate` then
`cache.bat`.

**Login works but no data, or "connected to SQLite".**
`.env` is still on SQLite or points at the wrong database. Fix: set `DB_CONNECTION=mysql` and
`DB_DATABASE=smartept` in `.env`, then re-run `INSTALL.bat`. (System Health → the "Database
connection" row will be red and name SQLite when this is the problem.)

**"Could not connect / create the database."**
MySQL isn't running or the credentials are wrong. Fix: Laragon → **Start All**; check
`DB_USERNAME`/`DB_PASSWORD` in `.env` (Laragon default is `root` / blank).

**"SQLSTATE... table doesn't exist" or a feature/screen errors after an update.**
Migrations haven't been run for the new tables. Fix: run `migrate.bat` (or `INSTALL.bat`).

**"There is no login" / can't sign in on a brand-new install.**
Roles/admin were never seeded. Fix: run `INSTALL.bat` (step 7), or manually:
`php artisan smartept:make-admin admin@yourcompany.com --company="Your Company"`.

**Changes/fixes don't take effect no matter what.**
PHP's OPcache is frozen. Fix: in `php.ini` set `opcache.validate_timestamps=1`, then Laragon
**Stop All → Start All** (a full stop, not reload).

**"pdo_mysql extension is not enabled."**
Enable `extension=pdo_mysql` in `php.ini` (Laragon → Menu → PHP → php.ini), then Stop All →
Start All.

**"composer.json does not contain valid JSON."**
In the terminal, type one command per line and never name a variable `COMPOSER`. Example:
`cd /d C:\laragon\www\smartept` on its own line, then the `composer ...` line.

---

## Reset an admin password / add another admin

```
php artisan smartept:make-admin someone@company.com --company="Your Company"
```
Prints a fresh temporary password. Add `--super` for a super-admin (no company), or
`--password=YourPass` to set a specific one.

---

## Still stuck?

Inside the console, open **Help → Troubleshooting**:
- **System Health** — one click tells you exactly which piece (database, key, storage, migrations)
  is red, with a "How to fix this" link.
- **Application log** — "Load log" then "Copy for developer" and send it to support:
  **WhatsApp 90000 98877**.

---

© 2026 SmartEPT, developed by Ametecs India Private Limited — all rights reserved.
