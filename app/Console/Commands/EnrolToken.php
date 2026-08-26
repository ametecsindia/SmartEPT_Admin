<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EnrollmentToken;
use Illuminate\Console\Command;

/**
 * Mint a one-time enrolment token for the Windows enforcement service.
 *
 * The console screen for this does not exist yet. This command does the same
 * job from the terminal, which is what an installer engineer actually has in
 * front of them anyway.
 *
 *   php artisan smartept:enrol-token
 *   php artisan smartept:enrol-token --uses=20 --hours=48 --label="Floor rollout"
 *
 * The secret is printed ONCE and never stored — only its sha256 is kept. Lose
 * it and mint another; there is no way to read it back.
 */
class EnrolToken extends Command
{
    protected $signature = 'smartept:enrol-token
        {--company= : Company id (defaults to the only company, or asks)}
        {--hours=24 : How long the token stays usable}
        {--uses=1 : How many machines it may enrol}
        {--label= : What this token is for, shown in the console}
        {--list : Show existing tokens instead of minting one}';

    protected $description = 'Mint a one-time enrolment token for the SmartEPT enforcement service';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listTokens();
        }

        $companyId = $this->resolveCompany();
        if ($companyId === null) {
            return self::FAILURE;
        }

        [$token, $secret] = EnrollmentToken::mint(
            $companyId,
            null, // minted from the console, not by a signed-in user
            (string) ($this->option('label') ?: 'Minted from the command line'),
            (int) $this->option('hours'),
            (int) $this->option('uses'),
        );

        $this->newLine();
        $this->info('Enrolment token created.');
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>' . $secret . '</>');
        $this->newLine();
        $this->line('  valid until : ' . $token->expires_at->toDayDateTimeString());
        $this->line('  machines    : ' . $token->max_uses);
        $this->newLine();
        $this->warn('Copy it now. It cannot be shown again — only its hash is stored.');
        $this->newLine();
        $this->line('On the Windows PC, run as Administrator:');
        $this->newLine();
        $this->line('  smarteptsvc.exe -install -server ' . rtrim(config('app.url'), '/') . ' -token ' . $secret);
        $this->newLine();
        $this->comment('Try -console first on a test machine — same work, runs in the foreground, easier to read.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function listTokens(): int
    {
        $rows = EnrollmentToken::withoutGlobalScopes()
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (EnrollmentToken $t) => [
                $t->id,
                $t->label,
                $t->expires_at?->toDateTimeString(),
                $t->uses . ' / ' . $t->max_uses,
                $t->usable() ? 'usable' : ($t->unusableReason() ?? 'spent'),
            ]);

        if ($rows->isEmpty()) {
            $this->info('No enrolment tokens yet.');

            return self::SUCCESS;
        }

        $this->table(['id', 'label', 'expires', 'used', 'state'], $rows);
        $this->comment('Secrets are never stored, so they cannot be listed. Mint a new one if you lost it.');

        return self::SUCCESS;
    }

    private function resolveCompany(): ?int
    {
        if ($id = $this->option('company')) {
            if (! Company::withoutGlobalScopes()->whereKey($id)->exists()) {
                $this->error("No company with id {$id}.");

                return null;
            }

            return (int) $id;
        }

        $companies = Company::withoutGlobalScopes()->orderBy('id')->get(['id', 'name']);

        if ($companies->isEmpty()) {
            $this->error('No companies exist. Seed or create one first.');

            return null;
        }
        if ($companies->count() === 1) {
            return (int) $companies->first()->id;
        }

        // More than one tenant: never guess which estate a credential is for.
        $this->table(['id', 'name'], $companies->map(fn ($c) => [$c->id, $c->name]));
        $choice = $this->ask('Which company id?');

        return $companies->contains('id', (int) $choice) ? (int) $choice : null;
    }
}
