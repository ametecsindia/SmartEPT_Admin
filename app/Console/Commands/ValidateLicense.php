<?php

namespace App\Console\Commands;

use App\Models\InstallationLicense;
use App\Services\LicenseClient;
use Illuminate\Console\Command;

/**
 * R2-1: daily phone-home. Scheduled at 01:00; safe to run any time.
 */
class ValidateLicense extends Command
{
    protected $signature = 'smartept:validate-license';

    protected $description = 'Validate this installation\'s licence key against SmartEPT Central and refresh the cached entitlement bundle.';

    public function handle(LicenseClient $client): int
    {
        $license = InstallationLicense::current();

        if (! $license->configured()) {
            $this->warn('No licence key configured — set one on the Licence screen (running unlicensed).');

            return self::SUCCESS;
        }

        $license = $client->validate($license);

        $this->info('Status: ' . $license->status . ($license->operational() ? ' (operational)' : ' (BLOCKED)'));

        if ($license->last_error) {
            $this->warn('Last error: ' . $license->last_error);
        }

        return $license->operational() ? self::SUCCESS : self::FAILURE;
    }
}
