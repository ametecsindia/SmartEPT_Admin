<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;

/**
 * R6 reporting hierarchy — the SINGLE source of truth for "which employees may this
 * logged-in user see". Reuses SmartEPT's user-based reporting (managers/team-leads log
 * in as users; employees carry reporting_manager_user_id / team / manager_user_id).
 *
 * Roles resolve as:
 *   SUPER/COMPANY/HR/COMPLIANCE/AUDITOR -> whole company (null = unrestricted)
 *   BRANCH_ADMIN                         -> employees in the admin's own branch only
 *   MANAGER / TEAM_LEADER (+ others)     -> their reporting subtree only
 */
class HierarchyService
{
    /** Roles that see the whole company (BelongsToCompany still tenant-bounds them). */
    private const UNRESTRICTED = ['SUPER_ADMIN', 'COMPANY_ADMIN', 'HR_ADMIN', 'COMPLIANCE_OFFICER', 'AUDITOR'];

    /** Employee ids this user may see. null = whole company. */
    public function visibleEmployeeIds(User $user): ?array
    {
        $slug = $user->role?->base_slug ?: $user->roleSlug();

        if (in_array($slug, self::UNRESTRICTED, true)) {
            return null;
        }

        if ($slug === 'BRANCH_ADMIN') {
            // Branch Admin: their assigned branch only (fixes the old "sees whole company" gap).
            $branchId = $user->branch_id;

            return $branchId
                ? Employee::query()->where('branch_id', $branchId)->pluck('id')->all()
                : $this->ownRowOnly($user); // no branch assigned yet -> only self, never the company
        }

        return $this->subtreeEmployeeIds($user);
    }

    /**
     * Employees under a manager/team-lead: their direct reports (reporting_manager_user_id
     * or legacy manager_user_id), everyone in teams they lead/manage, everyone under the
     * team-leads within their managed teams, and their own employee row.
     */
    public function subtreeEmployeeIds(User $user): array
    {
        // Supervisors in scope: the user + team-leads of the teams they MANAGE
        // (so a Manager sees the people under each of their Team Leads).
        $supervisorIds = [$user->id];
        $subLeadIds = Team::query()->where('manager_user_id', $user->id)
            ->whereNotNull('team_leader_user_id')->pluck('team_leader_user_id')->all();
        $supervisorIds = array_values(array_unique(array_merge($supervisorIds, $subLeadIds)));

        $teamIds = Team::query()
            ->where(fn ($q) => $q->whereIn('team_leader_user_id', $supervisorIds)
                                 ->orWhereIn('manager_user_id', $supervisorIds))
            ->pluck('id')->all();

        return Employee::query()
            ->where(fn ($q) => $q
                ->whereIn('reporting_manager_user_id', $supervisorIds)
                ->orWhereIn('manager_user_id', $supervisorIds)
                ->orWhereIn('team_id', $teamIds ?: [0])
                ->orWhere('user_id', $user->id))
            ->pluck('id')->all();
    }

    private function ownRowOnly(User $user): array
    {
        return Employee::query()->where('user_id', $user->id)->pluck('id')->all();
    }

    /** True if the target employee is inside the caller's visibility scope. */
    public function canViewEmployee(User $user, int $employeeId): bool
    {
        $ids = $this->visibleEmployeeIds($user);

        return $ids === null || in_array($employeeId, $ids, true);
    }

    /** Direct reports of a supervisor user (reporting_manager_user_id or legacy manager_user_id). */
    public function getDirectReports(int $userId): array
    {
        return Employee::query()
            ->where(fn ($q) => $q->where('reporting_manager_user_id', $userId)
                                 ->orWhere('manager_user_id', $userId))
            ->pluck('id')->all();
    }

    public function getEmployeesByBranch(int $branchId): array
    {
        return Employee::query()->where('branch_id', $branchId)->pluck('id')->all();
    }

    /**
     * Reporting-manager validation: no self-report and no cycle. Walks UP the proposed
     * manager's own reporting chain; if it leads back to this employee's own login user,
     * the link would close a loop and is rejected. $seen guards a pre-existing loop.
     */
    public function validateReportingManager(?int $employeeId, ?int $reportingManagerUserId): bool
    {
        if (! $reportingManagerUserId || ! $employeeId) {
            return true;
        }
        $emp = Employee::find($employeeId);
        if (! $emp || ! $emp->user_id) {
            return true; // a non-login employee can never be someone's supervisor -> no cycle
        }
        $selfUser = (int) $emp->user_id;

        $seen = [];
        $cursor = (int) $reportingManagerUserId;
        while ($cursor && ! in_array($cursor, $seen, true)) {
            if ($cursor === $selfUser) {
                return false; // self-report or a loop back to this employee
            }
            $seen[] = $cursor;
            // the manager-user's own supervisor = their employee row's reporting_manager_user_id
            $cursor = (int) (Employee::where('user_id', $cursor)->value('reporting_manager_user_id') ?? 0);
        }

        return true;
    }
}
