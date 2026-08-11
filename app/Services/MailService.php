<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MailLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Thin outbound-mail wrapper (SmartPRS pattern, simplified).
 *
 * Two guarantees the API layer relies on:
 *  1. Never throws — a broken SMTP relay must not fail user creation or a
 *     password reset. Failures are swallowed and recorded instead.
 *  2. Always writes a mail_logs row (sent | failed | skipped) so the attempt
 *     is auditable even on LAN installs where MAIL_MAILER=log.
 *
 * CLIENT-WISE SMTP (Ejaz, 11-Aug-2026): every send resolves its mailer as
 *   1. the company's OWN SMTP (companies.mail_settings, set by the company
 *      admin in Ops → Email/SMTP) when a host is configured,
 *   2. else the GLOBAL SMTP saved in the console Settings (settings table,
 *      super admin — the Ametecs/company default),
 *   3. else the .env mailer (unchanged behaviour).
 * Alerts, credentials and password-reset OTPs all pass through here, so they
 * automatically go out via the right relay with the right From identity.
 */
class MailService
{
    /**
     * Resolve the mailer + from-identity for a company. Returns
     * [mailerNameOrNull, fromAddressOrNull, fromNameOrNull]; null mailer = .env default.
     */
    protected static function resolveMailer(?int $companyId): array
    {
        // 1) Company override.
        if ($companyId && ($company = Company::find($companyId))) {
            $ms = $company->mail_settings;
            if (is_array($ms) && ! empty($ms['host'])) {
                $password = '';
                if (! empty($ms['password'])) {
                    try {
                        $password = Crypt::decryptString($ms['password']);
                    } catch (\Throwable $e) {
                        $password = (string) $ms['password']; // legacy/plain value
                    }
                }
                config(['mail.mailers.company_smtp' => [
                    'transport'  => 'smtp',
                    'host'       => $ms['host'],
                    'port'       => (int) ($ms['port'] ?? 587),
                    'username'   => $ms['username'] ?? null,
                    'password'   => $password !== '' ? $password : null,
                    'encryption' => ($ms['encryption'] ?? 'tls') === 'none' ? null : ($ms['encryption'] ?? 'tls'),
                    'timeout'    => 15,
                ]]);

                return ['company_smtp', $ms['from_address'] ?? null, $ms['from_name'] ?? null];
            }
        }

        // 2) Global SMTP saved in the console Settings (admin-configurable standard).
        if ($host = Setting::get('mail_host')) {
            $password = '';
            if ($enc = Setting::get('mail_password')) {
                try {
                    $password = Crypt::decryptString($enc);
                } catch (\Throwable $e) {
                    $password = (string) $enc;
                }
            }
            config(['mail.mailers.settings_smtp' => [
                'transport'  => 'smtp',
                'host'       => $host,
                'port'       => (int) (Setting::get('mail_port') ?: 587),
                'username'   => Setting::get('mail_username'),
                'password'   => $password !== '' ? $password : null,
                'encryption' => Setting::get('mail_encryption', 'tls') === 'none' ? null : Setting::get('mail_encryption', 'tls'),
                'timeout'    => 15,
            ]]);

            return ['settings_smtp', Setting::get('mail_from_address'), Setting::get('mail_from_name')];
        }

        // 3) .env default.
        return [null, null, null];
    }

    /** Send a raw-text mail and record the attempt. Returns the resulting status. */
    public static function send(string $to, string $subject, string $body, ?string $kind = null, ?int $companyId = null): string
    {
        $status = 'sent';
        $error  = null;

        if (trim($to) === '') {
            // No destination (e.g. employee without an email on file) — record, don't error.
            $status = 'skipped';
        } else {
            try {
                [$mailer, $fromAddress, $fromName] = self::resolveMailer($companyId);
                $pending = $mailer ? Mail::mailer($mailer) : Mail::mailer();
                $pending->raw($body, function ($message) use ($to, $subject, $fromAddress, $fromName) {
                    $message->to($to)->subject($subject);
                    if ($fromAddress) {
                        $message->from($fromAddress, $fromName ?: config('mail.from.name'));
                    }
                });
            } catch (\Throwable $e) {
                $status = 'failed';
                $error  = mb_substr($e->getMessage(), 0, 1000);
            }
        }

        MailLog::create([
            'company_id' => $companyId,
            'to'         => $to,
            'subject'    => $subject,
            'kind'       => $kind,
            'status'     => $status,
            'error'      => $error,
        ]);

        return $status;
    }

    /**
     * "Send test email" for the SMTP settings screens — same resolution as a
     * real send, but the error (if any) comes back to the caller so the admin
     * sees exactly what the relay said. Logged like every other attempt.
     */
    public static function test(string $to, ?int $companyId = null): array
    {
        try {
            [$mailer, $fromAddress, $fromName] = self::resolveMailer($companyId);
            $pending = $mailer ? Mail::mailer($mailer) : Mail::mailer();
            $pending->raw(
                "This is a SmartEPT test email.\n\nIf you are reading this, the SMTP settings work.\n\n— SmartEPT",
                function ($message) use ($to, $fromAddress, $fromName) {
                    $message->to($to)->subject('SmartEPT — test email');
                    if ($fromAddress) {
                        $message->from($fromAddress, $fromName ?: config('mail.from.name'));
                    }
                }
            );
            MailLog::create(['company_id' => $companyId, 'to' => $to, 'subject' => 'SmartEPT — test email',
                'kind' => 'SMTP_TEST', 'status' => 'sent', 'error' => null]);

            return [true, null];
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 1000);
            MailLog::create(['company_id' => $companyId, 'to' => $to, 'subject' => 'SmartEPT — test email',
                'kind' => 'SMTP_TEST', 'status' => 'failed', 'error' => $error]);

            return [false, $error];
        }
    }

    /**
     * Credentials mail sent on account creation and password reset.
     * Centralised here so the wording stays identical across both flows.
     */
    public static function sendCredentials(User $user, string $tempPassword): string
    {
        $body = "Hello {$user->name},\n\n"
            . "A SmartEPT account is ready for you.\n\n"
            . "Sign-in email: {$user->email}\n"
            . "Temporary password: {$tempPassword}\n\n"
            . "This password is temporary — you will be asked to change it after your first sign-in.\n\n"
            . "— SmartEPT";

        return self::send($user->email, 'Your SmartEPT sign-in', $body, 'USER_CREDENTIALS', $user->company_id);
    }
}
