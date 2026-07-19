<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * R5 EPT-12: report visibility scoping. Admin-ish roles see the whole company
 * (BelongsToCompany already bounds them to their tenant); a MANAGER/TEAM_LEADER
 * sees only the people in the teams they lead/manage, their direct reports, and
 * their own employee row.
 */
trait ScopesVisibleEmployees
{
    /** Employee ids this user may see in reports. null = unrestricted (whole company). */
    protected function visibleEmployeeIds(User $user): ?array
    {
        // Honour base_slug so custom console roles inherit their base role's reach.
        $slug = $user->role?->base_slug ?: $user->roleSlug();
        $unrestricted = ['SUPER_ADMIN', 'COMPANY_ADMIN', 'BRANCH_ADMIN', 'HR_ADMIN', 'COMPLIANCE_OFFICER', 'AUDITOR'];
        if (in_array($slug, $unrestricted, true)) {
            return null;
        }

        // Team + Employee are company-scoped by the BelongsToCompany global scope.
        $teamIds = Team::query()
            ->where(fn ($q) => $q->where('team_leader_user_id', $user->id)->orWhere('manager_user_id', $user->id))
            ->pluck('id');

        return Employee::query()
            ->where(fn ($q) => $q->whereIn('team_id', $teamIds)
                                 ->orWhere('manager_user_id', $user->id)
                                 ->orWhere('user_id', $user->id))
            ->pluck('id')->all();
    }

    /**
     * EPT org roll-up: report-visible ids further narrowed by an optional org
     * filter (?branch_id / ?department_id / ?team_id). Cascades company > branch
     * > department > team. The filter can only NARROW: a manager's own scope
     * still bounds the result, so it never widens visibility. Returns null only
     * when the caller is unrestricted AND no org filter is applied.
     */
    protected function scopedEmployeeIds(Request $request): ?array
    {
        $base = $this->visibleEmployeeIds($request->user());

        // Org roll-up cascades company > branch > department > team > individual.
        // employee_id filters on the row id; the rest are org FKs on the employee.
        $q = Employee::query();
        $applied = false;
        foreach (['branch_id' => 'branch_id', 'department_id' => 'department_id', 'team_id' => 'team_id', 'employee_id' => 'id'] as $param => $col) {
            $val = $request->query($param);
            if ($val !== null && $val !== '') {
                $q->where($col, (int) $val);
                $applied = true;
            }
        }
        if (! $applied) {
            return $base;                 // no org filter -> role scope only
        }
        $orgIds = $q->pluck('id')->all(); // BelongsToCompany already tenant-bounds this

        return $base === null
            ? $orgIds                                              // unrestricted role -> just the org subset
            : array_values(array_intersect($base, $orgIds));      // manager -> narrow within their scope
    }

    /** 403 unless the given employee id is within the caller's report scope. */
    protected function assertEmployeeVisible(Request $request, int $employeeId): void
    {
        $visible = $this->visibleEmployeeIds($request->user());
        abort_unless($visible === null || in_array($employeeId, $visible, true), 403, 'Employee is not in your report scope.');
    }
}
