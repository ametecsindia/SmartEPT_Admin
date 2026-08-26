<?php
/**
 * Why is this employee blocked and that one not?
 *
 * Prints, for every enrolled machine and every employee, exactly what the server
 * decides and why. Blocking follows the PERSON now, so "it works for some and
 * not others" has a specific answer per person, and this prints it.
 *
 *   php ept-enforce.php
 *
 * ponytail: a plain script. It answers one question and is thrown away when it
 * stops being asked.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
use App\Services\PolicyResolver;
use App\Support\EnforcementMode;
use Illuminate\Support\Facades\Schema;

function line(string $c = '-'): void { echo str_repeat($c, 96), PHP_EOL; }

line('=');
echo "  SmartEPT — who is enforced, and why\n";
line('=');
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 0. The migrations this depends on. A missing column here is not a detail:
//    the heartbeat writes signed_in_employee_id on every beat, and a missing
//    column makes that write throw. The endpoint then gets no directive at all
//    and keeps whatever policy it already had — which looks exactly like
//    "blocking works for some machines and not others".
echo "  SCHEMA\n";
$needed = [
    'enforcement_machines' => ['signed_in_employee_id'],
    'employees'            => ['enforcement_mode', 'enforcement_exempt_from', 'enforcement_exempt_until'],
];
$missing = [];
foreach ($needed as $table => $cols) {
    foreach ($cols as $col) {
        $ok = Schema::hasTable($table) && Schema::hasColumn($table, $col);
        printf("    %-24s %-28s %s\n", $table, $col, $ok ? 'present' : '*** MISSING ***');
        if (! $ok) {
            $missing[] = "$table.$col";
        }
    }
}
if ($missing) {
    echo PHP_EOL;
    echo "    STOP. Run:  php artisan migrate\n";
    echo "    Until then the heartbeat fails and endpoints keep their last policy,\n";
    echo "    which is why some are blocking and some are not.\n";
    echo PHP_EOL;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 1. What each machine is doing, and who it says is at the keyboard.
echo "  MACHINES\n";
$machines = EnforcementMachine::withoutGlobalScopes()->orderBy('id')->get();

if ($machines->isEmpty()) {
    echo "    none enrolled\n";
}

foreach ($machines as $m) {
    $who = 'nobody signed in';
    if (Schema::hasColumn('enforcement_machines', 'signed_in_employee_id') && $m->signed_in_employee_id) {
        $e = Employee::withoutGlobalScopes()->find($m->signed_in_employee_id);
        $who = $e ? sprintf('%s (id %d)', $e->fullName(), $e->id) : ('id ' . $m->signed_in_employee_id . ' — NOT FOUND');
    }

    $seen = $m->last_seen_at ? $m->last_seen_at->diffForHumans() : 'never';

    printf("    %-22s  company %-3d  policy v%-4s  %s\n",
        $m->hostname ?: substr((string) $m->machine_id, 0, 12),
        $m->company_id,
        (string) ($m->applied_policy_version ?? 0),
        $m->enforcement_health ?? 'UNKNOWN');
    printf("        signed in : %s\n", $who);
    printf("        last seen : %s\n", $seen);

    // An endpoint that has not checked in is not enforcing anything new, and
    // that is the first thing to rule out before blaming a rule.
    if ($m->last_seen_at && $m->last_seen_at->lt(now()->subMinutes(15))) {
        echo "        *** has not checked in for over 15 minutes — its agent or service is not running\n";
    }
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 2. Per employee: enforced or not, and which level decided it.
$resolver = app(PolicyResolver::class);

foreach (EnforcementState::withoutGlobalScopes()->orderBy('company_id')->get() as $state) {
    $company = (int) $state->company_id;

    line('=');
    printf("  COMPANY %d — tenant enforcement is %s\n", $company, $state->mode ?: 'OFF');
    line('=');

    if (($state->mode ?? '') !== 'ENFORCE') {
        echo "    Nothing is refused on any PC while the tenant is not ENFORCE.\n";
        echo "    AUDIT means learning: launches are logged only.\n\n";
    }

    $employees = Employee::withoutGlobalScopes()
        ->where('company_id', $company)
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->get();

    printf("    %-6s %-26s %-10s %-12s %s\n", 'ID', 'EMPLOYEE', 'RESOLVED', 'SET ON', 'WHY');
    line();

    foreach ($employees as $e) {
        $mode = $resolver->effectiveEnforcementMode($e);

        // Which level actually decided it — the answer to "I set it on the
        // employee, why is he still blocked".
        $where = 'default';
        $why = 'nothing set anywhere; default is ENFORCED';

        if ($e->enforcement_exempt_from || $e->enforcement_exempt_until) {
            $where = 'dated';
            $why = sprintf('exempt %s to %s%s',
                $e->enforcement_exempt_from ?: 'now',
                $e->enforcement_exempt_until ?: 'no end',
                $mode === EnforcementMode::EXEMPT ? '' : ' (NOT in force today)');
        } elseif (EnforcementMode::clean($e->enforcement_mode)) {
            $where = 'employee';
            $why = 'set on this person';
        } else {
            foreach ([['team', $e->team_id, \App\Models\Team::class],
                      ['department', $e->department_id, \App\Models\Department::class],
                      ['branch', $e->branch_id, \App\Models\Branch::class]] as [$label, $id, $class]) {
                if ($id && ($row = $class::withoutGlobalScopes()->find($id))
                    && EnforcementMode::clean($row->enforcement_mode)) {
                    $where = $label;
                    $why = sprintf('inherited from %s "%s"', $label, $row->name ?? $id);
                    break;
                }
            }
            if ($where === 'default') {
                $c = \App\Models\Company::find($company);
                if ($c && EnforcementMode::clean($c->enforcement_mode)) {
                    $where = 'company';
                    $why = 'set on the company';
                }
            }
        }

        printf("    %-6d %-26s %-10s %-12s %s\n",
            $e->id,
            mb_strimwidth($e->fullName(), 0, 26, '…'),
            $mode,
            $where,
            $why);
    }

    echo PHP_EOL;
    echo "    An employee shown EXEMPT blocks NOTHING when they sign in.\n";
    echo "    An employee shown ENFORCED blocks the company's rules — but only\n";
    echo "    while the tenant above is ENFORCE and they are signed in.\n\n";
}
