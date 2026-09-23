<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Setting;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Email / SMTP settings — admin-configurable standard (Ejaz, 11-Aug-2026):
 *  - GLOBAL relay (settings table): the install's default — Super Admin only.
 *  - COMPANY relay (companies.mail_settings): each client company's own SMTP,
 *    edited by its COMPANY_ADMIN (or Super). Alerts + password-reset OTPs for
 *    that company's people go out via it; blank = global, then .env.
 * Passwords stored encrypted, never returned; "Send test email" on both.
 */
class MailConfigController extends Controller
{
    private const FIELDS = [
        'host' => ['nullable', 'string', 'max:190'],
        'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        'username' => ['nullable', 'string', 'max:190'],
        'password' => ['nullable', 'string', 'max:190'],
        'encryption' => ['nullable', 'in:tls,ssl,none'],
        'from_address' => ['nullable', 'email', 'max:190'],
        'from_name' => ['nullable', 'string', 'max:190'],
    ];

    /** GET /api/ops/mail-config — global (super only) + the caller's company relay. */
    public function show(): JsonResponse
    {
        $user = auth()->user();
        $out = [];

        if ($user->isSuperAdmin()) {
            $out['global'] = [
                'host' => Setting::get('mail_host'),
                'port' => Setting::get('mail_port'),
                'username' => Setting::get('mail_username'),
                'has_password' => (bool) Setting::get('mail_password'),
                'encryption' => Setting::get('mail_encryption', 'tls'),
                'from_address' => Setting::get('mail_from_address'),
                'from_name' => Setting::get('mail_from_name'),
            ];
        }

        if ($user->company_id && ($company = Company::find($user->company_id))) {
            $ms = $company->mail_settings ?: [];
            $out['company'] = [
                'host' => $ms['host'] ?? null,
                'port' => $ms['port'] ?? null,
                'username' => $ms['username'] ?? null,
                'has_password' => ! empty($ms['password']),
                'encryption' => $ms['encryption'] ?? 'tls',
                'from_address' => $ms['from_address'] ?? null,
                'from_name' => $ms['from_name'] ?? null,
            ];
        }

        return response()->json($out);
    }

    /** PUT /api/ops/mail-config — save the GLOBAL relay (Super Admin, route-enforced). */
    public function saveGlobal(Request $request): JsonResponse
    {
        $data = $request->validate(self::FIELDS);

        Setting::put('mail_host', $data['host'] ?? '');
        Setting::put('mail_port', (string) ($data['port'] ?? ''));
        Setting::put('mail_username', $data['username'] ?? '');
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            Setting::put('mail_password', Crypt::encryptString($data['password'])); // blank = keep current
        }
        Setting::put('mail_encryption', $data['encryption'] ?? 'tls');
        Setting::put('mail_from_address', $data['from_address'] ?? '');
        Setting::put('mail_from_name', $data['from_name'] ?? '');

        $this->audit($request, 'SETTINGS_MAIL_GLOBAL_UPDATE', Setting::class, null, ['host' => $data['host'] ?? '']);

        return response()->json(['ok' => true]);
    }

    /** PUT /api/ops/mail-config/company — save the caller's company relay. */
    public function saveCompany(Request $request): JsonResponse
    {
        $user = auth()->user();
        $company = Company::findOrFail($user->company_id);

        $data = $request->validate(self::FIELDS);

        $ms = $company->mail_settings ?: [];
        $ms['host'] = $data['host'] ?? '';
        $ms['port'] = $data['port'] ?? null;
        $ms['username'] = $data['username'] ?? '';
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $ms['password'] = Crypt::encryptString($data['password']); // blank = keep current
        }
        if (($data['host'] ?? '') === '') {
            $ms = null; // cleared host = remove the override entirely → fall back to global
        } else {
            $ms['encryption'] = $data['encryption'] ?? 'tls';
            $ms['from_address'] = $data['from_address'] ?? '';
            $ms['from_name'] = $data['from_name'] ?? '';
        }

        $company->mail_settings = $ms;
        $company->save();

        $this->audit($request, 'SETTINGS_MAIL_COMPANY_UPDATE', Company::class, $company->id, ['host' => $data['host'] ?? '(cleared)']);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/ops/mail-config/test {scope: global|company} — send a test email
     * to the signed-in admin via the chosen relay resolution.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', 'in:global,company']]);
        $user = auth()->user();

        if ($data['scope'] === 'global' && ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Only a Super Admin can test the global relay.'], 403);
        }

        [$ok, $error] = MailService::test(
            $user->email,
            $data['scope'] === 'company' ? $user->company_id : null
        );

        return $ok
            ? response()->json(['ok' => true, 'message' => 'Test email sent to ' . $user->email . ' — check the inbox (and spam).'])
            : response()->json(['ok' => false, 'message' => 'Send failed: ' . $error], 422);
    }

    /**
     * GET /api/ops/notify-prefs — Audit & Ops → Notifications.
     * company = the caller's OWN company alerts (its admin decides); server = Super Admin only.
     */
    public function notifyPrefs(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'company' => $user->company_id ? MailService::prefs((int) $user->company_id) : null,
            'server'  => $user->isSuperAdmin() ? MailService::prefs(null) : null,
            'templates' => MailService::TEMPLATES, // built-in wording + {placeholders} for the editor
        ]);
    }

    /** PUT /api/ops/notify-prefs {scope: company|server, prefs:{kind:{on,roles,extra,subject,body,threshold|minutes|hours|hour}}} */
    public function saveNotifyPrefs(Request $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'scope' => ['required', 'in:company,server'],
            'prefs' => ['required', 'array'],
            'prefs.*.on' => ['boolean'],
            'prefs.*.roles' => ['array'],
            'prefs.*.roles.*' => ['in:SUPER_ADMIN,COMPANY_ADMIN,HR_ADMIN,MANAGER'],
            'prefs.*.extra' => ['nullable', 'string', 'max:1000'],
            'prefs.violation_spike.threshold' => ['integer', 'min:1', 'max:100000'],
            'prefs.late_login.minutes' => ['integer', 'min:1', 'max:600'],
            'prefs.device_offline.minutes' => ['integer', 'min:1', 'max:10080'],
            'prefs.gate_long_break.hours' => ['numeric', 'min:0.1', 'max:24'],
            'prefs.*.subject' => ['nullable', 'string', 'max:250'],
            'prefs.*.body' => ['nullable', 'string', 'max:10000'],
            'prefs.*.hour' => ['integer', 'min:0', 'max:23'],
        ]);

        if ($data['scope'] === 'server' && ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Only a Super Admin can change server notifications.'], 403);
        }
        $companyId = $data['scope'] === 'company' ? (int) $user->company_id : null;
        if ($data['scope'] === 'company' && ! $companyId) {
            return response()->json(['message' => 'Your account is not linked to a company.'], 422);
        }

        $merged = MailService::prefs($companyId);
        foreach ($merged as $kind => $cur) {
            $merged[$kind] = array_merge($cur, array_intersect_key((array) ($data['prefs'][$kind] ?? []), $cur));
        }
        Setting::put(MailService::prefsKey($companyId), json_encode($merged));

        $this->audit($request, 'SETTINGS_NOTIFY_PREFS_UPDATE', $companyId ? Company::class : Setting::class, $companyId,
            array_map(fn ($p) => ! empty($p['on']), $merged));

        return response()->json(['ok' => true, 'prefs' => $merged]);
    }

    /** GET /api/ops/mail-log — last 100 email attempts; a company admin sees only their company's. */
    public function mailLog(): JsonResponse
    {
        $user = auth()->user();

        return response()->json(['data' => \App\Models\MailLog::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->latest('id')->limit(100)
            ->get(['id', 'to', 'subject', 'kind', 'status', 'error', 'created_at'])]);
    }
}
