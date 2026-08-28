<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EnforcementAuditEvent;
use App\Models\EnforcementMachine;
use App\Models\EnforcementState;
use App\Models\PolicyRule;
use App\Services\PolicyResolver;
use App\Support\EnforcementMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * "The console says Enforcing and nothing is blocked. Why?"
 *
 * Blocking is a chain of six links and it fails silently at any one of them — every link looks
 * fine from the link above. Checking them one at a time over WhatsApp took six rounds on the
 * Live Dashboard bug before `smartept:why-offline` ended it in one look. This is that command
 * for blocking. Read-only: it changes nothing.
 *
 * The chain, in the order it breaks in practice:
 *
 *   1  the tenant switch      ENFORCE, or nothing blocks anywhere
 *   2  armed rules            action Close/Block AND status Blocked/Violation - BOTH
 *   3  a server that can      /api/agent/enforcer-handoff must exist in THIS build, or the
 *      hand out a token       service can never enrol and no PC will ever report
 *   4  an enrolled service    a PC with the agent but no service reports and blocks nothing
 *   5  a live service         enrolled is not the same as checking in
 *   6  an enforced person     blocking follows the person, through seven levels
 *
 * Run it on the SERVER the agents talk to. It names the first broken link and stops guessing.
 */
class WhyNotBlocking extends Command
{
    protected $signature = 'smartept:why-not-blocking {--company= : Company id (default: every company)}';

    protected $description = 'Explain why app/website blocking is or is not happening';

    private int $problems = 0;

    public function handle(): int
    {
        $companies = $this->option('company')
            ? [(int) $this->option('company')]
            : \App\Models\Company::withoutGlobalScopes()->pluck('id')->all();

        foreach ($companies as $companyId) {
            $this->line('');
            $this->line('  ══ company ' . $companyId . ' ' . str_repeat('═', 50));
            $this->tenantSwitch($companyId);
            $this->rules($companyId);
            $this->handoffRoute();
            $this->machines($companyId);
            $this->people($companyId);
            $this->evidence($companyId);
        }

        $this->line('');
        if ($this->problems === 0) {
            $this->info('  Nothing above is broken. If a program still opens that should not:');
            $this->line('    - give it 30 seconds, then try again;');
            $this->line('    - check the rule names the program Windows actually launches');
            $this->line('      (the Store version and the installed version can differ);');
            $this->line('    - on that PC run the service with -status and read the last line.');
        } else {
            $this->error('  ' . $this->problems . ' problem(s) above. Fix them TOP DOWN — each one');
            $this->error('  makes everything below it untestable.');
        }
        $this->line('');

        return self::SUCCESS;
    }

    // 1 ---------------------------------------------------------------------
    private function tenantSwitch(int $companyId): void
    {
        $state = EnforcementState::forCompany($companyId);
        $mode = $state->effectiveMode();

        if ($mode === EnforcementState::ENFORCE) {
            $this->ok('1. Tenant switch', 'ENFORCE'
                . ($state->cleared_report_id ? '  (' . $state->cleared_report_id . ')' : ''));

            return;
        }

        $this->bad('1. Tenant switch', $mode . ' — nothing is blocked for anybody, whatever the rules say');
        if ($state->mode === EnforcementState::AUDIT) {
            $this->hint('This site was left mid-learning by an older build. Learning has been removed.');
        }
        $this->hint('Fix:  php artisan smartept:enforcement on');
    }

    // 2 ---------------------------------------------------------------------
    private function rules(int $companyId): void
    {
        $all = PolicyRule::withoutGlobalScopes()->where('company_id', $companyId)->get();
        $armed = $all->filter(fn (PolicyRule $r) => $r->isEnforcing());

        if ($all->isEmpty()) {
            $this->bad('2. Rules', 'none at all — there is nothing to block');

            return;
        }

        if ($armed->isNotEmpty()) {
            $this->ok('2. Rules', $armed->count() . ' of ' . $all->count() . ' armed and reaching PCs');

            return;
        }

        $this->bad('2. Rules', 'all ' . $all->count() . ' are saved and reach NO PC');
        $this->hint('A rule blocks only when BOTH are true: "What happens" is Close/block,');
        $this->hint('AND the row status is Blocked or Violation. Block + Tracked looks correct');
        $this->hint('on the Rules screen and is dropped silently. The first few:');

        foreach ($all->take(6) as $r) {
            $why = [];
            if (! in_array(strtoupper((string) $r->action), PolicyRule::HARD_ACTIONS, true)) {
                $why[] = 'action=' . ($r->action ?: 'unset');
            }
            if (! in_array(strtoupper((string) $r->status), ['BLOCKED', 'VIOLATION'], true)) {
                $why[] = 'status=' . ($r->status ?: 'unset');
            }
            $this->line(sprintf('        %-28s %s', mb_substr((string) $r->item, 0, 28), implode('  ', $why)));
        }
    }

    // 3 ---------------------------------------------------------------------
    private function handoffRoute(): void
    {
        // The one that is invisible from the console and explains a permanent zero. If this
        // build predates the handoff endpoint, the agent cannot obtain a token, the service on
        // every PC idles for ever with no identity, and the Enforcement card reads 0 whatever
        // anybody installs. A stale server ZIP at a client site looks exactly like this.
        $found = collect(Route::getRoutes())->contains(
            fn ($r) => str_contains($r->uri(), 'agent/enforcer-handoff')
        );

        if ($found) {
            $this->ok('3. Handoff endpoint', 'api/agent/enforcer-handoff present');

            return;
        }

        $this->bad('3. Handoff endpoint', 'MISSING from this build');
        $this->hint('No PC can ever enrol: the agent has nowhere to ask for a token.');
        $this->hint('This server is running an older release. Update it from 1-SERVER.');
    }

    // 4 + 5 -----------------------------------------------------------------
    private function machines(int $companyId): void
    {
        $machines = EnforcementMachine::withoutGlobalScopes()->where('company_id', $companyId)->get();
        $active = $machines->whereNull('revoked_at');

        if ($machines->isEmpty()) {
            $this->bad('4. Enrolled PCs', 'none — no enforcement service has ever enrolled');
            $this->hint('The employee agent is a DIFFERENT program: an employee signing in does');
            $this->hint('not put a PC here. Each PC needs the SmartEPT Agent setup from 2-AGENT,');
            $this->hint('which installs the service, and then somebody must sign in on it —');
            $this->hint('the sign-in is what hands the service its credential.');
            $this->hint('Check on the PC:  "C:\\Program Files\\SmartEPT Agent\\resources\\service\\SmartEPTAgentService.exe" -status');

            return;
        }

        $this->ok('4. Enrolled PCs', $active->count() . ' active' .
            ($machines->count() > $active->count() ? ', ' . ($machines->count() - $active->count()) . ' revoked' : ''));

        $fresh = now()->subMinutes(10);
        $live = $active->filter(fn ($m) => $m->last_seen_at && $m->last_seen_at->gte($fresh));

        if ($live->isEmpty()) {
            $this->bad('5. Checking in', 'none in the last 10 minutes');
        } else {
            $this->ok('5. Checking in', $live->count() . ' of ' . $active->count());
        }

        foreach ($active as $m) {
            $this->line(sprintf('        %-22s policy v%-4s %-10s last seen %s',
                mb_substr((string) ($m->hostname ?: $m->device_uuid ?: $m->id), 0, 22),
                (int) $m->applied_policy_version,
                (string) ($m->enforcement_health ?: '—'),
                $m->last_seen_at ? $m->last_seen_at->diffForHumans() : 'never'));
        }
    }

    // 6 ---------------------------------------------------------------------
    private function people(int $companyId): void
    {
        $resolver = app(PolicyResolver::class);
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('deleted_at')->get();

        $exempt = $employees->filter(
            fn (Employee $e) => $resolver->effectiveEnforcementMode($e) !== EnforcementMode::ENFORCED
        );

        if ($exempt->isEmpty()) {
            $this->ok('6. People', $employees->count() . ' employee(s), all inside enforcement');

            return;
        }

        if ($exempt->count() === $employees->count()) {
            $this->bad('6. People', 'EVERY employee is exempt — nobody is inside enforcement');
        } else {
            $this->ok('6. People', ($employees->count() - $exempt->count()) . ' of ' . $employees->count()
                . ' inside enforcement');
        }

        $this->hint('Exempt: ' . $exempt->take(10)->map(fn ($e) => $e->fullName())->implode(', '));
        $this->hint('An exemption is inherited from shift, team, department or branch as often as');
        $this->hint('it is set on the person. The console\'s "Decided at" column names which.');
    }

    private function evidence(int $companyId): void
    {
        $n = EnforcementAuditEvent::withoutGlobalScopes()->where('company_id', $companyId)->count();

        $this->line($n > 0
            ? '     ·  ' . $n . ' block(s) recorded — this tenant HAS stopped something.'
            : '     ·  nothing stopped yet. On a quiet estate that is normal, not a fault.');
    }

    // ---- output -----------------------------------------------------------

    private function ok(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>OK  </> %-22s %s', $label, $detail));
    }

    private function bad(string $label, string $detail): void
    {
        $this->problems++;
        $this->line(sprintf('  <fg=red;options=bold>FAIL</> %-22s <fg=red>%s</>', $label, $detail));
    }

    private function hint(string $text): void
    {
        $this->line('        <fg=gray>' . $text . '</>');
    }
}
