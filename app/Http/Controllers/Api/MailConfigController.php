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
}
