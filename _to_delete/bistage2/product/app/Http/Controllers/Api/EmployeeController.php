<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /** GET /api/employees — tenant-scoped, filterable by team/department/branch/status. */
    public function index(Request $request): JsonResponse
    {
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
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($employees);
    }

    /** GET /api/employees/{employee} */
    public function show(Employee $employee): JsonResponse
    {
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
        $data = $this->validated($request, false, $employee);
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

    /** DELETE /api/employees/{employee} */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $employee->delete();
        $this->audit($request, 'DELETE', Employee::class, $employee->id);

        return response()->json(null, 204);
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
            'branch_id'            => ['nullable', 'integer', 'exists:branches,id'],
            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'team_id'              => ['nullable', 'integer', 'exists:teams,id'],
            'designation_id'       => ['nullable', 'integer', 'exists:designations,id'],
            'shift_id'             => ['nullable', 'integer', 'exists:shifts,id'],
            'manager_user_id'      => ['nullable', 'integer', 'exists:users,id'],
            'user_id'              => ['nullable', 'integer', 'exists:users,id'],
            'employment_status'    => ['nullable', 'in:ACTIVE,ON_LEAVE,RELIEVED'],
            'date_of_joining'      => ['nullable', 'date'],
            'biometric_id'         => ['nullable', 'string', 'max:64'],
            'smartprs_employee_id' => ['nullable', 'string', 'max:64'],
            'smartdcm_user_id'     => ['nullable', 'string', 'max:64'],
            'monitoring_policy_id' => ['nullable', 'integer'],
            'compliance_policy_id' => ['nullable', 'integer'],
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

}
