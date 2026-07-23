<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR attendance regularization: list the sheet, correct a day, or add a missed
 * day. Every change requires a reason (kept on the row's notes with time +
 * actor) and is audit-logged — corrections feed payroll, so they must be
 * accountable. Tenant isolation comes from the model's company scope.
 */
class AttendanceAdminController extends Controller
{
    /** GET /api/attendance?date=&employee_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $logs = EmployeeAttendanceLog::with('employee:id,employee_code,first_name,last_name')
            ->when($request->query('date'), fn ($q, $v) => $q->whereDate('work_date', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('work_date')->orderBy('employee_id')
            ->paginate((int) $request->integer('per_page', 50));

        $logs->getCollection()->transform(fn ($r) => [
            'id'                   => $r->id,
            'employee_id'          => $r->employee_id,
            'employee_name'        => $r->employee?->fullName(),
            'employee_code'        => $r->employee?->employee_code,
            'work_date'            => $r->work_date?->toDateString(),
            'status'               => $r->status,
            'source'               => $r->source,
            'check_in_at'          => $r->check_in_at?->toDateTimeString(),
            'check_out_at'         => $r->check_out_at?->toDateTimeString(),
            'late_minutes'         => $r->late_minutes,
            'early_logout_minutes' => $r->early_logout_minutes,
            // QA Phase 3 (B7/B8): how each value was derived (audit — raw stays raw).
            'check_in_source'      => $r->check_in_source,
            'check_out_source'     => $r->check_out_source,
            'arrival_source_used'  => $r->arrival_source_used,
            'derivation_note'      => $r->derivation_note,
            'notes'                => $r->notes,
        ]);

        return response()->json($logs);
    }

    /** PUT /api/attendance/{attendance} — regularize an existing day. */
    public function update(Request $request, EmployeeAttendanceLog $attendance): JsonResponse
    {
        $data = $request->validate([
            'status'       => ['required', Rule::in(['PRESENT', 'ABSENT', 'HALF_DAY', 'ON_LEAVE'])],
            'check_in_at'  => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'reason'       => ['required', 'string', 'max:500'], // no silent payroll edits
        ]);

        $before = $attendance->only(['status', 'source', 'check_in_at', 'check_out_at']);

        $attendance->update([
            'status'       => $data['status'],
            'source'       => 'MANUAL', // the row no longer reflects raw agent/biometric data
            'check_in_at'  => $data['check_in_at'] ?? $attendance->check_in_at,
            'check_out_at' => $data['check_out_at'] ?? $attendance->check_out_at,
            'notes'        => $this->appendNote($attendance->notes, $request, $data['reason']),
        ]);

        $this->audit($request, 'UPDATE', EmployeeAttendanceLog::class, $attendance->id, [
            'before' => $before, 'after' => $data,
        ]);

        return response()->json(['data' => $attendance->fresh()]);
    }

    /** POST /api/attendance — add a missed day (e.g. leave never recorded). */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'employee_id' => [
                'required',
                // Tenant-scoped: an HR admin must not create rows for another company's employees.
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'work_date'    => ['required', 'date'],
            'status'       => ['required', Rule::in(['PRESENT', 'ABSENT', 'HALF_DAY', 'ON_LEAVE'])],
            'check_in_at'  => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'reason'       => ['required', 'string', 'max:500'],
        ]);

        // One attendance verdict per employee per day, regardless of source — a second
        // row would double-count in payroll.
        $exists = EmployeeAttendanceLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $data['employee_id'])
            ->whereDate('work_date', $data['work_date'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'work_date' => 'An attendance record already exists for this employee and date. Use PUT to modify it.',
            ]);
        }

        $log = EmployeeAttendanceLog::create([
            'company_id'   => $companyId,
            'employee_id'  => $data['employee_id'],
            'work_date'    => $data['work_date'],
            'status'       => $data['status'],
            'source'       => 'MANUAL',
            'check_in_at'  => $data['check_in_at'] ?? null,
            'check_out_at' => $data['check_out_at'] ?? null,
            'notes'        => $this->appendNote(null, $request, $data['reason']),
        ]);

        $this->audit($request, 'CREATE', EmployeeAttendanceLog::class, $log->id, $data);

        return response()->json(['data' => $log], 201);
    }

    /** Append "[time] actor: reason" so the row carries its full correction history. */
    private function appendNote(?string $notes, Request $request, string $reason): string
    {
        $line = sprintf('[%s] %s: %s', now()->toDateTimeString(), $request->user()->email, $reason);

        return trim(($notes ? $notes . "\n" : '') . $line);
    }
}
