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

    /** 403 unless the given employee id is within the caller's report scope. */
    protected function assertEmployeeVisible(Request $request, int $employeeId): void
    {
        $visible = $this->visibleEmployeeIds($request->user());
        abort_unless($visible === null || in_array($employeeId, $visible, true), 403, 'Employee is not in your report scope.');
    }
}
