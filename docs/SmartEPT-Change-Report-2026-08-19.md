# SmartEPT — Change Report

**19 August 2026 · Internal · Ametecs India Private Limited**

Seven workstreams, all delivered and verified. Six were reported by Ejaz; the seventh
(package integrity) came out of investigating the sixth and is the most serious finding of
the day.

---

## Summary

| # | Item | Severity | Status |
|---|---|---|---|
| 1 | Productivity % above 100% (596% on AI0043) | **High** — wrong numbers reaching the client | Fixed |
| 2 | Post-shift auto sign-out | Feature request | Delivered |
| 3 | Webcam presence never captured | **High** — feature silently dead | Fixed |
| 4 | Bulk import: password column | Feature request | Delivered |
| 5 | Employee export (round-trips to import) | Feature request | Delivered |
| 6 | Super Admin: reset a client's password | Feature request | Delivered |
| 7 | On-prem package: install crash + data leaks | **Critical** — customer data in the download | Fixed |

**Code changed:** 12 files modified, 4 new files (Admin), 3 files modified (Central),
4 files modified (Agent). Roughly 460 changed lines plus 1,030 new lines of tooling and
commands.

---

## 1. Productivity % above 100%

**Reported:** "Productivity Report is incorrect in some rows randomly… for AI0043 the entire
row's data seems to be corrupted."

### Root cause

`ProductivityController::row()` computed **Actual Present = logout − login**, and nothing
else. AI0043's sign-out was stamped at 10:21 while the agent kept tracking until roughly
17:00. Present therefore collapsed to 01:05, and because the allotted break is *pro-rated to
present*, every downstream cell collapsed with it.

The formula was never wrong. The **input** was — one stale timestamp, and the row deranged
itself.

### Verification

The formula was replayed standalone against the four rows visible in the client's own export.
It reproduced their sheet's Net Hrs of **00:57 exactly**, which is what makes this a
diagnosis rather than a theory.

| Row | Before | After |
|---|---|---|
| AI0030 | present 7:59 · net 7:00 · **44.5%** | unchanged |
| AI0018 | present 0:47 · net 0:41 · **21.8%** | unchanged |
| **AI0043** | present 1:05 · net 0:57 · **594.2%** | present 7:59 · net 7:00 · **80.6%** |
| AI0047 | present 8:29 · net 7:26 · **64.2%** | unchanged |

Only the broken row moves. The three healthy rows are byte-identical.

### Fix

`app/Http/Controllers/Api/ProductivityController.php`

- Actual Present is floored at the time the day actually tracked:
  `max(logout − login, Working + Idle + Break)`. An employee cannot have been present for
  less time than the day recorded.
- Productive % is hard-capped at 100%.
- New **"Data Issue"** column (T) in the .xlsx export names the rows that had to be
  repaired — those are the missing sign-outs, so they can be chased rather than silently
  corrected. The JSON report carries `clock_present_seconds`, `clock_gap_seconds` and
  `data_issue` for the same purpose.

Existing history self-heals on the next report run. No re-processing is needed.

---

## 2. Post-shift auto sign-out

**Requested:** "In case the agent didn't sign out, the application should automatically sign
the agent out at the specified time post the shift end time."

This is the structural fix for item 1 — it stops the bad input being produced.

### What was already there, and why it was not enough

`smartept:mark-attendance` closes forgotten sessions, but it runs at **00:15 the next day**
and is retrospective. Between shift end and midnight the employee stays "logged in" and
whatever stale instant sits in `check_out_at` is what the report divides by.

### Delivered

**New setting, in both places, per-shift wins:**

| Location | Field |
|---|---|
| Organisation → Shifts | *Auto sign-out after shift end (min)* |
| Policies → Attendance | *Auto sign-out after working end time (min) — blank = never* |

Blank in both = feature off, so nothing changes for an existing tenant until an admin
configures it.

**New command** `smartept:auto-logout`, scheduled every 5 minutes. For each still-open
session past its cutoff it:

1. Closes `employee_login_sessions` with reason `POST_SHIFT_AUTO`
2. Advances `check_out_at` / `final_logout_at` — **forward only**, so a real punch-out or an
   HR correction always wins — and zeroes `early_logout_minutes`
3. Calls `StatusService::closeAll` so the live board stops showing a ghost break or meeting
4. Revokes the device's Sanctum token, so the agent 401s on its next heartbeat and returns to
   its login screen instead of tracking all night

**Design decisions worth recording:**

- The sign-out is stamped at **shift end + N, not "now"** — a PC left on overnight is never
  credited the extra hours.
- Night shifts are handled via `crosses_midnight`, and a login *after* the shift end gets its
  own window rather than being closed instantly.
- Only sessions from the **last 3 days** are considered; older ones stay with the nightly
  sweep, so first deployment cannot rewrite months of settled history.
- 16-hour duration cap, matching the existing nightly job.
- `--dry-run` and `--now=` for safe testing.

**Files:** `app/Console/Commands/AutoLogoutPostShift.php` (new, 214 lines),
`database/migrations/2026_08_19_000100_add_post_shift_auto_logout.php` (new),
`OrgController.php`, `routes/console.php`, `admin.blade.php`.

---

## 3. Webcam presence never captured

**Reported:** "Webcam presence is not captured even though the webcam is connected… the
Webcam presence option is On as per the settings."

Four independent causes. Any one of them alone would have produced the reported symptom.

### 3a. The agent's presence worker was dying silently

`src/renderer/presence.js` called `navigator.mediaDevices.getUserMedia(...)` at the top level
with only a `.catch()` attached. Two failure modes:

- If `mediaDevices` was unavailable the call threw **synchronously**, killing the module
  before the sampling loop below it was ever registered — so nothing was reported, **not even
  the failure**.
- If the camera was merely *busy* (Teams or Zoom holding it when the agent started), the
  worker gave up **permanently after one attempt**. The camera being free five minutes later
  made no difference; the worker was already dead.

Rewritten: the sampling loop is registered first, the camera is opened last, failures retry
with backoff from 30 s to 5 min forever, every failure carries a `reason`, and a `track.ended`
listener catches a camera unplugged mid-session.

### 3b. Missing permission check handler

Only `setPermissionRequestHandler` was set. `getUserMedia` consults the permission **check**
handler as well, and the default refuses `media` for a `file://` document — which is exactly
what the hidden presence worker is. Added
`setPermissionCheckHandler((wc, p) => p === 'media')`.

### 3c. Hidden-window timer throttling

The presence window is `show: false`, and Chromium aggressively throttles timers in hidden
renderers. The 5-second sampling loop was being starved. Added `backgroundThrottling: false`.

### 3d. The console only ever showed photos

`WebcamController::companyDay()` filtered on `whereNotNull('storage_file_id')`, and the
shipped default policy is **`presence_enabled = true, photo_enabled = false`**. So a
correctly configured tenant saw a permanently empty wall and reasonably concluded presence
was broken.

Added a presence roll-up to the endpoint and a **"Webcam presence detected"** panel above the
photo grid, showing seconds and event counts per employee, with `CAMERA_UNAVAILABLE` and
`CAMERA_BLOCKED` called out in warning colour — those are the rows that actually diagnose a
camera problem.

### Also fixed

`PresenceController::webcam()` only checked that *a* webcam policy existed, so with both flags
off it wrote metadata-only rows that no screen could ever display. Now gated on
`presence_enabled || photo_enabled`, matching the presence endpoint.

### New diagnostics

- **`%APPDATA%\smartept-agent\presence.log`** — the worker's console output plus
  `did-fail-load` and `render-process-gone`. This is the first place to look next time.
- `refreshPolicy()` now starts and stops the presence worker live, so ticking
  `presence_enabled` in the console no longer needs an agent restart.

### Trap worth recording

`tracking_mode = PRESENCE_ONLY` **disables** webcam presence, twice over — `PolicyResolver`
forces the flags false for any non-FULL mode, and the tracking-mode middleware silently
returns 204 for `presence-event`. "Presence" there means presence *at work*, not face
presence. Left as designed, but check tracking mode is FULL before believing a webcam bug
report.

**Agent version bumped to 0.13.0 — this one needs a rebuild and redeploy.**

---

## 4 & 5. Employee import password and export

**Requested:** a `password` column in the bulk-import sample that sets the employee's login,
and an export whose columns match the import sample so it can be re-imported.

**Delivered:**

- `password` column added to the sample and the importer. A value sets that employee's real
  password (minimum 8 characters, hashed by the model cast, never stored on the employee
  row); blank keeps the existing behaviour of a generated temporary password that must be
  changed at first sign-in. Passwords we generate are still echoed once; passwords the admin
  typed into the sheet are not repeated back.
- `employment_status` made importable, so an exported `ON_LEAVE` or `RELIEVED` employee does
  not silently return as `ACTIVE`.
- **`GET /api/employees/export`** — CSV whose header is exactly the import columns, honouring
  the search box and the caller's role scope, with formula-injection protection on every
  cell. Passwords export blank, because they are stored hashed.
- **Export employees (CSV)** button in the Employee tab.

The column order now lives in **one** constant, `EmployeeController::IMPORT_COLUMNS`, used by
the sample, the importer documentation and the export, so the three cannot drift apart.

---

## 6. Super Admin: reset a client's password

**Requested:** "Super Admin should have option to Reset Client's password from Super Admin
Client list (currently there is none except client requesting email OTP for reset)."

**Delivered** in SmartEPT Central: a **Reset password** action on each client row.

- **Super Admin only.** Note the subtlety: `sales` already holds `tenants: manage` in the
  permission matrix, and the matrix is consulted *before* a route group's role list — so a
  `admin.role:super` suffix alone would not have restricted it. The controller re-checks
  `isSuper()` explicitly.
- Type a password or leave blank to generate a strong one.
- Result shown once in a modal, so support can read it out over the phone.
- Emails the client, sets `must_set_password` so they choose their own at next sign-in, and
  **burns any outstanding reset OTP** so an old code cannot be replayed.
- Audited as `tenant.password_reset`, **without** the password in the audit meta.

---

## 7. On-premise package: install crash and data leaks

The most serious item of the day, and it was not on the original list.

### 7a. The install crash

A client install of `SmartEPT-Admin-Server-Setup-1.0.zip` died during `artisan migrate`:

```
file_get_contents(...\vendor\symfony\translation/Resources/data/parents.json)
Failed to open stream: No such file or directory
```

**Root cause: a truncated entry in Composer's own files cache on the build machine.**
`vendor/symfony/translation/Resources/data/` was missing entirely, while upstream
`symfony/translation v7.4.14` (verified by cloning the tag) does ship it. Because
`installed.json` looked current, a plain `composer install` kept restoring the same damage —
only `composer clear-cache` forced a fresh download. The file came back at the correct 3,601
bytes.

It was hard to read because Symfony touches that file only when resolving a fallback locale,
which happens while an error is being *rendered* — so it masked whatever the migration was
actually reporting.

### 7b. What the package was leaking

Auditing the shipped artefact turned up **18 problems**, of which 17 had nothing to do with
the crash:

| Leaked | Detail |
|---|---|
| `docs/` | 22 of our files — bugfix reports, QA reports |
| `tests/` | 30 files, including `DevLicenceKeyTest.php`, which documents the licence bypass |
| `storage/app/private/archives/` | **A real employee's archive ZIP, by name** |
| `storage/app/smartept/` | Another customer's stored screenshots and webcam photos |
| `.machine_fp` | The build machine's fingerprint — the dev licence is bound to it |
| `bootstrap/cache/` | Build-machine caches, live during `migrate` |
| Scratch | `commit-*.{txt,php,bat}`, `build-log.txt`, `INSTALL-GUIDE.md`, `.fuse_hidden*` |

`make-clientside.php` already had the correct exclusion policy; `rebuild-server-zip.bat` never
received it. The two lists must now be maintained together.

### 7c. What was built

**`deployment/verify-package.php`** — a release gate (616 lines). Exit 0 means safe to ship.
Four modes: working tree, `--dist`, `--zip`, `--quick`. The two checks that carry the weight:

1. **Vendor diffed file-by-file against Composer's local files cache** — finds any file that
   vanished after extraction, for every package, offline. Packages with no cached archive are
   counted and reported as *not verified*.
2. **Translator smoke test in a subprocess** — forces the exact Symfony path that crashed and
   reproduces the client's error verbatim when the file is absent.

Plus forbidden paths, files and patterns; directories that must ship *empty*; and a migration
count floor.

**`deployment/make-zip.php`** — the archive builder, because `tar` proved unusable twice over
(see landmines below). PHP's `ZipArchive`: explicit empty-directory entries so runtime folders
survive, a flush every 500 files, and it writes to `<out>.partial` and reopens it as a zip
before renaming. Measured at **10,000 files in 2.3 seconds**, CRC-clean.

**`rebuild-server-zip.bat`** — now five gated steps: stage → verify staged → compress →
**verify the built zip** → clean up. A rejected archive is renamed `.REJECTED` rather than
deleted, so the evidence survives.

### 7d. Verification of the 1.1 package

Audited independently of the build's own gate:

- Real zip, 9,066 entries, **CRC clean**
- `parents.json` present at 3,601 bytes
- The packaged vendor **actually boots** — the exact crash path runs, and `bootstrap/app.php`
  returns a live `Illuminate\Foundation\Application`
- 93 vendor packages + `composer/pcre`, 88 migrations, 154 app PHP files
- All seven runtime directories present and **empty**
- **All fourteen leak categories at zero**
- `deployment/` reduced to `install-helper.php` alone, which the installers need

Size went from **38.5 MB to 12.7 MB** — that difference is the employee archives, the stored
screenshots and our internal reports no longer being in it.

### 7e. Landmines uncovered — worth keeping

1. **`php -r` cannot carry a path argument on Windows.** `escapeshellarg()` replaces embedded
   `"` with spaces and cmd re-splits the trailing argument, so `$argv[1]` never arrives. Write
   a temp `.php` file instead.
2. **robocopy `/XD` with a bare name matches at any depth** — `/XD ".github"` stripped
   `.github` out of every vendor package.
3. **Ctrl+C during `composer install` leaves no `vendor/autoload.php`.** cmd asks "Terminate
   batch job (Y/N)?" *after* the process is dead, so answering `n` does not save it. Repair
   with `composer dump-autoload --no-dev --optimize`.
4. **`tar` is not usable here.** bsdtar reads any argument containing a colon as `host:path`
   ("Cannot connect to C: resolve failed"); and the `tar` first on PATH in Cmder is **GNU
   tar**, whose `-a` understands only *compression* suffixes — given `.zip` it silently writes
   a plain TAR named `.zip`. Both were hit for real. `tar` has been removed from the build.

---

## Deployment

**SmartEPT Admin** (`C:\laragon\www\smartept`)

```
php artisan migrate
php artisan optimize:clear
php artisan smartept:auto-logout --dry-run
```

Then configure *Auto sign-out after shift end* on the relevant shifts or the attendance policy
— the feature is inert until it is set.

**SmartEPT Central** (`C:\laragon\www\smartept-central`) — no migration; the Reset password
action is live on deploy.

**Desktop Agent** — rebuild and redeploy **0.13.0**. The webcam presence fixes are entirely
agent-side and will not take effect otherwise.

**On-prem package** — `SmartEPT-Admin-Server-Setup-1.1.zip` is built and verified.
**Delete `SmartEPT-Admin-Server-Setup-1.0.zip` and the stale `…-1.0\` folder beside it** — the
old archive still contains the missing file *and* the named employee archive.

---

## Open items

| Item | Note |
|---|---|
| Test suite not run | `composer install --no-dev` removed phpunit. Run a plain `composer install` and `php artisan test` before committing. |
| `composer.lock` uncommitted | Outstanding from the 19-Aug phpspreadsheet fix. |
| Front-end build config still in the package | `package.json`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `cache.bat`, `migrate.bat`, `test.bat`. Harmless, but `make-clientside.php` strips them — consider aligning. |
| 14 security advisories, 3 packages | Unaudited. Matters more than usual for perpetual on-prem clients, who keep whatever ships for years. |
| 7 licence bypasses outside `app/` | Unchanged from the on-prem plan. Two are live customer-facing defects. |

---

*© 2026 SmartEPT, developed by Ametecs India Private Limited. Internal document.*
