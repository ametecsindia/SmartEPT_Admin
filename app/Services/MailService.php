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

    /**
     * NOTIFICATIONS (Ejaz, 23-Sep-2026): "no email goes out automatically without my
     * approval". Every AUTOMATIC email type is OFF until someone switches it on in
     * Audit & Ops → Notifications.
     *
     *  - COMPANY alerts: each client company's own admin decides, for THEIR company only,
     *    which alerts go out and to whom (their admins/HR/managers + any custom emails).
     *    Stored per company: settings key "notify_prefs:company:{id}".
     *  - SERVER alerts (the technical error report) are the Super Admin's: key "notify_prefs".
     *
     * Codes a person asks for themselves (password-reset code, data-clear code, SMTP
     * test) are NOT listed — switching them off would break "Forgot password".
     */
    public const COMPANY_NOTIFY = [
        'device_offline'   => ['on' => false, 'roles' => ['COMPANY_ADMIN'], 'extra' => '', 'minutes' => 30, 'subject' => '', 'body' => ''],
        'violation_spike'  => ['on' => false, 'roles' => ['COMPANY_ADMIN'], 'extra' => '', 'threshold' => 20, 'subject' => '', 'body' => ''],
        'late_login'       => ['on' => false, 'roles' => ['COMPANY_ADMIN', 'HR_ADMIN'], 'extra' => '', 'minutes' => 15, 'hour' => 11, 'subject' => '', 'body' => ''],
        'gate_long_break'  => ['on' => false, 'roles' => ['COMPANY_ADMIN', 'HR_ADMIN'], 'extra' => '', 'hours' => 3, 'subject' => '', 'body' => ''],
        'USER_CREDENTIALS' => ['on' => true, 'extra' => '', 'subject' => '', 'body' => ''],
    ];

    public const SERVER_NOTIFY = [
        'error_digest' => ['on' => false, 'roles' => ['SUPER_ADMIN'], 'extra' => '', 'hour' => 7, 'subject' => '', 'body' => ''],
    ];

    /**
     * Built-in wording with {placeholders} — shown in the Notifications editor. A saved custom
     * subject/body (prefs 'subject'/'body') replaces it; blank = keep the built-in text.
     */
    public const TEMPLATES = [
        'device_offline' => ['vars' => ['count', 'minutes', 'devices'],
            'subject' => 'SmartEPT alert: {count} device(s) went offline',
            'body' => "The following monitored PCs stopped reporting more than {minutes} minutes ago:\n\n{devices}\n\nIf the PC is on and in use, the SmartEPT agent may have been stopped — ask IT to check it. Data recorded while offline syncs automatically when the agent returns.\n\n— SmartEPT"],
        'violation_spike' => ['vars' => ['total', 'threshold', 'hour'],
            'subject' => 'SmartEPT alert: violation spike — {total} events in the last hour',
            'body' => "SmartEPT recorded {total} compliance violations in the last hour — above your alert limit of {threshold}.\n\nA spike usually means a policy change that is too strict, one team testing limits, or a misconfigured application/website rule. Open the console → Violations to see who and what, and → Usage & Compliance for the day's detail.\n\n— SmartEPT"],
        'late_login' => ['vars' => ['date', 'minutes', 'count', 'list'],
            'subject' => 'SmartEPT: late logins today — {date}',
            'body' => "These employees logged in more than {minutes} minutes late today ({date}):\n\n{list}\n\nOpen the console → Attendance for the full day.\n\n— SmartEPT"],
        'gate_long_break' => ['vars' => ['employee', 'employee_code', 'hours', 'from', 'to'],
            'subject' => 'SmartEPT: long out-of-office break — {employee}',
            'body' => "{employee} ({employee_code}) was out of office for {hours} hours today ({from}–{to}, recorded by the biometric door).\n\nBeyond 3 hours the day normally counts as a half-day — the attendance sheet applies this automatically tonight; use Attendance → regularize if there is a genuine reason (client visit, medical).\n\n— SmartEPT"],
        'USER_CREDENTIALS' => ['vars' => ['name', 'email', 'temp_password'],
            'subject' => 'Your SmartEPT sign-in',
            'body' => "Hello {name},\n\nA SmartEPT account is ready for you.\n\nSign-in email: {email}\nTemporary password: {temp_password}\n\nThis password is temporary — you will be asked to change it after your first sign-in.\n\n— SmartEPT"],
        'error_digest' => ['vars' => ['total', 'hours', 'lines'],
            'subject' => 'SmartEPT daily error digest — {total} error(s)',
            'body' => "SmartEPT logged {total} error(s) in the last {hours} hours on this server.\n\n{lines}\n\nRepeated errors usually mean a queue/mail/storage misconfiguration or a bug worth reporting to Ametecs support (WhatsApp 90000 98877).\n\n— SmartEPT"],
    ];

    public static function render(string $tpl, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }

        return strtr($tpl, $map);
    }

    /** Saved preferences merged over the defaults. $companyId null = the server (Super Admin) list. */
    public static function prefs(?int $companyId = null): array
    {
        $defaults = $companyId ? self::COMPANY_NOTIFY : self::SERVER_NOTIFY;
        $saved = [];
        try {
            $saved = json_decode((string) Setting::get(self::prefsKey($companyId), ''), true) ?: [];
        } catch (\Throwable $e) {
            // settings table missing mid-migration → defaults (everything automatic OFF)
        }
        $out = [];
        foreach ($defaults as $kind => $def) {
            $out[$kind] = array_merge($def, array_intersect_key((array) ($saved[$kind] ?? []), $def));
        }

        return $out;
    }

    public static function prefsKey(?int $companyId): string
    {
        return $companyId ? 'notify_prefs:company:' . $companyId : 'notify_prefs';
    }

    public static function pref(string $kind, string $field, $default = null, ?int $companyId = null)
    {
        return self::prefs($companyId)[$kind][$field] ?? $default;
    }

    /** Types on neither list (OTP codes, tests) are always allowed. */
    public static function enabled(string $kind, ?int $companyId = null): bool
    {
        if (isset(self::SERVER_NOTIFY[$kind])) {
            return ! empty(self::prefs(null)[$kind]['on']);
        }
        if (isset(self::COMPANY_NOTIFY[$kind])) {
            return ! empty(($companyId ? self::prefs($companyId) : self::COMPANY_NOTIFY)[$kind]['on']);
        }

        return true;
    }

    /**
     * Who gets an automatic email. Company alerts: active users of THAT company holding a
     * ticked role, plus the custom addresses. Server alerts: ticked roles across the
     * install, plus custom addresses. Returns [email => companyId|null].
     */
    public static function recipients(string $kind, ?int $companyId = null): array
    {
        $server = isset(self::SERVER_NOTIFY[$kind]);
        $p = self::prefs($server ? null : $companyId)[$kind] ?? null;
        if (! $p || empty($p['on']) || (! $server && ! $companyId)) {
            return [];
        }
        $allowed = $server ? ['SUPER_ADMIN', 'COMPANY_ADMIN'] : ['COMPANY_ADMIN', 'HR_ADMIN', 'MANAGER'];
        $roles = array_values(array_intersect((array) ($p['roles'] ?? []), $allowed));
        $out = [];
        if ($roles) {
            User::query()->where('status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->when(! $server, fn ($q) => $q->where('company_id', $companyId))
                ->get(['email', 'company_id'])
                ->each(function ($u) use (&$out) {
                    if ($u->email) {
                        $out[strtolower($u->email)] = $u->company_id;
                    }
                });
        }
        foreach (preg_split('/[\s,;]+/', (string) ($p['extra'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($e)] = $out[strtolower($e)] ?? ($server ? null : $companyId);
            }
        }

        return $out;
    }

    /** Send a raw-text mail and record the attempt. Returns the resulting status. */
    /** @param array $vars values for the {placeholders} a custom subject/body (Notifications editor) may use */
    public static function send(string $to, string $subject, string $body, ?string $kind = null, ?int $companyId = null, array $vars = []): string
    {
        // Custom wording saved in Audit & Ops → Notifications (blank = the built-in text).
        if ($kind && (isset(self::COMPANY_NOTIFY[$kind]) || isset(self::SERVER_NOTIFY[$kind]))) {
            $p = self::prefs(isset(self::SERVER_NOTIFY[$kind]) ? null : $companyId)[$kind] ?? [];
            if (trim((string) ($p['subject'] ?? '')) !== '') {
                $subject = self::render(trim($p['subject']), $vars);
            }
            if (trim((string) ($p['body'] ?? '')) !== '') {
                $body = self::render($p['body'], $vars);
            }
        }

        $status = 'sent';
        $error  = null;

        if (trim($to) === '') {
            // No destination (e.g. employee without an email on file) — record, don't error.
            $status = 'skipped';
        } elseif ($kind && ! self::enabled($kind, $companyId)) {
            // Notifications screen (23-Sep-2026): the owner switched this email type off.
            $status = 'skipped';
            $error  = 'Turned off in Audit & Ops → Notifications';
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
        $vars = ['name' => $user->name, 'email' => $user->email, 'temp_password' => $tempPassword];
        $t = self::TEMPLATES['USER_CREDENTIALS'];
        $status = self::send($user->email, self::render($t['subject'], $vars), self::render($t['body'], $vars),
            'USER_CREDENTIALS', $user->company_id, $vars);

        // Optional copies chosen in Notifications ("Also send to") — they receive the same message.
        if ($user->company_id) {
            foreach (array_keys(self::recipients('USER_CREDENTIALS', $user->company_id)) as $copy) {
                if ($copy !== strtolower((string) $user->email)) {
                    self::send($copy, self::render($t['subject'], $vars), self::render($t['body'], $vars),
                        'USER_CREDENTIALS', $user->company_id, $vars);
                }
            }
        }

        return $status;
    }
}
