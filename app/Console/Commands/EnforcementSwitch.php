<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EnforcementState;
use Illuminate\Console\Command;

/**
 * Turn enforcement on, off, or back to learning — for an installation that ALREADY EXISTS.
 *
 * 26-Aug-2026 (Ejaz): `SMARTEPT_ENFORCEMENT_DEFAULT=ENFORCE` was set on a client PC where
 * SmartEPT was already installed, and nothing changed. Correct, and worth stating plainly:
 * that setting is read by `EnforcementState::forCompany()`, which uses firstOrCreate. Once the
 * row exists the attributes are ignored, so the env var arms only a BRAND-NEW install. Keeping
 * it that way is deliberate — an upgrade must never silently start blocking things on an estate
 * nobody has surveyed (decision 4) — but it left no way to arm a site that is already live,
 * because promotion through the console still requires a clean audit report.
 *
 * This is that way. It is the installer's / our switch, not a bypass offered to the client:
 * the console gate is untouched, so nobody inside the tenant can skip the audit by clicking.
 *
 * Why a command rather than an UPDATE statement: every mode change must bump `policy_version`.
 * Endpoints re-fetch their policy when that number moves, so a hand-written UPDATE would change
 * the console and leave every PC enforcing whatever it had before — the exact "says applied,
 * blocks nothing" failure this product exists to stop.
 */
class EnforcementSwitch extends Command
{
    protected $signature = 'smartept:enforcement
        {state? : on | off | audit — omit to just show the current state}
        {--company= : Company id (default: every company on this installation)}
        {--reason= : Recorded against an "off"}';

    protected $description = 'Show or set enforcement mode (on / audit / off) for an existing installation';

    public function handle(): int
    {
        $companies = $this->option('company')
            ? [(int) $this->option('company')]
            : Company::withoutGlobalScopes()->pluck('id')->all();

        if (! $companies) {
            $this->error('No companies on this installation.');

            return self::FAILURE;
        }

        $state = strtolower(trim((string) $this->argument('state')));

        if ($state === '') {
            return $this->show($companies);
        }

        $mode = match ($state) {
            'on', 'enforce'  => EnforcementState::ENFORCE,
            'audit', 'learn' => EnforcementState::AUDIT,
            'off', 'disable' => EnforcementState::OFF,
            default          => null,
        };

        $learning = EnforcementState::learningEnabled();

        if ($mode === null) {
            $this->error("Unknown state '{$state}'. Use: on | " . ($learning ? 'audit | ' : '') . 'off');

            return self::FAILURE;
        }

        // 27-Aug-2026 (Ejaz): "there should be no learning mechanism in the client." This
        // command is the last thing that could still write AUDIT, so it has to refuse too —
        // an installation where the console offers two states and the command line offers
        // three is one support call away from a site nobody can explain.
        if ($mode === EnforcementState::AUDIT && ! $learning) {
            $this->error('There is no learning period on this installation.');
            $this->line('  The application catalogue ships with SmartEPT, so there is nothing');
            $this->line('  for this site to discover. Use:  php artisan smartept:enforcement on|off');
            $this->newLine();
            $this->comment('  (To survey a genuinely unknown estate, set SMARTEPT_ENFORCEMENT_LEARNING=true');
            $this->comment('   in .env first. That is for our own lab, not for a client package.)');

            return self::FAILURE;
        }

        foreach ($companies as $companyId) {
            $row = EnforcementState::forCompany($companyId);
            $was = $row->mode;

            $changes = [
                'mode'           => $mode,
                // Endpoints re-fetch on a version change. Without this the console would say
                // ENFORCE while every PC carried on with its previous policy.
                'policy_version' => (int) $row->policy_version + 1,
            ];

            if ($mode === EnforcementState::ENFORCE) {
                // Never a fabricated report id: this tenant was switched on by an operator, not
                // promoted by a clean audit, and the console has to be able to say which.
                $changes += [
                    'cleared_report_id'  => 'PRECONFIGURED',
                    'cleared_at'         => now(),
                    'cleared_by_user_id' => null,
                    'disabled_at'        => null,
                    'disabled_reason'    => null,
                ];
            } elseif ($mode === EnforcementState::AUDIT) {
                $changes += ['audit_started_at' => now(), 'disabled_at' => null, 'disabled_reason' => null];
            } else {
                $changes += [
                    'disabled_at'     => now(),
                    'disabled_reason' => $this->option('reason') ?: 'Disabled from the command line',
                ];
            }

            $row->fill($changes)->save();

            $this->line(sprintf('  company %d: %s -> <options=bold>%s</> (policy version %d)',
                $companyId, $was, $mode, $row->policy_version));
        }

        $this->newLine();
        $this->info($mode === EnforcementState::ENFORCE
            ? 'Enforcement is ON. Endpoints apply it on their next sync (~30s).'
            : 'Done. Endpoints pick this up on their next sync (~30s).');

        if ($mode === EnforcementState::ENFORCE) {
            $this->newLine();
            $this->comment('  If a program that should work stops opening, turn it off again:');
            $this->comment('      php artisan smartept:enforcement off --reason="<what broke>"');
        }

        return self::SUCCESS;
    }

    private function show(array $companies): int
    {
        $learning = EnforcementState::learningEnabled();
        $rows = [];

        foreach ($companies as $companyId) {
            $s = EnforcementState::forCompany($companyId);

            // Both columns, because on a site upgraded mid-learning they differ, and the
            // difference is the whole explanation. "Stored AUDIT / acting OFF" answers
            // "the database says learning but the console says off" in one line.
            $rows[] = [
                $companyId,
                $s->effectiveMode(),
                $s->mode === $s->effectiveMode() ? '—' : $s->mode . ' (learning removed)',
                $learning && $s->mode === EnforcementState::AUDIT
                    ? ($s->auditRemainingLabel() ?: 'minimum learning time reached')
                    : '—',
                $s->cleared_report_id ?: '—',
                $s->policy_version,
            ];
        }

        $this->table(['Company', 'Acting as', 'Stored', 'Learning', 'Cleared by', 'Policy v'], $rows);
        $this->comment('  Set it with:  php artisan smartept:enforcement on|' . ($learning ? 'audit|' : '') . 'off');

        if (! $learning) {
            $this->comment('  Learning is not part of this installation (SMARTEPT_ENFORCEMENT_LEARNING=false).');
        }

        return self::SUCCESS;
    }
}
