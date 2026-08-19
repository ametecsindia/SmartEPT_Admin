# Manual Test Plan — Gate-to-PC changes

**SmartEPT by Ametecs · 19 August 2026 · For the QA / implementation team**

---

## 1. Why we are doing this

Two changes were made to **Gate-to-PC** — the feature that stops an employee's PC from starting
a work session until they physically punch in at the door. Both are finished and unit-tested,
but neither has been through a human on a real console with a real agent. That is what this plan
is for.

Gate-to-PC is not an ordinary feature. It is the one control that makes buddy-punching
impossible, and it is also the one control that can **stop an entire office from working** if it
misbehaves. A bug here does not produce a wrong number on a report — it produces twenty people
standing at their desks unable to log in. That asymmetry is why this plan is longer and slower
than a normal feature test, and why we would rather you find a problem this week than a customer
find it next week.

Please work through the cases in order. They build on each other: the setup left behind by one
case is often the starting point of the next.

---

## 2. What changed

### 2.1 Change one — "Without biometric device" now really means without

**The bug.** An organisation was switched from *With biometric device* to *Without biometric
device*, because it had finished testing with the readers and no longer wanted them. The setting
saved correctly. But every employee's agent still showed **"Punch in at the door to start"** and
refused to begin a work session. Exiting the app, unbinding and re-binding the device, and a
hard refresh of the admin console all made no difference.

**The cause.** The code that decides "is this company gated?" never looked at the attendance
source at all. It looked at two other switches and at whether any biometric device row still
existed in the database. Switching the organisation to *Without biometric device* does not
delete those device rows — so as far as the gate was concerned, nothing had changed, and it
stayed on forever. Worse, switching to *Without biometric device* also hides the Biometric
screen from the menu, and the Gate-to-PC switch lives on that screen — so there was no longer
any way in the console to turn it off. A genuine dead end.

**The fix.** *Without biometric device* is now checked **first** and wins over everything else.
There is no reader, so there can be no gate — regardless of leftover device rows or any other
switch.

### 2.2 Change two — the Gate Exclusion Policy (new feature)

**The gap.** Gate-to-PC was all-or-nothing per company. In practice it needs exceptions:

- the door reader is broken and **nobody** can punch in;
- one person's finger or card will not read;
- **the punch is recorded on the device but never reaches SmartEPT** because the site's network
  or internet link is down — the employee genuinely punched in, the server simply cannot see it;
- night shift, field engineers, work-from-home staff and contractors who never pass a reader
  at all.

Before this change the only escape was a one-off emergency override that expired the same day,
so an administrator was re-approving the same people every single morning.

**The feature.** An exclusion can now be set at five levels — **Machine, Employee, Team,
Department, Branch** — each set to *Inherit* (default), *Excluded* (may sign in without a punch)
or *Required* (must punch in, even if a level above is excluded). The most specific level wins.

Each exclusion can carry **valid-from / valid-until dates**, a **reason**, and records **who
granted it**. Blank dates mean permanent; dated ones stop applying by themselves and the gate
re-arms with nobody having to remember.

A new console screen, **Manage → Gate Exclusions**, lists every exclusion in the company in one
place with an Active / Scheduled / Expired status.

### 2.3 What also got fixed along the way

The change was reviewed twice, specifically hunting for ways the gate could be wrongly lifted.
Twelve defects were found and fixed before it reached you. Several are directly visible from the
console and have test cases below — a deleted branch that kept letting its staff in, a retired
PC that kept locking out its former user, dates expiring at the wrong hour, an edit that quietly
turned a temporary exclusion into a permanent one, and a name containing an apostrophe breaking
the Remove button.

---

## 3. Impact — what we are protecting against

Read this before you start. It tells you what "a failure" actually means here, so you know how
hard to push.

| If this breaks | What the customer experiences | Severity |
|---|---|---|
| Gate stays on when it should be off | A whole office cannot start work. Every minute is lost productivity, and the support call is immediate and angry. | **Critical** |
| Gate goes off when it should be on | Buddy-punching and proxy attendance become possible again, silently. Nobody notices until an audit. | **Critical** |
| An exclusion does not expire | The control is quietly switched off for that group, permanently, and nobody knows. | **High** |
| An exclusion expires too early | The excluded person is locked out again mid-problem — usually at 09:00, usually the worst moment. | **High** |
| Wrong "Granted by" recorded | An administrator is held accountable for a decision someone else made. Fails an audit. | **Medium** |
| Screen shows a status that does not match reality | Support cannot diagnose anything, and trust in the screen is lost. | **Medium** |

The two Critical rows are why this plan asks you to verify the *negative* cases as carefully as
the positive ones. It is not enough that an exclusion lets the right person in — you must also
confirm it does **not** let anyone else in.

---

## 4. Before you start

### 4.1 What you need

| Item | Detail |
|---|---|
| Admin console access | A **Company Admin** login. A few cases also need a second admin login (an **HR Admin** works) and a **Super Admin** login. |
| A test PC with the agent | Installed, bound, and signed in as a test employee. You need to be able to see the agent window. |
| Two test employees | Both in the same branch. One is your main subject; the other proves exclusions do **not** leak to colleagues. Call them **Employee A** and **Employee B** below. |
| An organisation structure | At least one Branch, one Department and one Team, with Employee A sitting in all three. |
| A notepad | Record the exact time of each agent observation. Several cases depend on "within about 15 seconds". |

### 4.2 Simulating a door punch — no hardware needed

Most cases need a punch to exist (or not exist) for an employee. You do not need a physical
reader. Use the CSV import on the Biometric screen.

**Step 1 — map the employee once.** Biometric screen → *Map biometric ID → employee*. Enter a
made-up device ID (for example `TEST-A`) and pick Employee A. Save. Do the same with `TEST-B`
for Employee B. This only has to be done once.

**Step 2 — import a punch.** Create a file `punch.csv` in Notepad with exactly this content,
changing the date and time to now:

```
biometric_employee_id,punch_type,punched_at
TEST-A,IN,2026-08-19 09:05:00
```

Biometric screen → *Import punches (CSV)* → choose the file → **Import**. You should see
"✓ Imported 1 punches." The punch appears in the Punch log, and Employee A's gate opens.

Use `OUT` in the `punch_type` column to punch out. **To test "no punch today", simply do not
import one** — and if you already have, use a date in the past.

> Keep this file handy. You will use it repeatedly.

### 4.3 The two screens you will live in

- **Organisation** — the *Attendance source* card at the top (With / Without biometric device),
  and the Branch / Department / Team records.
- **Biometric** — the *Gate-to-PC* card (the on/off switch and mode), punch import, mapping.
- **Gate Exclusions** — the new screen, under Manage in the sidebar.

### 4.4 How to read the agent

When the gate is closed, the agent shows a full-screen lock panel headed **"Punch in at the door
to start"** with a pulsing *"Waiting for your gate punch…"*. When the gate opens, that panel
disappears and the normal dashboard appears. **The agent re-checks every 15 seconds** — so after
any change in the console, watch the agent for at least 20 seconds before calling it a failure.

### 4.5 Recording results

Use the accompanying spreadsheet, **SmartEPT-Gate-Manual-Test-Results-2026-08-19.xlsx**. One row
per case. Mark **Pass**, **Fail** or **Blocked**, put your name and the date, and in the Notes
column write what you actually saw — not "did not work". For a failure we need:

1. What you did (which case, which step you got to).
2. What you expected.
3. What actually happened — the exact on-screen wording, and a screenshot if you can.
4. The time, to the minute. Several of these behaviours are time-dependent.

---

## 5. Test cases

### Group A — Attendance source (Change one)

---

**A1 · Baseline: a gated company walls the agent**

*Why:* everything else is measured against this. If the gate is not actually on, later "the
employee got in" results mean nothing.

*Setup:* Organisation → Attendance source = **With biometric device**, Save.
Biometric → Gate-to-PC → Gate mode = **Always ON (force)** → Save gate policy.
Make sure Employee A has **no punch today** (check the Punch log).

*Steps:* On the test PC, sign the agent in as Employee A.

*Expected:* The agent shows **"Punch in at the door to start"** and no work session begins. The
timer does not run.

---

**A2 · Switching to "Without biometric device" releases the agent — the original bug**

*Why:* this is the exact failure that was reported. It must now be impossible.

*Setup:* continue from A1, with the agent still showing the wall.

*Steps:* Console → Organisation → Attendance source → select **Without biometric device** →
**Save**. Do not touch anything else. Do not restart the agent. Watch the agent.

*Expected:* Within about 15 seconds the lock panel disappears on its own and the normal agent
dashboard appears. The work session starts. **No** app restart, unbind/re-bind or re-login is
needed.

*This is the single most important case in this plan.* If it fails, stop and report immediately.

---

**A3 · The Biometric and Gate Exclusions menus hide themselves**

*Why:* with no reader there is nothing to configure, and leaving the screens visible previously
led to the dead end described in 2.1.

*Setup:* still on **Without biometric device**.

*Steps:* Hard-refresh the console (Ctrl+F5) and sign in again. Look at the sidebar.

*Expected:* **Biometric** and **Gate Exclusions** are both absent from the Manage group. Every
other menu is unchanged.

---

**A4 · Switching back restores the gate**

*Why:* the fix must not be a one-way door.

*Steps:* Organisation → Attendance source → **With biometric device** → Save. Hard-refresh.
Check the sidebar, then watch the agent.

*Expected:* Biometric and Gate Exclusions reappear. Within about 15 seconds the agent shows the
"Punch in at the door" wall again (Employee A still has no punch today).

---

**A5 · Leftover reader records do not keep the gate on**

*Why:* this was the mechanism of the original bug — device rows that survive the switch.

*Setup:* Biometric → confirm at least one biometric device is configured and Active (leave it
there — do **not** delete it). Gate mode = **Always ON (force)**.

*Steps:* Organisation → **Without biometric device** → Save. Watch the agent.

*Expected:* the agent is released within about 15 seconds, even though the device record and the
"Always ON" gate setting both still exist. *Without biometric device* overrules them.

*Afterwards:* set Attendance source back to **With biometric device** — the rest of the plan
needs the gate available.

---

### Group B — The Gate Exclusions screen

---

**B1 · The menu is in the right place**

*Steps:* look at the sidebar under **Manage**.

*Expected:* **Gate Exclusions** appears directly below **Biometric**. Clicking it opens a screen
titled *Gate Exclusions* with the subtitle *"Who may sign in without a door punch — and until
when"*.

---

**B2 · The status card tells the truth**

*Why:* an exclusion list read without knowing whether the gate is even on is worse than no list.

*Steps:* with Gate mode = **Always ON (force)**, open Gate Exclusions and read the top card.
Then set Gate mode = **OFF (pilot / observe only)**, Save, return to Gate Exclusions and re-read
it.

*Expected:* first it says **Gate-to-PC is ON** (green) and explains employees must punch in;
then it says **Gate-to-PC is OFF** (amber) and warns that the exclusions below have no effect
until you switch it on.

*Afterwards:* set Gate mode back to **Always ON (force)**.

---

**B3 · Empty state**

*Steps:* with no exclusions created yet, look at the table.

*Expected:* a plain-language message saying there are no exclusions and that every employee must
punch in — not a blank table and not an error.

---

**B4 · Create an exclusion for one employee**

*Steps:* **+ Add exclusion** → Level = *Employee* → choose **Employee A** → Setting =
**Excluded** → leave both dates blank → Reason = `Testing B4 — fingerprint not reading` → Save.

*Expected:* a success message; the table now shows one row — Level *Employee*, the person's
name, Setting **Excluded**, From and Until both showing a dash, Status **Active**, your reason,
and your own name under *Granted by*.

---

**B5 · The excluded employee is released**

*Steps:* watch the agent on the test PC (still signed in as Employee A, still no punch today).

*Expected:* within about 15 seconds the lock panel disappears and the work session starts.

---

**B6 · Removing the exclusion re-gates them**

*Steps:* Gate Exclusions → **Remove** on that row → confirm. Watch the agent.

*Expected:* the row disappears from the table. Within about 15 seconds the agent shows the
"Punch in at the door" wall again.

---

### Group C — Levels and precedence

---

**C1 · A branch exclusion covers everyone at that site**

*Why:* this is how a broken reader is handled — one action, whole site.

*Steps:* **+ Add exclusion** → Level = *Branch* → Employee A's branch → **Excluded** → no dates
→ Reason = `Testing C1 — reader down` → Save. Watch the agent.

*Expected:* the agent is released within about 15 seconds. Employee A never had a personal
exclusion — they are covered by the branch.

---

**C2 · A team marked Required stays behind the gate**

*Why:* without this, a branch exclusion would be all-or-nothing and unusable for real sites.

*Setup:* the branch exclusion from C1 is still in place and Employee A is working.

*Steps:* **+ Add exclusion** → Level = *Team* → Employee A's team → Setting = **Required** →
Reason = `Testing C2 — security team must still punch` → Save. Watch the agent.

*Expected:* within about 15 seconds the agent returns to the "Punch in at the door" wall, even
though the branch above is Excluded. The table shows two rows, one **Excluded** (Branch) and one
**Required** (Team).

---

**C3 · A personal exclusion beats the team requirement**

*Why:* proves "most specific wins" all the way down.

*Setup:* both C1 and C2 rows still in place.

*Steps:* **+ Add exclusion** → Level = *Employee* → Employee A → **Excluded** → Reason =
`Testing C3` → Save. Watch the agent.

*Expected:* released within about 15 seconds. Employee is more specific than Team, so it wins.

---

**C4 · Colleagues are not affected**

*Why:* the most dangerous kind of bug here is an exclusion that leaks to people it was never
meant for.

*Setup:* remove the Branch and Team rows from C1/C2. Leave **only** Employee A's personal
exclusion in place.

*Steps:* sign the agent in as **Employee B** on the test PC (or use a second PC). Employee B has
no punch today.

*Expected:* Employee B sees the **"Punch in at the door"** wall. They are not excluded and must
not be released.

*Afterwards:* sign back in as Employee A and remove the remaining exclusion.

---

**C5 · One machine only**

*Why:* covers a shared kiosk or a loan laptop that never sees the reader.

*Steps:* **+ Add exclusion** → Level = *Machine* → pick the test PC → **Excluded** → Reason =
`Testing C5 — loan laptop` → Save. Watch the agent (signed in as Employee A, no punch today).

*Expected:* released within about 15 seconds.

*Afterwards:* remove it.

---

### Group D — Dates

---

**D1 · An exclusion dated in the future does nothing yet**

*Why:* "why isn't my exclusion working?" is nearly always this.

*Steps:* **+ Add exclusion** → Level = *Employee* → Employee A → **Excluded** → *Valid from* =
**tomorrow's date** → *Valid until* = **a week from tomorrow** → Reason = `Testing D1` → Save.
Look at the table, then watch the agent.

*Expected:* the row's Status reads **Scheduled** (not Active). The agent stays on the
"Punch in at the door" wall — the exclusion has not started.

---

**D2 · An exclusion covering today applies**

*Steps:* edit that row → change *Valid from* to **today**. Save. Watch the table and the agent.

*Expected:* Status changes to **Active**, and the agent is released within about 15 seconds.

---

**D3 · An exclusion whose end date has passed does nothing**

*Why:* this is the auto-expiry promise. If it fails, exclusions become permanent by accident.

*Steps:* edit the row → set *Valid from* = **a week ago** and *Valid until* = **yesterday**.
Save. Look at the table and watch the agent.

*Expected:* Status reads **Expired**, and the agent returns to the "Punch in at the door" wall
within about 15 seconds. An expired row is harmless — it stays visible so you can see it lapsed,
but it grants nothing.

---

**D4 · An exclusion ending today still works all of today**

*Why:* the end date is inclusive. Ending it "today" must not cut someone off at breakfast.

*Steps:* edit the row → *Valid from* = **a week ago**, *Valid until* = **today**. Save.

*Expected:* Status **Active**, agent released. The exclusion covers the whole of today.

---

**D5 · Expiry happens at local midnight — overnight check**

*Why:* an earlier version of this feature expired exclusions at midnight UTC, which in India is
**05:30 in the morning** — so a night-shift worker would have been locked out mid-shift. This
case confirms the fix on a real clock.

*Steps:* leave the D4 exclusion exactly as it is (*Valid until* = today) and leave the agent
signed in and running overnight if you can. Check the agent and the Gate Exclusions screen:

- once in the **evening**, after 18:30 — record the time;
- again the **next morning**, before 09:30 — record the time.

*Expected:* in the evening, Status is still **Active** and the agent is still working. The next
morning, Status is **Expired** and the agent is back on the wall. The change must happen
somewhere around **local midnight** — specifically **not** at 05:30, and not at 18:30 the
previous evening.

*If you cannot leave it running overnight,* mark this case **Blocked** and tell us — it needs a
developer to simulate the clock instead.

---

**D6 · An end date before the start date is refused**

*Steps:* try to save an exclusion with *Valid from* = next Friday and *Valid until* = today.

*Expected:* a clear error message and nothing is saved. The form does not silently accept it.

---

**D7 · Blank dates mean permanent**

*Steps:* clear both date fields on the row and Save.

*Expected:* Status **Active**, From and Until both show a dash, and the agent is released. It
stays that way indefinitely until someone removes it.

*Afterwards:* remove the row.

---

### Group E — Reason and accountability

---

**E1 · The reason and the grantor are shown**

*Steps:* create any exclusion with a reason. Read the table row.

*Expected:* the Reason column shows exactly what you typed. The *Granted by* column shows **your
own name** — the administrator who is signed in.

---

**E2 · Whoever changes it becomes accountable for it**

*Why:* previously, extending someone else's exclusion left the original administrator's name on
it — so the audit blamed the wrong person.

*Setup:* the exclusion from E1, created by you, with *Valid until* = tomorrow.

*Steps:* sign out. Sign in as a **different administrator** (the HR Admin login). Go to Gate
Exclusions → Edit that row → change *Valid until* to next week → Save. Read the row.

*Expected:* *Granted by* now shows the **second** administrator's name — the person who actually
extended it. The Setting is still **Excluded** and the reason is unchanged; extending it must
not have removed it.

---

**E3 · Removing an exclusion clears everything with it**

*Steps:* Remove the row, then create a fresh exclusion on the same person with no reason and no
dates.

*Expected:* the new row shows a dash for reason and dates. No leftovers from the removed one.

---

**E4 · The change is in the audit log**

*Steps:* Audit & Ops screen → look for entries around the time you created and removed the
exclusions above.

*Expected:* the changes appear as normal update entries against the employee / branch record,
attributed to the administrator who made them.

---

### Group F — Robustness (the defects found in review)

---

**F1 · A name with an apostrophe does not break the screen**

*Why:* the Remove button used to be built in a way that broke on quotation marks — and worse,
could have been abused. This confirms the fix.

*Steps:* Organisation → rename a test Branch to **O'Brien Site** (or create one with that
name). Give it an exclusion. Go to Gate Exclusions and press **Remove** on that row.

*Expected:* the name displays correctly in the table, the confirmation prompt appears with the
name in it, and Remove works. No error, and nothing strange on screen.

*Also try:* a branch or PC name containing a double quote `"` and one containing `<b>test</b>`.
Both should display as plain text, exactly as typed, and the buttons should still work.

---

**F2 · Deleting a branch re-gates its staff**

*Why:* a deleted branch used to keep granting its exclusion forever, which meant deleting it to
re-gate people silently did nothing.

*Steps:* create a Branch-level exclusion covering Employee A and confirm the agent is released.
Then Organisation → Branches → **Delete** that branch (confirm the prompt). Watch the agent.

*Expected:* within about 15 seconds the agent returns to the "Punch in at the door" wall. The
row also disappears from Gate Exclusions.

*Note:* this deletes a branch. Use a throwaway test branch, not a real one.

---

**F3 · A retired PC stops locking out its former user**

*Why:* an unbound machine marked *Required* used to keep an excluded employee walled, with no
visible cause anywhere in the console.

*Steps:* give Employee A a personal **Excluded** exclusion. Give the test PC a **Machine**-level
**Required** exclusion. Confirm the agent is walled (the machine wins — it is more specific).
Now go to Devices and **unbind** the test PC. Watch the agent / sign in again.

*Expected:* once the PC is unbound, the *Required* on it no longer applies, and Employee A's own
exclusion takes effect — they can work again.

---

**F4 · Super Admin can open the screen**

*Steps:* sign in with the **Super Admin** login. Open Gate Exclusions.

*Expected:* the screen loads without an error message in the table area. (A Super Admin is not
tied to one company, so the list may be empty or span tenants — the point is that it does not
show an error.)

---

**F5 · Ordinary employees cannot see or change any of this**

*Why:* if an employee could exclude themselves the whole control is worthless.

*Steps:* sign in to the console with a plain **Employee** self-service login.

*Expected:* no **Gate Exclusions** menu, no **Biometric** menu, no way to open an employee record
and change a gate setting. They see only their own read-only tabs.

---

**F6 · Monitoring still works for an excluded employee**

*Why:* an exclusion should only remove the *door punch requirement*. It must not accidentally
switch off tracking for that person.

*Steps:* with Employee A excluded and working, leave the agent running for ten minutes with
normal activity. Then check the console: Live Dashboard, Screenshots, Usage & Compliance.

*Expected:* Employee A appears as active, screenshots are arriving (if their policy enables
them), and app/website usage is being recorded — exactly as for a non-excluded employee.

---

### Group G — Nothing else broke

---

**G1 · A normal punch still opens the gate**

*Steps:* remove all exclusions. Confirm Employee A is walled. Import an `IN` punch for `TEST-A`
dated now. Watch the agent.

*Expected:* within about 15 seconds (plus sync time) the agent is released, exactly as it always
did. The Punch log shows the punch and Attendance shows the check-in.

---

**G2 · A punch that arrives late is still recorded**

*Why:* the network-outage scenario. The exclusion lets people work; the punches must still land
correctly when the link comes back.

*Steps:* with Employee A **excluded** and already working, import an `IN` punch for `TEST-A`
timestamped **earlier this morning** (as a queued punch would be). Check Attendance for today.

*Expected:* the punch appears in the Punch log and the attendance record for the day reflects it
normally. Being excluded did not stop the punch being recorded.

---

**G3 · The mid-day out-punch behaviour is unchanged**

*Steps:* with **no** exclusion in place and Employee A punched in and working, import an `OUT`
punch timestamped now. Wait, then import an `IN` punch a few minutes later.

*Expected:* the same behaviour as before this release — an automatic out-of-office break opens
on the OUT and closes on the return IN.

---

**G4 · The emergency override still works**

*Why:* it was kept deliberately, for same-minute emergencies. It must not have been broken by
the new policy.

*Steps:* with no exclusions and Employee A walled, have an administrator issue the emergency
gate override for that employee (with a reason). Watch the agent.

*Expected:* released within about 15 seconds, and the override is recorded against the approving
administrator.

---

## 6. When something fails

Do not try to fix it or work around it. Do this instead:

1. **Stop on that case** and leave the console and agent exactly as they are, if you can.
2. Take a screenshot of the agent window **and** of the Gate Exclusions screen.
3. Note the **exact time**, to the minute, and which employee / branch / PC was involved.
4. Write in the Notes column what you expected and what you saw, in your own words.
5. Move on to the next group — a failure in one group does not usually block the others.

If **A2** fails, stop the whole run and tell us straight away. That is the case the entire fix
exists for.

---

## 7. Sign-off

When you have finished, return the completed spreadsheet with:

- every row marked Pass, Fail or Blocked;
- your name and the date on each row you ran;
- the console version / build and the agent version you tested against;
- an overall recommendation at the bottom: **ready to release**, **release with the listed
  issues**, or **not ready**.

Partial runs are still valuable — send what you have rather than waiting until everything is
done.

---

*Ametecs India — internal. SmartEPT · Employee Productivity Tracking.*
