<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 25-Sep-2026 — the role matrix (Organisation → Roles, Card | View | Edit) is THE
 * access rule for the admin console. Every console API route is mapped here to the
 * card(s) that own it: GET needs that card's View, anything else needs its Edit
 * (Edit implies View). For a mapped route the tick decides — it grants AND denies,
 * whatever the old role:/permission: list on the route says.
 *
 * Deliberately left unmapped (old role/permission check still applies):
 *  - shared look-ups several screens use (employees list/detail, org units list,
 *    holidays list, attendance "me", policy/gate traces used by pickers);
 *  - operator-only controls: tenants, danger-zone data clear, updates, storage quota
 *    and global mail changes, cloud-storage credentials, enforcer enrolment;
 *  - actions with their own granular permission: meetings, LiveView, exports,
 *    violation evidence, agent override, break review.
 * SUPER_ADMIN / COMPANY_ADMIN are never restricted; EMPLOYEE portal roles keep their
 * own fixed, employee-scoped access; a role with no card ticks at all keeps its old access.
 */
class CardAccess
{
    /** First match wins: [path pattern (Str::is), cards (any-of, 'tab.*' allowed), level override]. */
    public const RULES = [
        // Dashboard
        ['api/dashboard/violations', ['violations.compliance_violations', 'dashboard.*']],
        ['api/dashboard/device-health', ['dashboard.*', 'devices.registered_devices']],
        ['api/dashboard/*', ['dashboard.*']],
        // Attendance
        ['api/attendance', ['attendance.attendance_sheet']],
        ['api/attendance/*', ['attendance.attendance_sheet']],
        ['api/holidays', ['attendance.holiday_calendar'], 'writes'],
        ['api/holidays/*', ['attendance.holiday_calendar']],
        // Screenshots / Webcam
        ['api/reports/screenshots', ['screenshots.screenshot_timeline']],
        ['api/reports/employee/*/screenshots', ['screenshots.screenshot_timeline']],
        ['api/screenshots/*', ['screenshots.screenshot_timeline']],
        ['api/reports/webcam', ['webcam.webcam_photos', 'webcam.webcam_detected']],
        ['api/webcam/*', ['webcam.webcam_photos']],
        // Usage & Compliance (also opened from the employee drawer)
        ['api/reports/usage-summary', ['usage.usage_summary']],
        ['api/reports/employee/*/app-usage', ['usage.application_usage', 'employees.employee_directory']],
        ['api/reports/employee/*/website-usage', ['usage.website_usage', 'employees.employee_directory']],
        ['api/reports/employee/*/compliance', ['usage.compliance_events', 'employees.employee_directory']],
        ['api/reports/time-utilization', ['dashboard.time_utilization', 'usage.*']],
        // Reports (view-only cards; a rebuild is part of reading the report)
        ['api/reports/productivity*', ['reports.rep_productivity', 'dashboard.live_productivity'], 'view'],
        ['api/reports/breaks', ['reports.rep_breaks']],
        ['api/reports/meetings', ['reports.rep_meetings']],
        ['api/reports/monthly-summary', ['reports.rep_monthly']],
        // Employees (list/detail stay shared look-ups)
        ['api/employees/archives*', ['employees.employee_archive']],
        ['api/employees/export', ['employees.employee_directory']],
        ['api/employees/*/relieve', ['employees.employee_directory']],
        ['api/employees/bulk-import', ['employees.employee_directory']],
        ['api/employees', ['employees.employee_directory'], 'writes'],
        ['api/employees/*', ['employees.employee_directory'], 'writes'],
        // Organisation
        ['api/companies/*', ['biometric.gate_to_pc', 'org.attendance_source', 'org.company_timezone', 'org.break_limits', 'org.privacy_rawip']],
        ['api/org/*', ['org.org_units'], 'grant'],               // list = shared look-up
        ['api/org/*', ['org.org_units', 'gateexcl.exclusions']], // gate exclusions are saved on org units
        ['api/roles', ['org.org_roles']],
        ['api/roles/*', ['org.org_roles']],
        // Users / Devices
        ['api/users', ['users.login_accounts', 'employees.employee_directory'], 'grant'], // also the reporting-manager picker
        ['api/users', ['users.login_accounts']],
        ['api/users/*', ['users.login_accounts']],
        ['api/devices', ['devices.registered_devices', 'gateexcl.*'], 'grant'], // also policy/gate-exclusion pickers
        ['api/devices/*/gate-mode', ['devices.registered_devices', 'gateexcl.exclusions']],
        ['api/ops/agent-lock', ['devices.agent_lock']],
        ['api/gate-exclusions', ['gateexcl.*']],
        ['api/devices/*', ['devices.registered_devices']],
        // App & Web Rules (incl. its Enforcement + Device control panels)
        ['api/policies/protection-capabilities', ['rules.app_web_rules']],
        ['api/policies/*/*/rules', ['rules.app_web_rules']],
        ['api/policies/application*', ['rules.app_web_rules', 'policies.policy_list', 'policies.policy_form']],
        ['api/policies/website*', ['rules.app_web_rules', 'policies.policy_list', 'policies.policy_form']],
        ['api/enforcement/audit-report', ['rules.enforcement', 'rules.device_control', 'rules.app_web_rules'], 'view'],
        ['api/enforcement/device-control', ['rules.device_control']],
        ['api/enforcement/*', ['rules.enforcement']],
        // Policies
        ['api/policies/assign', ['policies.policy_assign', 'rules.app_web_rules']],
        ['api/policies/*', ['policies.policy_list', 'policies.policy_form']],
        // Biometric
        ['api/gate/policy', ['biometric.gate_to_pc']],
        ['api/integrations/biometric/devices*', ['biometric.bio_setup']],
        ['api/integrations/biometric/live-sync', ['biometric.bio_setup']],
        ['api/integrations/biometric/logs', ['biometric.bio_punch_log'], 'view'],
        ['api/integrations/biometric/import', ['biometric.bio_import']],
        ['api/integrations/biometric/map-employee', ['biometric.bio_map']],
        ['api/integrations/biometric/mappings*', ['biometric.bio_map']],
        ['api/integrations/biometric/unmapped', ['biometric.bio_map']],
        ['api/reports/biometric-mismatch', ['biometric.bio_mismatch']],
        // Licence
        ['api/license', ['license.lic_status'], 'view'],
        ['api/license', ['license.lic_key']],
        ['api/license/import', ['license.lic_offline']],
        ['api/license/validate', ['license.*'], 'view'],
        // API & Integrations
        ['api/integrations/keys*', ['integrations.api_keys']],
        ['api/integrations/targets*', ['integrations.outbound_targets']],
        // Audit & Ops / Help
        ['api/ops/retention', ['ops.cleanup_schedule']],
        ['api/ops/purge-run', ['ops.cleanup_schedule']],
        ['api/ops/storage-cleanup', ['ops.cleanup_schedule']],
        ['api/ops/storage-local*', ['ops.local_storage']],
        ['api/ops/backup*', ['ops.local_storage']],
        ['api/ops/storage-quota', ['ops.storage_quota'], 'view-only'],
        ['api/ops/mail-config', ['ops.mail_smtp'], 'view-only'],     // PUT = global relay, Super Admin only
        ['api/ops/mail-config/*', ['ops.mail_smtp']],
        ['api/ops/mail-log', ['ops.mail_smtp', 'ops.notifications']],
        ['api/ops/notify-prefs', ['ops.notifications']],
        ['api/ops/storage-usage', ['ops.local_storage', 'ops.cloud_storage']],
        ['api/ops/storage-config', ['ops.local_storage', 'ops.cloud_storage'], 'view-only'],
        ['api/audit-logs', ['ops.audit_trail']],
        ['api/ops/diagnostics', ['help.system_health']],
        ['api/ops/logs', ['help.app_log']],
    ];

    /** true = allow, false = deny, null = not governed by the matrix (use the old check). */
    public static function decide(Request $request): ?bool
    {
        $user = $request->user();
        if (! $user || $user->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN')) {
            return null;
        }
        if (($user->role?->base_slug ?: $user->roleSlug()) === 'EMPLOYEE') {
            return null;
        }

        $rule = self::match($request->method(), $request->path());
        if (! $rule) {
            return null;
        }

        $held = $user->permissionSlugs();
        // A role with no card ticks at all has never been set up in the matrix
        // (fresh install / re-seeded system role): it keeps its old access, the
        // same convention the console's applyCardAccess() has used since 1-Aug.
        if (! preg_grep('/^card\./', $held)) {
            return null;
        }

        $ok = self::holds($held, $rule['cards'], $rule['level']);

        // Shared look-ups: a tick opens them, no tick falls back to the old check
        // (other screens' pickers depend on them).
        return $ok ? true : ($rule['grant_only'] ? null : false);
    }

    /** @return array{cards: string[], level: string, grant_only: bool}|null */
    public static function match(string $method, string $path): ?array
    {
        $read = in_array(strtoupper($method), ['GET', 'HEAD'], true);
        foreach (self::RULES as $r) {
            [$pattern, $cards] = $r;
            $mode = $r[2] ?? null;
            if (! Str::is($pattern, $path)) {
                continue;
            }
            if ($mode === 'grant' && ! $read) {
                continue;
            }
            if ($mode === 'writes' && $read) {
                continue;                       // GET of a shared look-up: not governed
            }
            if ($mode === 'view-only' && ! $read) {
                continue;                       // writes stay operator-only
            }
            if ($pattern === 'api/license' && $mode === 'view' && ! $read) {
                continue;                       // POST api/license = licence key (next rule)
            }
            $level = ($mode === 'view' || $mode === 'view-only' || $read) ? 'view' : 'edit';

            return ['cards' => $cards, 'level' => $level, 'grant_only' => $mode === 'grant'];
        }

        return null;
    }

    /** Edit implies View. 'tab.*' matches any card on that tab. */
    public static function holds(array $held, array $cards, string $level): bool
    {
        $levels = $level === 'view' ? ['view', 'edit'] : ['edit'];
        foreach ($cards as $card) {
            foreach ($levels as $lv) {
                if (str_ends_with($card, '.*')) {
                    $prefix = 'card.' . substr($card, 0, -1);
                    foreach ($held as $h) {
                        if (str_starts_with($h, $prefix) && str_ends_with($h, '.' . $lv)) {
                            return true;
                        }
                    }
                } elseif (in_array("card.$card.$lv", $held, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Does this user hold Edit on the card? (controller-level field checks) */
    public static function canEdit(User $user, string $card): bool
    {
        return $user->hasRole('SUPER_ADMIN', 'COMPANY_ADMIN') || self::holds($user->permissionSlugs(), [$card], 'edit');
    }
}
