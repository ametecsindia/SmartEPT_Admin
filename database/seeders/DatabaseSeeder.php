<?php

namespace Database\Seeders;

use App\Models\ApplicationPolicy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\MonitoringPolicy;
use App\Models\PolicyAssignment;
use App\Models\Role;
use App\Models\ScreenshotPolicy;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Models\WebcamPolicy;
use App\Models\WebsitePolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Roles + permissions.
        $this->call(RolePermissionSeeder::class);
        $roles = Role::whereNull('company_id')->pluck('id', 'slug');

        // 2) Sample tenant: Ametecs Pvt Ltd.
        $company = Company::updateOrCreate(
            ['code' => 'AMETECS'],
            [
                'name' => 'Ametecs Pvt Ltd',
                'legal_name' => 'Ametecs Private Limited',
                'timezone' => 'Asia/Kolkata',
                'deployment_model' => 'LAN',
                'storage_driver' => 'LOCAL',
                'data_retention_days' => 90,
                'status' => 'ACTIVE',
            ]
        );

        $branch = Branch::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'HO'],
            ['name' => 'Head Office', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'country' => 'India']
        );

        $dept = Department::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'OPS'],
            ['name' => 'Operations', 'branch_id' => $branch->id]
        );

        $team = Team::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'OPS-A'],
            ['name' => 'Operations Team A', 'department_id' => $dept->id]
        );

        $desig = Designation::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'EXEC'],
            ['name' => 'Executive', 'level' => 1]
        );

        $shift = Shift::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'GEN'],
            [
                'name' => 'General Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
                'grace_minutes' => 10, 'working_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
                'break_minutes_allowed' => 60,
            ]
        );

        // 2b) Sample 2026 holiday calendar. Idempotent via whereDate (not firstOrCreate):
        // the date cast stores a midnight timestamp, which a plain equality miss-matches.
        foreach ([
            ['2026-08-15', 'Independence Day'],
            ['2026-10-02', 'Gandhi Jayanti'],
            ['2026-11-09', 'Diwali'],
        ] as [$date, $name]) {
            Holiday::where('company_id', $company->id)->whereDate('holiday_date', $date)->exists()
                || Holiday::create(['company_id' => $company->id, 'holiday_date' => $date, 'name' => $name, 'type' => 'PUBLIC']);
        }

        // 3) One user per admin role.
        // NOTE (11-Aug-2026): User casts password => 'hashed', so pass the PLAIN
        // value — Hash::make() here double-hashed it and NO seeded login worked.
        $mk = fn (string $name, string $email, string $roleSlug, ?int $companyId = null) => User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name, 'password' => 'password',
                'company_id' => $companyId, 'branch_id' => $companyId ? $branch->id : null,
                'role_id' => $roles[$roleSlug], 'status' => 'ACTIVE',
            ]
        );

        $mk('Super Admin', 'super@smartept.io', 'SUPER_ADMIN', null);
        $mk('Company Admin', 'admin@ametecs.io', 'COMPANY_ADMIN', $company->id);
        $manager = $mk('Team Manager', 'manager@ametecs.io', 'MANAGER', $company->id);
        $mk('HR Admin', 'hr@ametecs.io', 'HR_ADMIN', $company->id);
        $mk('Compliance Officer', 'compliance@ametecs.io', 'COMPLIANCE_OFFICER', $company->id);
        $mk('Auditor', 'auditor@ametecs.io', 'AUDITOR', $company->id);

        $team->update(['manager_user_id' => $manager->id]);

        // 4) Employees, each with a linked EMPLOYEE login for the agent.
        $people = [
            ['E-1001', 'Priya', 'Raman', 'priya.raman@ametecs.io'],
            ['E-1002', 'Dev', 'Patel', 'dev.patel@ametecs.io'],
            ['E-1003', 'Arjun', 'Mehta', 'arjun.mehta@ametecs.io'],
        ];

        foreach ($people as [$code, $first, $last, $email]) {
            $eUser = $mk("$first $last", $email, 'EMPLOYEE', $company->id);
            Employee::updateOrCreate(
                ['company_id' => $company->id, 'employee_code' => $code],
                [
                    'first_name' => $first, 'last_name' => $last, 'email' => $email,
                    'branch_id' => $branch->id, 'department_id' => $dept->id, 'team_id' => $team->id,
                    'designation_id' => $desig->id, 'shift_id' => $shift->id,
                    'manager_user_id' => $manager->id, 'user_id' => $eUser->id,
                    'employment_status' => 'ACTIVE', 'date_of_joining' => now()->subYear()->toDateString(),
                ]
            );
        }

        // 5) A default policy set for the company.
        $monitoring = MonitoringPolicy::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Standard Office Monitoring'],
            [
                'description' => 'Working-hours tracking with consent, app/website usage on.',
                'tracking_enabled' => true, 'working_hours_only' => true,
                'consent_required' => true, 'employee_status_visible' => true,
                'app_usage_enabled' => true, 'website_usage_enabled' => true,
                'data_retention_days' => 90, 'is_active' => true, 'version' => 1,
            ]
        );

        $screenshotPolicy = ScreenshotPolicy::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Standard Screenshots'],
            ['enabled' => true, 'interval_seconds' => 600, 'on_violation' => true, 'active_work_only' => true, 'retention_days' => 30, 'version' => 1]
        );

        $webcamPolicy = WebcamPolicy::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Presence Only'],
            ['presence_enabled' => true, 'photo_enabled' => false, 'face_confidence_threshold' => 0.70, 'version' => 1]
        );

        $appPolicy = ApplicationPolicy::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Standard App Policy'],
            [
                'allowed_apps' => ['chrome.exe', 'msedge.exe', 'excel.exe', 'winword.exe', 'teams.exe', 'code.exe', 'smartdcm', 'smartprs'],
                'blocked_apps' => ['steam', 'epicgames', 'utorrent', 'bittorrent', 'valorant', 'discord', 'anydesk', 'teamviewer'],
                'categories'   => ['excel.exe' => 'PRODUCTIVE', 'winword.exe' => 'PRODUCTIVE', 'teams.exe' => 'COMMUNICATION', 'smartdcm' => 'CLIENT_REQUIRED'],
                'action_on_blocked' => 'WARN',
                'version' => 1,
            ]
        );

        $sitePolicy = WebsitePolicy::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Standard Website Policy'],
            [
                'allowed_sites' => ['office.com', 'google.com', 'smartdcm', 'smartprs', 'company-crm'],
                'blocked_sites' => ['facebook', 'instagram', 'youtube', 'netflix', 'tiktok', 'twitter', 'x.com', 'reddit', 'gambling'],
                'categories'    => ['office.com' => 'PRODUCTIVE', 'youtube' => 'NON_PRODUCTIVE', 'teams' => 'COMMUNICATION'],
                'track_full_url' => false,
                'action_on_blocked' => 'WARN',
                'version' => 1,
            ]
        );

        // 6) Assign policies at company level.
        $assign = function (string $type, int $policyId) use ($company) {
            PolicyAssignment::firstOrCreate(
                ['company_id' => $company->id, 'policy_type' => $type, 'assignable_type' => 'COMPANY', 'assignable_id' => $company->id],
                ['policy_id' => $policyId]
            );
        };
        $assign('MONITORING', $monitoring->id);
        $assign('APPLICATION', $appPolicy->id);
        $assign('WEBSITE', $sitePolicy->id);
        $assign('SCREENSHOT', $screenshotPolicy->id);
        $assign('WEBCAM', $webcamPolicy->id);

        $this->command?->info('Seeded Ametecs sample tenant. Logins use password: password');
    }
}
