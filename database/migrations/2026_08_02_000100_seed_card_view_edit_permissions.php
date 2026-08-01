<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Item B — card-level View/Edit access.
 * Registers every console card as a View permission (+ an Edit permission for
 * cards that have actions), grouped by tab, so the role editor can render a
 * per-card View/Edit grid. Every EXISTING role is granted full card access so
 * current behaviour is unchanged until an admin deliberately unticks a card.
 * Enforcement is client-side (hide a card without View; hide its action buttons
 * without Edit); the server keeps its existing tab/permission security.
 */
return new class extends Migration
{
    /** [tabKey, tabLabel, [ [cardKey, cardLabel, editable], ... ] ] */
    private array $tabs = [
        ['dashboard', 'Live Dashboard', [
            ['workforce_status', 'Workforce status', false],
            ['time_utilization', 'Time utilization', false],
            ['live_productivity', 'Live productivity', false],
            ['employees_live', 'Employees — live', false],
            ['device_health', 'Device health', false],
        ]],
        ['attendance', 'Attendance', [
            ['attendance_sheet', 'Attendance sheet', true],
            ['holiday_calendar', 'Holiday calendar', true],
        ]],
        ['screenshots', 'Screenshots', [
            ['screenshot_timeline', 'Screenshot timeline', true],
        ]],
        ['webcam', 'Webcam', [
            ['webcam_photos', 'Webcam presence photos', false],
        ]],
        ['usage', 'Usage & Compliance', [
            ['usage_summary', 'All-employees summary', false],
            ['application_usage', 'Application usage', false],
            ['website_usage', 'Website usage', false],
            ['compliance_events', 'Compliance events', false],
        ]],
        ['violations', 'Violations', [
            ['compliance_violations', 'Compliance violations', false],
        ]],
        ['employees', 'Employees', [
            ['employee_directory', 'Employee directory', true],
            ['employee_archive', 'Employee archive', true],
        ]],
        ['org', 'Organisation', [
            ['attendance_source', 'Attendance source', true],
            ['company_timezone', 'Company time zone', true],
            ['break_limits', 'Break time limits', true],
            ['privacy_rawip', 'Privacy — raw IP & local sites', true],
            ['org_roles', 'Organisation roles', true],
        ]],
        ['users', 'Users', [
            ['login_accounts', 'Login accounts', true],
        ]],
        ['devices', 'Devices', [
            ['registered_devices', 'Registered devices & agent health', true],
        ]],
        ['policies', 'Policies', [
            ['policy_list', 'Policies list', true],
            ['policy_form', 'Create / edit policy', true],
            ['policy_assign', 'Assign a policy', true],
        ]],
        ['rules', 'App & Web Rules', [
            ['app_web_rules', 'Apps & Websites rules', true],
        ]],
        ['meetings', 'Meetings', [
            ['meetings', 'Meetings', true],
        ]],
        ['biometric', 'Biometric', [
            ['bio_setup', 'Device setup', true],
            ['bio_punch_log', 'Punch log', false],
            ['bio_mismatch', 'Mismatch report', false],
            ['bio_import', 'Import punches (CSV)', true],
            ['bio_map', 'Map biometric ID → employee', true],
        ]],
        ['reports', 'Reports & Exports', [
            ['rep_productivity', 'Live productivity (day-wise)', false],
            ['rep_breaks', 'Break report', false],
            ['rep_meetings', 'Meeting report', false],
        ]],
        ['license', 'Licence', [
            ['lic_status', 'Licence status', false],
            ['lic_key', 'Licence key', true],
            ['lic_offline', 'Offline licence file', true],
        ]],
        ['integrations', 'API & Integrations', [
            ['api_keys', 'API keys', true],
            ['outbound_targets', 'Outbound targets', true],
            ['integration_guide', 'Integration guide', false],
        ]],
        ['ops', 'Data & Ops', [
            ['cleanup_schedule', 'Automatic cleanup schedule', true],
            ['local_storage', 'Local / on-premise storage', true],
            ['cloud_storage', 'Cloud storage (GCS)', true],
            ['audit_trail', 'Audit trail', false],
        ]],
        ['help', 'Help', [
            ['system_health', 'System health', false],
            ['known_issues', 'Known issues', false],
            ['app_log', 'Application log', false],
        ]],
    ];

    public function up(): void
    {
        $ids = [];
        foreach ($this->tabs as [$tabKey, $tabLabel, $cards]) {
            $group = 'Card access · ' . $tabLabel;
            foreach ($cards as [$cardKey, $cardLabel, $editable]) {
                $ids[] = Permission::updateOrCreate(
                    ['slug' => "card.$tabKey.$cardKey.view"],
                    ['name' => $cardLabel, 'group' => $group]
                )->id;
                if ($editable) {
                    $ids[] = Permission::updateOrCreate(
                        ['slug' => "card.$tabKey.$cardKey.edit"],
                        ['name' => $cardLabel . ' (edit)', 'group' => $group]
                    )->id;
                }
            }
        }

        // Preserve current behaviour: every existing role keeps full card access
        // until an admin unticks a card. New roles inherit from their base role.
        foreach (Role::all() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $ids = Permission::where('slug', 'like', 'card.%')->pluck('id')->all();
        foreach (Role::all() as $role) {
            $role->permissions()->detach($ids);
        }
        Permission::where('slug', 'like', 'card.%')->delete();
    }
};
