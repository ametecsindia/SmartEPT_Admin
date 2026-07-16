<?php

namespace App\Services;

use App\Models\MailLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Thin outbound-mail wrapper (SmartPRS pattern, simplified).
 *
 * Two guarantees the API layer relies on:
 *  1. Never throws — a broken SMTP relay must not fail user creation or a
 *     password reset. Failures are swallowed and recorded instead.
 *  2. Always writes a mail_logs row (sent | failed | skipped) so the attempt
 *     is auditable even on LAN installs where MAIL_MAILER=log.
 */
class MailService
{
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
                Mail::raw($body, fn ($message) => $message->to($to)->subject($subject));
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
