<?php

namespace App\Console\Commands;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Illuminate\Console\Command;

/**
 * R2-1: daily phone-home. Scheduled at 01:00; safe to run any time.
 * Per-tenant licensing (12-Aug-2026): validates the install-level licence AND
 * every cloud tenant's own row, so all bundles stay fresh even on quiet nights.
 */
class ValidateLicense extends Command
{
    protected $signature = 'smartept:validate-license';

    protected $description = 'Validate every licence key (install-level + per-tenant) against SmartEPT Central and refresh the cached entitlement bundles.';

    public function handle(LicenseClient $client): int
    {
        $rows = InstallationLicense::query()
            ->orderByRaw('company_id IS NOT NULL')->orderBy('id')->get();

        if ($rows->isEmpty() || $rows->every(fn ($l) => ! $l->configured())) {
            $this->warn('No licence key configured — set one on the Licence screen (running unlicensed).');

            return self::SUCCESS;
        }

        $allOk = true;

        foreach ($rows as $license) {
            if (! $license->configured()) {
                continue;
            }

            $license = $client->validate($license);

            $label = $license->company_id
                ? 'Tenant #' . $license->company_id . ' (' . ($license->company?->name ?? '?') . ')'
                : 'Install';
            $this->info($label . ': ' . $license->status . ($license->operational() ? ' (operational)' : ' (BLOCKED)'));

            if ($license->last_error) {
                $this->warn('  Last error: ' . $license->last_error);
            }

            $allOk = $allOk && $license->operational();
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
