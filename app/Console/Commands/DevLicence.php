<?php

namespace App\Console\Commands;

use App\Services\DevLicenceKey;
use Illuminate\Console\Command;

/**
 * Turn licence enforcement off / on for local development and testing.
 *
 *   php artisan smartept:licence off       enforcement OFF on this machine
 *   php artisan smartept:licence on        enforcement ON  (deletes the file)
 *   php artisan smartept:licence status    which state am I in?
 *
 * The file this writes is bound to this machine and is excluded from the client-side
 * build, so it cannot reach a customer. See App\Services\DevLicenceKey.
 */
class DevLicence extends Command
{
    protected $signature = 'smartept:licence {state=status : off | on | status}';

    protected $description = 'Switch licence enforcement off/on locally for testing (developer machines only)';

    public function handle(DevLicenceKey $key): int
    {
        $state = strtolower((string) $this->argument('state'));

        return match ($state) {
            'off' => $this->turnOff($key),
            'on' => $this->turnOn($key),
            'status' => $this->status($key),
            default => $this->badArgument(),
        };
    }

    private function turnOff(DevLicenceKey $key): int
    {
        $path = $key->enable();

        $this->newLine();
        $this->warn('  Licence enforcement is now OFF on this machine.');
        $this->line('  File: ' . $path);
        $this->newLine();
        $this->line('  This file works ONLY on this machine and is never included in a');
        $this->line('  client-side build. Run "php artisan smartept:licence on" to restore');
        $this->line('  enforcement, and always confirm status before handing a build over.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function turnOn(DevLicenceKey $key): int
    {
        $removed = $key->disable();

        $this->newLine();
        $this->info('  Licence enforcement is ON.');
        $this->line($removed ? '  Removed: ' . $key->path() : '  (no toggle file was present)');
        $this->newLine();

        return self::SUCCESS;
    }

    private function status(DevLicenceKey $key): int
    {
        $off = $key->active();

        $this->newLine();
        if ($off) {
            $this->warn('  Licence enforcement: OFF  (developer toggle present)');
            $this->line('  File: ' . $key->path());
        } else {
            $this->info('  Licence enforcement: ON');
            if (is_file($key->path())) {
                $this->line('  A ' . basename($key->path()) . ' exists but is NOT valid for this machine —');
                $this->line('  it is being ignored. Run "smartept:licence off" to write a correct one.');
            }
        }
        $this->newLine();

        return self::SUCCESS;
    }

    private function badArgument(): int
    {
        $this->error('Use: off | on | status');

        return self::INVALID;
    }
}
