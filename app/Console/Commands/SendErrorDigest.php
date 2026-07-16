<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * R2-2: daily error digest (SmartPRS ErrorDigest pattern, simplified).
 * Scans laravel.log for ERROR/CRITICAL lines from the last 24 hours and mails
 * a capped summary to super/company admins — silent servers hide problems.
 */
class SendErrorDigest extends Command
{
    protected $signature = 'smartept:error-digest {--hours=24} {--max-lines=40}';

    protected $description = 'Email admins a digest of application errors logged in the last 24 hours.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $max = (int) $this->option('max-lines');
        $since = now()->subHours($hours);

        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            $this->info('No log file — nothing to digest.');

            return self::SUCCESS;
        }

        $matches = [];
        $total = 0;
        $fh = fopen($path, 'r');

        while (($line = fgets($fh)) !== false) {
            // "[2026-07-16 01:23:45] production.ERROR: ..."
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $m)) {
                continue;
            }
            if ($m[1] < $since->format('Y-m-d H:i:s')) {
                continue;
            }
            $total++;
            if (count($matches) < $max) {
                $matches[] = trim(mb_substr($line, 0, 300));
            }
        }
        fclose($fh);

        if ($total === 0) {
            $this->info("Clean — no errors in the last {$hours}h.");

            return self::SUCCESS;
        }

        $body = "SmartEPT logged {$total} error(s) in the last {$hours} hours on this server.\n\n"
            . implode("\n", $matches)
            . ($total > count($matches) ? "\n… and " . ($total - count($matches)) . ' more (see storage/logs/laravel.log).' : '')
            . "\n\nRepeated errors usually mean a queue/mail/storage misconfiguration or a bug worth reporting to Ametecs support (WhatsApp 90000 98877)."
            . "\n\n— SmartEPT";

        $admins = User::query()
            ->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['SUPER_ADMIN', 'COMPANY_ADMIN']))
            ->get(['id', 'email', 'company_id']);

        foreach ($admins as $admin) {
            MailService::send($admin->email, "SmartEPT daily error digest — {$total} error(s)", $body, 'error_digest', $admin->company_id);
        }

        $this->warn("Digest sent: {$total} error(s), " . $admins->count() . ' recipient(s).');

        return self::SUCCESS;
    }
}
