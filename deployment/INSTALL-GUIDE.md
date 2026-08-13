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
