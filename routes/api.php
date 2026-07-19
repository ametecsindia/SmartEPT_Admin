<?php

use App\Http\Controllers\Api\ProductivityController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AgentStatusController;
use App\Http\Controllers\Api\AttendanceAdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\MonthlyReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\BiometricDeviceController;
use App\Http\Controllers\Api\BreakController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\OpsController;
use App\Http\Controllers\Api\StorageConfigController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\OrgController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ScreenshotController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SmartEPT API routes — Milestone M1 (Server Foundation)
|--------------------------------------------------------------------------
| Auth & RBAC · Company/Org · Employee & Device · Policy Engine
*/

// ---- Public ----
// Reachability probe for the agent's "Test connection" button (no data exposed).
Route::get('ping', fn () => response()->json([
    'app'         => 'SmartEPT',
    'version'     => config('smartept.version', '1.0'),
    'server_time' => now()->toIso8601String(),
]));
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ---- Authenticated (any valid token) ----
Route::middleware('auth:sanctum')->group(function () {

    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    // Self-service: any role may change their own password (clears must_change_password).
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // ---- User account lifecycle (Release-1 item 1) ----
    // Admin management of login accounts: create with one-time temp password,
    // update role/status, reset password, soft-disable. Tenant-scoped in controller.
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN,HR_ADMIN')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
    });

    // ---- Attendance completeness (Release-1 items 2+3) ----
    // Holiday calendar + manual regularization: HR-level roles only, tenant-scoped.
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN,HR_ADMIN')->group(function () {
        Route::get('holidays', [HolidayController::class, 'index']);
        Route::post('holidays', [HolidayController::class, 'store']);
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy']);

        Route::get('attendance', [AttendanceAdminController::class, 'index']);
        Route::post('attendance', [AttendanceAdminController::class, 'store']);
        Route::put('attendance/{attendance}', [AttendanceAdminController::class, 'update']);
    });

    // ---- Employee agent ----
    // ---- Ops (R2-4): audit viewer + storage usage + backups ----
    Route::get('audit-logs', [OpsController::class, 'auditLogs'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,AUDITOR');
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN')->group(function () {
        Route::get('ops/storage-usage', [OpsController::class, 'storageUsage']);
        Route::get('ops/storage-config', [StorageConfigController::class, 'show']);
        Route::put('ops/storage-config', [StorageConfigController::class, 'save']);
        Route::post('ops/storage-config/test', [StorageConfigController::class, 'test']);
        Route::get('ops/backups', [OpsController::class, 'backups']);
        Route::post('ops/backup', [OpsController::class, 'runBackup']);
        Route::post('ops/storage-cleanup', [OpsController::class, 'storageCleanup']); // 17-Jul bulk evidence/log cleanup
        Route::get('ops/retention', [OpsController::class, 'retention']);              // 17-Jul auto-cleanup params
        Route::put('ops/retention', [OpsController::class, 'updateRetention']);
        Route::post('ops/purge-run', [OpsController::class, 'runPurge']);
        Route::get('gate/policy', [OpsController::class, 'gatePolicy']);       // Gate-to-PC USP
        Route::put('gate/policy', [OpsController::class, 'updateGatePolicy']);
    });

    // ---- Licence (R2-1): admin view/set key + force revalidation ----
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN')->group(function () {
        Route::get('license', [LicenseController::class, 'show']);
        Route::post('license', [LicenseController::class, 'store']);
        Route::post('license/validate', [LicenseController::class, 'revalidate']);
    });

    Route::prefix('agent')->middleware(['licensed', 'active-employee', 'throttle:600,1'])->group(function () {
        // Bootstrap (no consent gate — these establish identity + consent).
        Route::post('register-device', [DeviceController::class, 'register']);
        Route::get('policy', [PolicyController::class, 'agentBundle']);
        Route::post('heartbeat', [DeviceController::class, 'heartbeat']);
        Route::post('consent', [ConsentController::class, 'store']);
        Route::get('consent/status', [ConsentController::class, 'status']);
        Route::get('today', [AgentStatusController::class, 'today']);
        Route::post('sync-batch', [SyncController::class, 'batch']);
        // Biometric Gate v1.1: PRE-consent by design — the wall shows before work
        // starts, and it exposes only the caller's own punch state.
        Route::get('gate-status', [AgentStatusController::class, 'gateStatus']);

        // Tracking ingestion (M2 + M3) — gated by recorded consent where policy requires it.
        Route::middleware('consent')->group(function () {
            Route::post('attendance-event', [AttendanceController::class, 'store']);
            Route::post('activity-events', [ActivityController::class, 'activity']);
            Route::post('idle-event', [ActivityController::class, 'idle']);
            Route::post('break-event', [BreakController::class, 'store']);

            // M3 — presence (metadata only) + screenshots/webcam (policy-gated media).
            Route::post('presence-event', [PresenceController::class, 'event']);
            Route::post('webcam-event', [PresenceController::class, 'webcam']);
            Route::post('screenshot-upload', [ScreenshotController::class, 'upload']);

            // M4 — app/website usage + compliance events.
            Route::post('app-usage', [UsageController::class, 'appUsage']);
            Route::post('website-usage', [UsageController::class, 'websiteUsage']);
            Route::post('compliance-event', [ComplianceController::class, 'store']);
        });
    });

    // ---- Monitoring reports (M3): screenshots + presence timelines ----
    Route::get('reports/screenshots', [ScreenshotController::class, 'companyDay'])
        ->middleware('permission:screenshot.view');
    Route::get('reports/employee/{employee}/screenshots', [ScreenshotController::class, 'timeline'])
        ->middleware('permission:screenshot.view');
    Route::get('screenshots/{screenshot}/file', [ScreenshotController::class, 'file'])
        ->middleware('permission:screenshot.view')
        ->name('screenshots.file');
    Route::get('reports/employee/{employee}/presence', [PresenceController::class, 'timeline'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,MANAGER,TEAM_LEADER,COMPLIANCE_OFFICER,AUDITOR');

    // ---- Usage + compliance reports (M4) ----
    Route::middleware('permission:activity.view')->group(function () {
        Route::get('reports/usage-summary', [UsageController::class, 'companySummary']); // 17-Jul all-employees default
        Route::get('reports/time-utilization', [UsageController::class, 'timeUtilization']); // dashboard 'where the hours went'
        Route::get('reports/productivity', [ProductivityController::class, 'report']); // 17-Jul all-employee day-wise productivity
        Route::get('reports/employee/{employee}/app-usage', [UsageController::class, 'appReport']);
        Route::get('reports/employee/{employee}/website-usage', [UsageController::class, 'websiteReport']);
        Route::get('reports/employee/{employee}/compliance', [ComplianceController::class, 'report']);
    });
    Route::get('dashboard/violations', [ComplianceController::class, 'feed'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,MANAGER,TEAM_LEADER,COMPLIANCE_OFFICER,AUDITOR');

    // ---- Live dashboard + timeline (M5) ----
    $mgr = 'role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,MANAGER,TEAM_LEADER,COMPLIANCE_OFFICER,AUDITOR,HR_ADMIN';
    Route::get('dashboard/live-status', [DashboardController::class, 'liveStatus'])->middleware($mgr);
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->middleware($mgr);
    Route::get('dashboard/device-health', [DashboardController::class, 'deviceHealth'])->middleware($mgr);
    Route::get('reports/employee/{employee}/timeline', [ReportController::class, 'timeline'])->middleware($mgr);
    // Monthly payroll pack: per-employee counts + payable days for a month.
    Route::get('reports/monthly-summary', [MonthlyReportController::class, 'summary'])->middleware($mgr);

    // ---- Exports (M5 + M6) — CSV ----
    Route::middleware('permission:export.data')->group(function () {
        Route::get('export/attendance', [ExportController::class, 'attendance']);
        Route::get('export/productivity', [ExportController::class, 'productivity']);
        Route::get('export/compliance', [ExportController::class, 'compliance']);
        Route::get('export/daily-summary', [ExportController::class, 'dailySummary']);
        Route::get('export/attendance-register', [MonthlyReportController::class, 'attendanceRegister']);
    });

    // ---- Biometric integration (M6) ----
    Route::prefix('integrations/biometric')->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,HR_ADMIN')->group(function () {
        Route::post('logs', [BiometricController::class, 'pushLogs']);
        Route::get('logs', [BiometricController::class, 'getLogs']);
        Route::post('import', [BiometricController::class, 'import']);
        Route::post('map-employee', [BiometricController::class, 'mapEmployee']);

        // Punch-device registry: physical readers AND cloud attendance APIs.
        Route::get('devices', [BiometricDeviceController::class, 'index']);
        Route::post('devices/test-connection', [BiometricDeviceController::class, 'testConnection']);
        Route::post('devices/{device}/sync', [BiometricDeviceController::class, 'syncNow']);
        Route::post('devices', [BiometricDeviceController::class, 'store']);
        Route::put('devices/{device}', [BiometricDeviceController::class, 'update']);
        Route::delete('devices/{device}', [BiometricDeviceController::class, 'destroy']);
    });
    Route::get('reports/biometric-mismatch', [BiometricController::class, 'mismatch'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,HR_ADMIN,MANAGER,AUDITOR');

    // ---- Companies (tenant provisioning + profile) ----
    Route::get('companies', [CompanyController::class, 'index'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,AUDITOR');
    Route::get('companies/{company}', [CompanyController::class, 'show'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,AUDITOR');
    Route::post('companies', [CompanyController::class, 'store'])
        ->middleware('role:SUPER_ADMIN');
    Route::put('companies/{company}', [CompanyController::class, 'update'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN');

    // ---- Organisation sub-entities: branches / departments / teams / designations / shifts ----
    Route::get('org/{type}', [OrgController::class, 'index'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,HR_ADMIN,MANAGER,AUDITOR');
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,HR_ADMIN')->group(function () {
        Route::post('org/{type}', [OrgController::class, 'store']);
        Route::put('org/{type}/{id}', [OrgController::class, 'update']);
        Route::delete('org/{type}/{id}', [OrgController::class, 'destroy']);
    });

    // ---- R4 item 5: organisation roles + module-permission matrix ----
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    });

    // ---- Integrations (17-Jul): API keys + outbound targets (company-admin) ----
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN')->group(function () {
        Route::get('integrations/keys', [IntegrationController::class, 'keys']);
        Route::post('integrations/keys', [IntegrationController::class, 'createKey']);
        Route::post('integrations/keys/{apiKey}/revoke', [IntegrationController::class, 'revokeKey']);
        Route::get('integrations/targets', [IntegrationController::class, 'targets']);
        Route::post('integrations/targets', [IntegrationController::class, 'saveTarget']);
        Route::put('integrations/targets/{target}', [IntegrationController::class, 'saveTarget']);
        Route::delete('integrations/targets/{target}', [IntegrationController::class, 'deleteTarget']);
        Route::post('integrations/targets/{target}/push', [IntegrationController::class, 'pushTarget']);
    });

    // ---- Employees ----
    Route::get('employees', [EmployeeController::class, 'index'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,HR_ADMIN,MANAGER,TEAM_LEADER,AUDITOR');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,HR_ADMIN,MANAGER,TEAM_LEADER,AUDITOR');
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,HR_ADMIN')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::post('employees/bulk-import', [EmployeeController::class, 'bulkImport']); // 17-Jul SmartPRS-style bulk onboarding
        Route::put('employees/{employee}', [EmployeeController::class, 'update']);
        Route::post('employees/{employee}/relieve', [EmployeeController::class, 'relieve']); // R2-3 offboarding
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
    });

    // ---- Devices (admin view) ----
    Route::get('devices', [DeviceController::class, 'index'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,BRANCH_ADMIN,MANAGER,TEAM_LEADER,AUDITOR');

    // R2-3 device management: unbind kills the agent token + frees the seat;
    // rebind (admin approval) re-claims a seat and lets the agent register again.
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN')->group(function () {
        Route::post('devices/{device}/unbind', [DeviceController::class, 'unbind']);
        Route::post('devices/{device}/rebind', [DeviceController::class, 'rebind']);
    });

    // ---- Policy Engine ----
    Route::get('policies/{type}', [PolicyController::class, 'index'])
        ->middleware('role:SUPER_ADMIN,COMPANY_ADMIN,COMPLIANCE_OFFICER,AUDITOR');
    Route::middleware('role:SUPER_ADMIN,COMPANY_ADMIN,COMPLIANCE_OFFICER')->group(function () {
        Route::post('policies/assign', [PolicyController::class, 'assign']);
        Route::post('policies/{type}', [PolicyController::class, 'store']);
        Route::put('policies/{type}/{id}', [PolicyController::class, 'update']);
        Route::delete('policies/{type}/{id}', [PolicyController::class, 'destroy']);
    });
});

// ==== SmartEPT PUBLIC API v1 — API-key authenticated (integration hub, 17-Jul) ====
Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('ping', [PublicApiController::class, 'ping'])->middleware('api-key');
    Route::post('attendance/punches', [PublicApiController::class, 'ingestPunches'])->middleware('api-key:ingest');
    Route::get('attendance', [PublicApiController::class, 'readAttendance'])->middleware('api-key:read');
});
