<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * QA Phase 6 (B9) — shared validation for HR manual attendance (regularize an existing
 * day = PUT /attendance/{attendance}; add a missed day = POST /attendance).
 *
 * The core guard: a manual CHECK-OUT can never be in the future (server time) — that
 * used to let a payroll edit credit hours that hadn't happened yet. Check-out must also
 * be at/after check-in. The future test uses Carbon::isFuture() (honours the app clock /
 * test travel), not the `before_or_equal:now` string rule (which reads system time).
 * Route middleware already enforces the HR/Admin role; this only shapes the payload.
 */
class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->route('attendance') !== null;
        $companyId = $this->user()->company_id;

        $rules = [
            'status'       => ['required', Rule::in(['PRESENT', 'ABSENT', 'HALF_DAY', 'ON_LEAVE'])],
            'check_in_at'  => ['nullable', 'date'],
            'check_out_at' => [
                'nullable', 'date', 'after_or_equal:check_in_at',
                function ($attribute, $value, $fail) {
                    if ($value && Carbon::parse($value)->isFuture()) {
                        $fail('Check-out time cannot be later than the current time.');
                    }
                },
            ],
            'reason'       => ['required', 'string', 'max:500'], // no silent payroll edits
        ];

        // Adding a missed day also needs a tenant-scoped employee + the date.
        if (! $isUpdate) {
            $rules['employee_id'] = [
                'required',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ];
            $rules['work_date'] = ['required', 'date'];
        }

        return $rules;
    }
}
