<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeArchive;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\EmployeeArchiver;
use App\Services\MailService;
use App\Services\PolicyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\ScopesVisibleEmployees;

class EmployeeController extends Controller
{
    use ScopesVisibleEmployees;

    /** GET /api/employees — tenant-scoped, filterable by team/department/branch/status. */
    public function index(Request $request): JsonResponse
    {
        $visible = $this->visibleEmployeeIds($request->user());

        $employees = Employee::query()
            ->with(['team:id,name', 'department:id,name', 'branch:id,name', 'designation:id,name', 'shift:id,name'])
            ->when($request->team_id, fn ($q, $v) => $q->where('team_id', $v))
            ->when($request->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->branch_id, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('employment_status', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($w) =>
                $w->where('first_name', 'like', "%{$v}%")
                  ->orWhere('last_name', 'like', "%{$v}%")
                  ->orWhere('employee_code', 'like', "%{$v}%")))
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($employees);
    }

    /** GET /api/employees/{employee} */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);

        return response()->json(['data' => $employee->load([
            'team', 'department', 'branch', 'designation', 'shift', 'devices', 'manager:id,name',
        ])]);
    }

    /** POST /api/employees */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $employee = Employee::create($data); // company_id auto-filled
        $this->audit($request, 'CREATE', Employee::class, $employee->id, $data);

        $response = ['data' => $employee];

        // Release-1: every new employee gets a self-service EMPLOYEE login by
        // default (opt out with create_login=false, or by linking user_id
        // explicitly). Skipped when there is no email to sign in with, or the
        // email already belongs to a login — we never fail the employee create
        // over the optional account.
        if ($request->boolean('create_login', true)
            && empty($data['user_id'])
            && ! empty($employee->email)
            && ! User::where('email', $employee->email)->exists()) {
            // Temp password is returned exactly once — only the hash is stored.
            $temp = Str::password(10);

            $login = User::create([
                'name'                 => $employee->fullName(),
                'email'                => $employee->email,
                'password'             => $temp, // hashed by the model cast
                'company_id'           => $employee->company_id,
                'branch_id'            => $employee->branch_id,
                'role_id'              => Role::where('slug', 'EMPLOYEE')->whereNull('company_id')->value('id'),
                'status'               => 'ACTIVE',
                'must_change_password' => true,
            ]);

            $employee->update(['user_id' => $login->id]);
            MailService::sendCredentials($login, $temp); // best-effort, never blocks
            $this->audit($request, 'CREATE', User::class, $login->id, [
                'email' => $login->email, 'role' => 'EMPLOYEE', 'employee_id' => $employee->id,
            ]);

            $response['login'] = ['user_id' => $login->id, 'email' => $login->email];
            $response['temp_password'] = $temp;
        }

        return response()->json($response, 201);
    }

    /** PUT /api/employees/{employee} */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);

        $data = $this->validated($request, false, $employee);
        if (array_key_exists('reporting_manager_user_id', $data)
            && ! app(\App\Services\HierarchyService::class)->validateReportingManager($employee->id, $data['reporting_manager_user_id'])) {
            abort(422, 'That reporting manager would create a reporting loop.');
        }
        $employee->update($data);
        $this->audit($request, 'UPDATE', Employee::class, $employee->id, $data);

        return response()->json(['data' => $employee]);
    }

    /**
     * POST /api/employees/{employee}/relieve — R2-3 offboarding in one action:
     * status → RELIEVED, login disabled, ALL tokens revoked (console + agent),
     * every device unbound and its licence seat released on Central.
     */
    public function relieve(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $employee->update(['employment_status' => 'RELIEVED']);

        $client = app(\App\Services\LicenseClient::class);

        foreach ($employee->devices as $device) {
            $client->deactivateDevice($device->device_uuid);
            $device->update([
                'unbound_at' => now(),
                'current_status' => 'OFFLINE',
                'agent_health' => 'STOPPED',
            ]);
        }

        if ($employee->user) {
            $employee->user->tokens()->delete();
            $employee->user->update(['status' => 'DISABLED']);
        }

        $this->audit($request, 'RELIEVE_EMPLOYEE', Employee::class, $employee->id, [
            'reason' => $data['reason'],
            'devices_unbound' => $employee->devices->count(),
        ]);

        return response()->json(['data' => $employee->fresh()]);
    }

    /**
     * DELETE /api/employees/{employee}
     *
     * Archive-on-delete (Ejaz 24-Jul):
     *  1. Snapshot the employee + a full count of their data into an EmployeeArchive row
     *     labelled Code_Name_Date (the heavy ZIP with every record + the actual screenshot/
     *     webcam files is built moments later by smartept:build-archives).
     *  2. Free the employee_code so a new joiner can reuse it (the soft-deleted row would
     *     otherwise keep the code locked).
     *  3. Soft-delete the employee — the underlying data stays in place, safely backed up.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeVisible($request, $employee->id);

        $archiver = app(EmployeeArchiver::class);

        // Capture everything BEFORE we mutate the code.
        $freedCode = $employee->employee_code;
        $name      = $employee->fullName();
        $label     = $archiver->label($employee);
        $counts    = $archiver->counts($employee->id);
        $snapshot  = $archiver->snapshot($employee);

        // Part C #18/#19: archive + soft-delete the employee AND deactivate its linked login
        // in ONE transaction, so a deleted employee never leaves an active user behind.
        $archive = DB::transaction(function () use ($employee, $label, $freedCode, $name, $counts, $snapshot, $request) {
            $archive = EmployeeArchive::create([
                'company_id'             => $employee->company_id,
                'employee_id'            => $employee->id,
                'archive_label'          => $label,
                'original_employee_code' => $freedCode,
                'employee_name'          => $name !== '' ? $name : $freedCode,
                'archived_by_user_id'    => $request->user()->id,
                'archived_at'            => now(),
                'snapshot'               => $snapshot,
                'counts'                 => $counts,
                'file_status'            => 'PENDING',
            ]);

            // Free the code, then soft-delete (history retained).
            $employee->forceFill([
                'employee_code' => $freedCode . '~del' . $employee->id . '~' . now()->format('ymdHis'),
            ])->save();
            $employee->delete();

            // Deactivate the linked login + revoke every token (console + agent) so the person
            // cannot sign in and drops out of the active Users tab immediately.
            if ($employee->user) {
                $employee->user->tokens()->delete();
                $employee->user->update(['status' => 'DISABLED']);
            }

            return $archive;
        });

        $this->audit($request, 'DELETE', Employee::class, $employee->id, [
            'employee_code' => $freedCode,
            'archive_id'    => $archive->id,
            'archive_label' => $label,
        ]);

        return response()->json(['data' => [
            'archive_id'    => $archive->id,
            'archive_label' => $label,
            'message'       => 'Employee archived; the code is free to reuse. The full backup is being prepared.',
        ]]);
    }

    /** GET /api/employees/archives — list archived (deleted) employees + backup status. */
    public function archives(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $rows = EmployeeArchive::where('company_id', $companyId)
            ->orderByDesc('archived_at')
            ->limit(1000)
            ->get()
            ->map(fn ($a) => [
                'id'            => $a->id,
                'label'         => $a->archive_label,
                'name'          => $a->employee_name,
                'code'          => $a->original_employee_code,
                'archived_at'   => $a->archived_at?->toDateTimeString(),
                'archived_by'   => $a->archivedBy?->name,
                'counts'        => $a->counts,
                'total_records' => array_sum($a->counts ?? []),
                'file_status'   => $a->file_status,
                'file_size'     => $a->file_size,
                'media_files'   => $a->media_files,
                'error'         => $a->error,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** GET /api/employees/archives/{archive}/download — stream the backup ZIP. */
    public function downloadArchive(Request $request, EmployeeArchive $archive)
    {
        abort_unless($archive->company_id === $request->user()->company_id, 403, 'Outside your tenant.');
        abort_unless($archive->file_status === 'READY' && $archive->storage_key, 404,
            'The backup file is not ready yet — it is still being prepared.');

        $this->audit($request, 'EXPORT', EmployeeArchive::class, $archive->id, ['label' => $archive->archive_label]);

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $archive->archive_label);

        $disk = Storage::disk($archive->storage_driver ?: 'local');
        if ($disk->exists($archive->storage_key)) {
            return $disk->download($archive->storage_key, $safe . '.zip');
        }

        // Legacy fallback: archives built before the disk-root fix (EPT25-02) were written
        // under storage/app/... instead of the 'local' disk root. Serve those directly.
        $legacy = storage_path('app/' . $archive->storage_key);
        abort_unless(is_file($legacy), 404, 'The backup file could not be found — it may still be preparing, or the background scheduler is not running.');

        return response()->download($legacy, $safe . '.zip');
    }

    /**
     * POST /api/employees/archives/{archive}/rebuild — Part C #22/#24. Rebuild a stuck or failed
     * archive ZIP SYNCHRONOUSLY, on demand. This is the escape hatch for an archive left on
     * "Preparing" because the minute scheduler is not running: it resets the row and builds it
     * now, so the admin never has to wait on a background worker that may be down.
     */
    public function rebuildArchive(Request $request, EmployeeArchive $archive): JsonResponse
    {
        abort_unless($archive->company_id === $request->user()->company_id, 403, 'Outside your tenant.');

        $archive->forceFill(['file_status' => 'PENDING', 'error' => null])->save();
        \Illuminate\Support\Facades\Artisan::call('smartept:build-archives', ['--id' => $archive->id]);

        $fresh = $archive->fresh();
        $this->audit($request, 'ARCHIVE_REBUILD', EmployeeArchive::class, $archive->id, [
            'label' => $archive->archive_label, 'status' => $fresh->file_status,
        ]);

        return response()->json(['data' => [
            'id'          => $fresh->id,
            'file_status' => $fresh->file_status,
            'error'       => $fresh->error,
            'file_size'   => $fresh->file_size,
            'media_files' => $fresh->media_files,
            'message'     => $fresh->file_status === 'READY'
                ? 'Archive rebuilt and ready to download.'
                : ($fresh->file_status === 'FAILED'
                    ? ('Rebuild failed: ' . $fresh->error)
                    : 'Rebuild started.'),
        ]]);
    }

    /**
     * POST /api/employees/archives/{archive}/restore — Part C #21. Bring a deleted employee back:
     * restore the row + original code, re-activate the linked login (password reset required on
     * next sign-in), keep role/branch. Device access is NOT auto-restored. Blocks on any live
     * conflict (code / email / biometric / user already in use) with the exact reason; optional
     * overrides let an authorised admin supply a fresh value instead of overwriting the other record.
     */
    public function restoreArchive(Request $request, EmployeeArchive $archive): JsonResponse
    {
        abort_unless($archive->company_id === $request->user()->company_id, 403, 'Outside your tenant.');

        $data = $request->validate([
            'new_employee_code' => ['nullable', 'string', 'max:64'],
            'new_email'         => ['nullable', 'email'],
            'new_biometric_id'  => ['nullable', 'string', 'max:64'],
        ]);

        $companyId = $archive->company_id;
        $employee = Employee::withTrashed()->where('company_id', $companyId)->find($archive->employee_id);
        abort_unless($employee, 404, 'The archived employee record could not be found.');
        abort_unless($employee->trashed(), 422, 'This employee is already active.');

        // validate() omits absent nullable keys, so read them defensively (?? null) before
        // ?: — otherwise a plain Restore (no new code typed) crashes with
        // "Undefined array key new_employee_code".
        $code  = ($data['new_employee_code'] ?? null) ?: $archive->original_employee_code;
        $email = ! empty($data['new_email']) ? $data['new_email'] : $employee->email;
        $bio   = ($data['new_biometric_id'] ?? null) ?: $employee->biometric_id;

        $conflict = fn ($field, $message, $options) => response()->json([
            'error' => ['field' => $field, 'message' => $message, 'options' => $options],
        ], 409);

        if (Employee::where('company_id', $companyId)->where('employee_code', $code)->where('id', '!=', $employee->id)->exists()) {
            return $conflict('employee_code',
                "Employee {$archive->original_employee_code} cannot be restored because Employee Code {$code} is currently assigned to another active employee.",
                ['change_employee_code', 'cancel']);
        }
        if ($email && Employee::where('company_id', $companyId)->where('email', $email)->where('id', '!=', $employee->id)->exists()) {
            return $conflict('email', "Email {$email} is already used by another active employee.", ['change_email', 'cancel']);
        }
        if ($email && User::where('email', $email)->where('id', '!=', (int) $employee->user_id)->exists()) {
            return $conflict('email', "Email {$email} is already used by another login account.", ['change_email', 'cancel']);
        }
        if ($bio && Employee::where('company_id', $companyId)->where('biometric_id', $bio)->where('id', '!=', $employee->id)->exists()) {
            return $conflict('biometric_id', "Biometric ID {$bio} is already used by another active employee.", ['change_biometric_id', 'cancel']);
        }
        if ($employee->user_id && Employee::where('company_id', $companyId)->where('user_id', $employee->user_id)->where('id', '!=', $employee->id)->exists()) {
            return $conflict('user_id', 'The linked login account is already attached to another active employee.', ['cancel']);
        }

        DB::transaction(function () use ($employee, $code, $email, $bio, $request) {
            $employee->restore();
            $employee->forceFill([
                'employee_code'     => $code,
                'email'             => $email,
                'biometric_id'      => $bio,
                'employment_status' => 'ACTIVE',
            ])->save();

            if ($employee->user_id) {
                $u = User::find($employee->user_id);
                if ($u) {
                    // Re-activate + force a password reset. Device access is NOT auto-restored.
                    $u->forceFill(['status' => 'ACTIVE', 'must_change_password' => true])->save();
                }
            }

            $this->audit($request, 'RESTORE_EMPLOYEE', Employee::class, $employee->id, [
                'employee_code' => $code, 'email' => $email,
            ]);
        });

        return response()->json(['data' => [
            'employee_id'   => $employee->id,
            'employee_code' => $code,
            'message'       => 'Employee restored and the login re-activated (password reset required on next sign-in). Device access was not auto-restored.',
        ]]);
    }

    private function validated(Request $request, bool $creating, ?Employee $employee = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        // employee_code is unique per company (DB has a composite unique index) —
        // validate it here so a duplicate returns 422 instead of a 500 from the DB.
        $companyId = $request->user()->company_id ?? $employee?->company_id;

        return $request->validate([
            'employee_code'        => [
                $req, 'string', 'max:64',
                Rule::unique('employees', 'employee_code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($employee?->id),
            ],
            'first_name'           => [$req, 'string', 'max:255'],
            'last_name'            => ['nullable', 'string', 'max:255'],
            'email'                => ['nullable', 'email'],
            'mobile'               => ['nullable', 'string', 'max:32'],
            'branch_id'            => ['nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'department_id'        => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'team_id'              => ['nullable', 'integer', Rule::exists('teams', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'designation_id'       => ['nullable', 'integer', Rule::exists('designations', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'shift_id'             => ['nullable', 'integer', Rule::exists('shifts', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'manager_user_id'      => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'reporting_manager_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'user_id'              => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'employment_status'    => ['nullable', 'in:ACTIVE,ON_LEAVE,RELIEVED'],
            'date_of_joining'      => ['nullable', 'date'],
            'biometric_id'         => ['nullable', 'string', 'max:64'],
            'smartprs_employee_id' => ['nullable', 'string', 'max:64'],
            'smartdcm_user_id'     => ['nullable', 'string', 'max:64'],
            'monitoring_policy_id' => ['nullable', 'integer', Rule::exists('monitoring_policies', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'compliance_policy_id' => ['nullable', 'integer', Rule::exists('compliance_policies', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            // Tracking mode override (null = inherit from team/dept/branch/company).
            'tracking_mode'        => ['nullable', 'in:FULL,PRESENCE_ONLY,EXCLUDED'],
        ]);
    }

    /**
     * POST /api/employees/bulk-import  (multipart CSV, or JSON {rows:[...]})
     *
     * Ejaz 17-Jul (SmartPRS parity): onboard a whole company at once. One row
     * per employee; org units (department/team/branch/designation/shift) are
     * matched BY NAME within the company and auto-created when missing, so IT
     * can paste an HR sheet without hunting for numeric IDs first. Each row is
     * validated and reported independently — a few bad rows never block the
     * good ones. dry_run=true previews without writing.
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        abort_unless($companyId, 422, 'Sign in as a company admin to import employees.');

        // Accept an uploaded CSV or a JSON rows array (same shape either way).
        $rows = [];
        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'mimes:csv,txt', 'max:4096']]);
            $rows = $this->parseCsv($request->file('file')->getRealPath());
        } elseif (is_array($request->input('rows'))) {
            $rows = $request->input('rows');
        }
        if (empty($rows)) {
            return response()->json(['ok' => false, 'error' => 'No rows found. Upload a CSV with a header row, or send rows[].'], 422);
        }
        if (count($rows) > 2000) {
            return response()->json(['ok' => false, 'error' => 'Please import at most 2000 rows at a time.'], 422);
        }

        $dryRun = $request->boolean('dry_run', false);
        $createLogin = $request->boolean('create_login', true);

        $results = [];
        $created = 0; $failed = 0; $credentials = [];
        $seenCodes = [];

        foreach ($rows as $i => $raw) {
            $line = $i + 2; // header is line 1
            $r = $this->normaliseRow($raw);

            if (($r['employee_code'] ?? '') === '' && ($r['first_name'] ?? '') === '') {
                continue; // skip blank lines silently
            }

            // Duplicate code inside the same file.
            $codeKey = strtolower(trim($r['employee_code'] ?? ''));
            if ($codeKey !== '' && isset($seenCodes[$codeKey])) {
                $results[] = ['line' => $line, 'ok' => false, 'code' => $r['employee_code'], 'error' => 'Duplicate employee_code within this file (line ' . $seenCodes[$codeKey] . ').'];
                $failed++; continue;
            }
            $seenCodes[$codeKey] = $line;

            try {
                $row = DB::transaction(function () use ($r, $companyId, $dryRun, $createLogin, &$credentials) {
                    // Resolve / create org units by name.
                    $attrs = [
                        'employee_code'  => trim($r['employee_code'] ?? ''),
                        'first_name'     => trim($r['first_name'] ?? ''),
                        'last_name'      => trim($r['last_name'] ?? '') ?: null,
                        'email'          => trim($r['email'] ?? '') ?: null,
                        'mobile'         => trim($r['mobile'] ?? '') ?: null,
                        'department_id'  => $this->resolveUnit(Department::class, $r['department'] ?? '', $companyId),
                        'team_id'        => $this->resolveUnit(Team::class, $r['team'] ?? '', $companyId),
                        'branch_id'      => $this->resolveUnit(Branch::class, $r['branch'] ?? '', $companyId),
                        'designation_id' => $this->resolveUnit(Designation::class, $r['designation'] ?? '', $companyId),
                        'shift_id'       => $this->resolveUnit(Shift::class, $r['shift'] ?? '', $companyId),
                        'biometric_id'   => trim($r['biometric_id'] ?? '') ?: null,
                        'date_of_joining' => $this->parseDate($r['date_of_joining'] ?? ''),
                        'employment_status' => 'ACTIVE',
                    ];

                    // Per-row validation with the same rules as single-create.
                    $v = validator($attrs + ['company_id' => $companyId], [
                        'employee_code' => ['required', 'string', 'max:64',
                            Rule::unique('employees', 'employee_code')->where(fn ($q) => $q->where('company_id', $companyId))],
                        'first_name' => ['required', 'string', 'max:255'],
                        'email' => ['nullable', 'email'],
                    ]);
                    if ($v->fails()) {
                        throw new \RuntimeException(implode(' ', $v->errors()->all()));
                    }

                    if ($dryRun) {
                        return ['would_create' => true];
                    }

                    $employee = Employee::create($attrs);
                    $out = ['employee_id' => $employee->id];

                    if ($createLogin && $employee->email && ! User::where('email', $employee->email)->exists()) {
                        $temp = Str::password(10);
                        $login = User::create([
                            'name' => $employee->fullName(), 'email' => $employee->email, 'password' => $temp,
                            'company_id' => $companyId, 'branch_id' => $employee->branch_id,
                            'role_id' => Role::where('slug', 'EMPLOYEE')->whereNull('company_id')->value('id'),
                            'status' => 'ACTIVE', 'must_change_password' => true,
                        ]);
                        $employee->update(['user_id' => $login->id]);
                        MailService::sendCredentials($login, $temp);
                        $credentials[] = ['email' => $login->email, 'temp_password' => $temp];
                        $out['login'] = $login->email;
                    }
                    return $out;
                });

                $results[] = ['line' => $line, 'ok' => true, 'code' => $r['employee_code']] + $row;
                $created++;
            } catch (\Throwable $e) {
                $results[] = ['line' => $line, 'ok' => false, 'code' => $r['employee_code'] ?? '', 'error' => $e->getMessage()];
                $failed++;
            }
        }

        if (! $dryRun) {
            $this->audit($request, 'BULK_IMPORT', Employee::class, null, ['created' => $created, 'failed' => $failed, 'rows' => count($results)]);
        }

        return response()->json([
            'ok' => true,
            'dry_run' => $dryRun,
            'summary' => ['rows' => count($results), 'created' => $created, 'failed' => $failed],
            'results' => $results,
            'credentials' => $credentials, // shown once so IT can hand them out
        ]);
    }

    /** Case-insensitive resolve of an org unit by name within the company; auto-creates when missing. */
    private function resolveUnit(string $model, ?string $name, int $companyId): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $existing = $model::where('company_id', $companyId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing->id;
        }
        return $model::create(['company_id' => $companyId, 'name' => $name])->id;
    }

    /** Normalise a raw row: lowercase keys, trim, accept a few header aliases. */
    private function normaliseRow(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $key = strtolower(trim((string) $k));
            $key = str_replace([' ', '-'], '_', $key);
            $out[$key] = is_string($v) ? trim($v) : $v;
        }
        $alias = [
            'code' => 'employee_code', 'emp_code' => 'employee_code', 'empcode' => 'employee_code',
            'firstname' => 'first_name', 'name' => 'first_name', 'lastname' => 'last_name',
            'phone' => 'mobile', 'contact' => 'mobile', 'dept' => 'department',
            'doj' => 'date_of_joining', 'joining_date' => 'date_of_joining',
            'biometric' => 'biometric_id', 'bio_id' => 'biometric_id',
        ];
        foreach ($alias as $from => $to) {
            if (isset($out[$from]) && ! isset($out[$to])) {
                $out[$to] = $out[$from];
            }
        }
        return $out;
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        if (! is_readable($path)) {
            return $rows;
        }
        $fh = fopen($path, 'r');
        $header = null;
        while (($cells = fgetcsv($fh)) !== false) {
            if ($header === null) {
                // Strip a UTF-8 BOM off the first header cell if present.
                if (isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
                }
                $header = array_map(fn ($h) => trim((string) $h), $cells);
                continue;
            }
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $idx => $col) {
                $row[$col] = $cells[$idx] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private function parseDate(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * GET /api/employees/{employee}/policy-trace — read-only diagnostic: WHY each monitored
     * capability (tracking / app usage / website usage / screenshots / webcam) is on or off
     * for this employee, showing the winning policy + the precedence level that set it, plus
     * the effective tracking mode. Explains cases like "Tracking off despite a Full-Track team".
     */
    public function policyTrace(Request $request, Employee $employee, PolicyResolver $resolver): JsonResponse
    {
        abort_unless($employee->company_id === $request->user()->company_id, 403, 'Outside your tenant.');

        return response()->json(['data' => $resolver->traceForEmployee($employee)]);
    }

}
