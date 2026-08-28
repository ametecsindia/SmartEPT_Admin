<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
use App\Models\PolicyRule;
use App\Models\Shift;
use App\Models\Team;
use App\Services\PolicyResolver;
use App\Support\EnforcementMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * "Why is this person blocked and that one not?"
 *
 * Blocking follows the PERSON, and it resolves through six levels. So the honest
 * answer to that question is a short chain of reasoning, and until now the only
 * way to see it was to read the database by hand.
 *
 * This exists because a support answer that requires running a script on the
 * server is not a support answer — it is a promise to be available. The client's
 * own IT has to be able to see it, at 9am, without us.
 */
class EnforcementDiagnosticsController extends Controller
{
    /** GET /api/enforcement/diagnostics */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $user->company_id;

        return response()->json([
            'ok' => true,
            'data' => [
                'schema'    => $this->schemaCheck(),
                'tenant'    => $this->tenant($companyId),
                'machines'  => $this->machines($companyId),
                'rules'     => $this->rules($companyId),
                'employees' => $this->employees($companyId),
            ],
        ]);
    }

    /**
     * Migrations this feature depends on.
     *
     * Not housekeeping. The heartbeat writes signed_in_employee_id on every
     * beat, and a missing column makes that write throw — so the endpoint gets
     * no directive at all and keeps whatever policy it already had. Machines
     * that were blocking stay blocked, machines that were not never start, and
     * nothing on either of them looks wrong.
     *
     * That failure presents as "it works for some employees and not others",
     * which is the hardest kind of report to act on. So it is checked first and
     * said plainly.
     *
     * @return array<string,mixed>
     */
    private function schemaCheck(): array
    {
        $needed = [
            'enforcement_machines' => ['signed_in_employee_id'],
            'employees'            => ['enforcement_mode', 'enforcement_exempt_from', 'enforcement_exempt_until'],
        ];

        $missing = [];
        foreach ($needed as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    $missing[] = "$table.$column";
                }
            }
        }

        return [
            'ok'      => $missing === [],
            'missing' => $missing,
            'fix'     => $missing === [] ? null : 'php artisan migrate',
            'why'     => $missing === [] ? null
                : 'Until this runs, every endpoint heartbeat fails. Machines keep the last policy they were given, so some block and some do not, and nothing on the PC looks wrong.',
        ];
    }

    /**
     * Every rule, and whether it actually reaches a PC.
     *
     * A rule that is saved in the console and never sent is the defect this
     * whole product exists to remove: the admin sees it, believes it, and it
     * does nothing. Two conditions have to hold, and BOTH are easy to miss:
     *
     *   action  CLOSE or BLOCK          "What happens" must actually prevent
     *   status  BLOCKED or VIOLATION    the row must be armed, not merely tracked
     *
     * A rule with action BLOCK and status TRACKED looks completely correct on
     * the Rules screen and is silently dropped. That is worth naming in the
     * console rather than leaving it to be discovered at a client.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rules(int $companyId): array
    {
        $hard = ['CLOSE', 'BLOCK'];
        $live = ['BLOCKED', 'VIOLATION'];

        return PolicyRule::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('policy_type')
            ->orderBy('item')
            ->get()
            ->map(function (PolicyRule $r) use ($hard, $live) {
                $action = strtoupper((string) $r->action);
                $status = strtoupper((string) $r->status);

                $why = [];
                if (! in_array($action, $hard, true)) {
                    $why[] = sprintf('"What happens" is %s — it has to be Close or Block to prevent anything',
                        $action ?: 'not set');
                }
                if (! in_array($status, $live, true)) {
                    $why[] = sprintf('the row is %s, not armed', $status ?: 'not set');
                }

                return [
                    'type'   => strtoupper((string) $r->policy_type),
                    'item'   => (string) $r->item,
                    'label'  => (string) ($r->label ?: $r->item),
                    'action' => $action,
                    'status' => $status,
                    'sent'   => $why === [],
                    'why'    => $why === [] ? 'Sent to every enforced PC.' : implode('; ', $why),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function tenant(int $companyId): array
    {
        $state = EnforcementState::forCompany($companyId);
        $mode = $state->effectiveMode() ?: EnforcementState::OFF;

        // A site upgraded mid-learning stores AUDIT and acts OFF. Say so here rather than
        // showing a bare "Off" that contradicts what somebody can read in the database.
        $stale = $state->mode === EnforcementState::AUDIT && ! EnforcementState::learningEnabled();

        return [
            'mode' => $mode,
            'note' => match (true) {
                $mode === 'ENFORCE' => 'Rules are being applied on employee PCs.',
                $mode === 'AUDIT'   => 'Learning. Launches are logged; nothing is refused on any PC.',
                $stale => 'Off. This site was left mid-learning by an older build; learning has '
                    . 'since been removed, so nothing is being collected and nothing is blocked. '
                    . 'Turn enforcement on when you are ready.',
                default => 'Off. Nothing is blocked for anybody, whatever the rules say.',
            },
        ];
    }

    /**
     * Every enrolled PC, and who it says is at the keyboard.
     *
     * @return array<int,array<string,mixed>>
     */
    private function machines(int $companyId): array
    {
        $hasColumn = Schema::hasColumn('enforcement_machines', 'signed_in_employee_id');

        return EnforcementMachine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('hostname')
            ->get()
            ->map(function (EnforcementMachine $m) use ($hasColumn) {
                $employee = null;
                if ($hasColumn && $m->signed_in_employee_id) {
                    $e = Employee::withoutGlobalScopes()->find($m->signed_in_employee_id);
                    $employee = $e ? ['id' => $e->id, 'name' => $e->fullName()] : null;
                }

                // An endpoint that stopped checking in is not applying anything
                // new. First thing to rule out before blaming a rule.
                $stale = $m->last_seen_at === null || $m->last_seen_at->lt(now()->subMinutes(15));

                return [
                    'hostname'     => $m->hostname ?: substr((string) $m->machine_id, 0, 12),
                    'health'       => $m->enforcement_health ?: 'UNKNOWN',
                    'policy'       => (int) ($m->applied_policy_version ?? 0),
                    'signed_in'    => $employee,
                    'last_seen_at' => $m->last_seen_at?->toIso8601String(),
                    'stale'        => $stale,
                    'note'         => $stale
                        ? 'Has not checked in for over 15 minutes. Its agent or the policy service is not running.'
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every employee, whether they are enforced, and WHICH LEVEL decided it.
     *
     * The level is the part that matters. "I set it on the person and they are
     * still blocked" is almost always an EXEMPT inherited from a team,
     * department or branch — nothing on their own record looks wrong, so
     * without this line there is nowhere to look.
     *
     * @return array<int,array<string,mixed>>
     */
    private function employees(int $companyId): array
    {
        $resolver = app(PolicyResolver::class);
        $hasColumns = Schema::hasColumn('employees', 'enforcement_mode');

        return Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $e) use ($resolver, $companyId, $hasColumns) {
                $mode = $hasColumns ? $resolver->effectiveEnforcementMode($e) : EnforcementMode::DEFAULT;
                [$level, $why] = $hasColumns
                    ? $this->decidedBy($e, $companyId, $mode)
                    : ['default', 'The per-employee columns are not in the database yet.'];

                return [
                    'id'    => $e->id,
                    'name'  => $e->fullName(),
                    'code'  => $e->employee_code,
                    'mode'  => $mode,
                    'level' => $level,
                    'why'   => $why,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Walk the same levels the resolver walks, in the same order, and report where it stopped.
     *
     * @return array{0:string,1:string}
     */
    private function decidedBy(Employee $e, int $companyId, string $mode): array
    {
        if ($e->enforcement_exempt_from || $e->enforcement_exempt_until) {
            $window = sprintf('%s to %s',
                $e->enforcement_exempt_from?->toDateString() ?: 'now',
                $e->enforcement_exempt_until?->toDateString() ?: 'no end date');

            return $mode === EnforcementMode::EXEMPT
                ? ['dated exemption', "Exempt $window" . ($e->enforcement_exempt_reason ? ' — ' . $e->enforcement_exempt_reason : '')]
                : ['dated exemption', "An exemption exists ($window) but is NOT in force today."];
        }

        if (EnforcementMode::clean($e->enforcement_mode)) {
            return ['employee', 'Set on this person.'];
        }

        // Same order as PolicyResolver::effectiveEnforcementMode(), shift included. If these
        // two lists ever disagree the console explains a decision the server did not make,
        // which is worse than explaining nothing.
        foreach ([
            ['shift', $e->shift_id, Shift::class],
            ['team', $e->team_id, Team::class],
            ['department', $e->department_id, Department::class],
            ['branch', $e->branch_id, Branch::class],
        ] as [$label, $id, $class]) {
            if (! $id) {
                continue;
            }
            $row = $class::withoutGlobalScopes()->find($id);
            if ($row && EnforcementMode::clean($row->enforcement_mode)) {
                return [$label, sprintf('Inherited from %s "%s". Nothing is set on the person.', $label, $row->name ?? $id)];
            }
        }

        $company = Company::find($companyId);
        if ($company && EnforcementMode::clean($company->enforcement_mode)) {
            return ['company', 'Set on the company. Nothing is set on the person, shift, team, department or branch.'];
        }

        return ['default', 'Nothing set at any level. The default is ENFORCED.'];
    }
}
