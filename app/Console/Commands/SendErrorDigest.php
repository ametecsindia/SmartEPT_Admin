<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * R2-2: daily error digest (SmartPRS ErrorDigest pattern, simplified).
 * Scans laravel.log for ERROR/CRITICAL lines from the last 24 hours and mails
 * a capped summary to super/company admins — silent servers hide problems.
 */
class SendErrorDigest extends Command
{
    protected $signature = 'smartept:error-digest {--hours=24} {--max-lines=40} {--force : send now, ignoring the chosen hour}';

    protected $description = 'Email admins a digest of application errors logged in the last 24 hours.';

    public function handle(): int
    {
        // Scheduled hourly; sends only at the hour chosen in Audit & Ops → Notifications (23-Sep-2026).
        if (! $this->option('force') && (int) now()->format('G') !== (int) MailService::pref('error_digest', 'hour', 7)) {
            return self::SUCCESS;
        }
        if (! MailService::enabled('error_digest')) {
            $this->info('Error digest is switched off in Notifications.');

            return self::SUCCESS;
        }

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

        $vars = ['total' => $total, 'hours' => $hours, 'lines' => implode("\n", $matches)
            . ($total > count($matches) ? "\n… and " . ($total - count($matches)) . ' more (see storage/logs/laravel.log).' : '')];
        $to = MailService::recipients('error_digest');
        foreach ($to as $email => $companyId) {
            MailService::send($email, "SmartEPT daily error digest — {$total} error(s)", $body, 'error_digest', $companyId, $vars);
        }

        $this->warn("Digest sent: {$total} error(s), " . count($to) . ' recipient(s).');

        return self::SUCCESS;
    }
}
