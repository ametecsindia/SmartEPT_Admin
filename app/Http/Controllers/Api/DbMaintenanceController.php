<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Danger Zone — super-admin data clearing (Ejaz 24-Jul).
 *
 * Wipes OPERATIONAL data for the caller's company, in selectable groups or all at once.
 * Guarded three ways: SUPER_ADMIN-only route, a 6-digit code e-mailed to the super admin
 * (second factor), and a typed CONFIRM phrase. A full database backup is taken automatically
 * before anything is deleted. People, users, org structure, policies and the licence are NEVER
 * touched here. Every step is audit-logged.
 */
class DbMaintenanceController extends Controller
{
    private const OTP_TTL = 600;          // 10 minutes
    private const OTP_MAX_TRIES = 5;

    /** Operational data groups that MAY be cleared. Never includes people/config/licence. */
    private function groups(): array
    {
        return [
            'attendance'  => ['label' => 'Attendance & login sessions', 'tables' => ['employee_attendance_logs', 'employee_login_sessions']],
            'activity'    => ['label' => 'Activity, idle, app & website usage, status timeline', 'tables' => ['employee_activity_events', 'employee_idle_logs', 'employee_app_usage_logs', 'employee_website_usage_logs', 'status_timeline']],
            'screenshots' => ['label' => 'Screenshots (records + image files)', 'tables' => ['employee_screenshot_logs', 'media_access_logs'], 'files' => 'SCREEN'],
            'webcam'      => ['label' => 'Webcam photos & presence (records + files)', 'tables' => ['employee_webcam_logs', 'employee_presence_events'], 'files' => 'WEBCAM'],
            'violations'  => ['label' => 'Violations / compliance events', 'tables' => ['employee_compliance_events']],
            'breaks'      => ['label' => 'Break records', 'tables' => ['employee_break_logs']],
            'meetings'    => ['label' => 'Meetings & attendance sessions', 'tables' => ['employee_meeting_sessions', 'meeting_participants', 'meetings']],
            'biometric'   => ['label' => 'Biometric punch logs', 'tables' => ['biometric_logs']],
            'summaries'   => ['label' => 'Daily summaries', 'tables' => ['employee_daily_summaries']],
            'archives'    => ['label' => 'Deleted-employee archives (records + backup ZIPs)', 'tables' => ['employee_archives'], 'archives' => true],
        ];
    }

    /** GET /api/ops/db-clear/summary — the clearable groups + how many rows each holds now. */
    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $out = [];
        foreach ($this->groups() as $key => $g) {
            $count = 0;
            foreach ($g['tables'] as $t) {
                $count += $this->countTable($t, $companyId);
            }
            $out[] = ['key' => $key, 'label' => $g['label'], 'tables' => $g['tables'], 'count' => $count];
        }

        return response()->json(['data' => [
            'groups'      => $out,
            'company_id'  => $companyId,
            'email_masked' => $this->mask($request->user()->email),
        ]]);
    }

    /** POST /api/ops/db-clear/request-code — e-mail the super admin a one-time code. */
    public function requestCode(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->company_id, 422, 'Your account has no company context to clear.');

        $code = (string) random_int(100000, 999999);
        Cache::put($this->otpKey($user->id), [
            'hash' => hash('sha256', $code),
            'tries' => 0,
        ], self::OTP_TTL);

        $status = MailService::send(
            $user->email,
            'SmartEPT — data-clear verification code',
            "Your verification code to clear SmartEPT data is: {$code}\n\n"
            . "It expires in 10 minutes. If you did not request this, ignore this email and "
            . "change your password — someone with admin access is attempting to erase data.",
            'db_clear_otp',
            $user->company_id
        );

        $this->audit($request, 'DB_CLEAR_CODE_SENT', \App\Models\User::class, $user->id, ['email_status' => $status]);

        return response()->json(['data' => [
            'sent'         => $status,
            'email_masked' => $this->mask($user->email),
            'note'         => $status === 'sent'
                ? 'A 6-digit code has been e-mailed to you.'
                : 'E-mail could not be sent (SMTP may not be configured). The code is in the app log — open Help → Troubleshooting → Log viewer to read it.',
        ]]);
    }

    /** POST /api/ops/db-clear/execute — verify the code + phrase, back up, then clear. */
    public function execute(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->company_id, 422, 'Your account has no company context to clear.');

        $data = $request->validate([
            'code'     => ['required', 'string'],
            'confirm'  => ['required', 'string'],
            'groups'   => ['required', 'array', 'min:1'],
            'groups.*' => ['string'],
        ]);

        abort_unless(strtoupper(trim($data['confirm'])) === 'CLEAR', 422,
            'Type CLEAR in the confirmation box to proceed.');

        // --- verify the emailed code (second factor) ---
        $key = $this->otpKey($user->id);
        $otp = Cache::get($key);
        abort_unless($otp, 422, 'No active code — press "E-mail me a code" first (codes expire after 10 minutes).');
        if (($otp['tries'] ?? 0) >= self::OTP_MAX_TRIES) {
            Cache::forget($key);
            abort(429, 'Too many wrong codes. Request a new code.');
        }
        if (! hash_equals($otp['hash'], hash('sha256', trim($data['code'])))) {
            $otp['tries'] = ($otp['tries'] ?? 0) + 1;
            Cache::put($key, $otp, self::OTP_TTL);
            abort(422, 'That code is not correct.');
        }
        Cache::forget($key); // single use

        $companyId = $user->company_id;
        $registry  = $this->groups();
        $selected  = array_values(array_intersect(array_keys($registry), $data['groups']));
        abort_if(empty($selected), 422, 'None of the selected groups are valid.');

        // --- automatic full backup BEFORE deleting anything ---
        $backup = 'skipped';
        try {
            Artisan::call('smartept:backup-database');
            $backup = trim(Artisan::output()) ?: 'done';
        } catch (\Throwable $e) {
            $backup = 'backup failed: ' . mb_substr($e->getMessage(), 0, 200);
        }

        // --- clear each selected group (company-scoped) ---
        $cleared = [];
        $filesDeleted = 0;
        foreach ($selected as $key2) {
            $g = $registry[$key2];
            $groupCounts = [];

            if (! empty($g['files'])) {
                $filesDeleted += $this->purgeStorageFiles($companyId, $g['files']);
            }
            if (! empty($g['archives'])) {
                $filesDeleted += $this->purgeArchiveZips($companyId);
            }
            foreach ($g['tables'] as $t) {
                $groupCounts[$t] = $this->deleteTable($t, $companyId);
            }
            $cleared[$key2] = $groupCounts;
        }

        $this->audit($request, 'DB_CLEAR_EXECUTED', \App\Models\User::class, $user->id, [
            'groups'        => $selected,
            'cleared'       => $cleared,
            'files_deleted' => $filesDeleted,
            'backup'        => mb_substr((string) $backup, 0, 300),
        ]);

        return response()->json(['data' => [
            'cleared'       => $cleared,
            'files_deleted' => $filesDeleted,
            'total_rows'    => array_sum(array_map(fn ($g) => array_sum($g), $cleared)),
            'backup'        => $backup,
        ]]);
    }

    // ---- helpers ----

    private function otpKey(int $userId): string
    {
        return 'dbclear:otp:' . $userId;
    }

    private function mask(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '(no email on file)';
        }
        [$u, $d] = explode('@', $email, 2);
        $head = mb_substr($u, 0, 2);

        return $head . str_repeat('*', max(1, mb_strlen($u) - 2)) . '@' . $d;
    }

    /**
     * Build a company-scoped query for a table. SAFETY: if the table can be scoped by
     * company_id we use that; else if it has employee_id we scope to this company's
     * employees; if neither is possible we return NULL so the caller SKIPS it — a table
     * we cannot tenant-scope is never touched (no accidental cross-company wipe).
     */
    private function scoped(string $table, ?int $companyId)
    {
        if (! Schema::hasTable($table) || ! $companyId) {
            return null;
        }
        if (Schema::hasColumn($table, 'company_id')) {
            return DB::table($table)->where('company_id', $companyId);
        }
        if (Schema::hasColumn($table, 'employee_id')) {
            return DB::table($table)->whereIn('employee_id', function ($q) use ($companyId) {
                $q->select('id')->from('employees')->where('company_id', $companyId);
            });
        }

        return null; // cannot scope safely → skip
    }

    private function countTable(string $table, ?int $companyId): int
    {
        try {
            $q = $this->scoped($table, $companyId);

            return $q ? (int) $q->count() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function deleteTable(string $table, ?int $companyId): int
    {
        try {
            $q = $this->scoped($table, $companyId);

            return $q ? (int) $q->delete() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Delete stored media files of a type for a company, then their storage_files rows. */
    private function purgeStorageFiles(?int $companyId, string $typeLike): int
    {
        $deleted = 0;
        try {
            if (! Schema::hasTable('storage_files')) {
                return 0;
            }
            DB::table('storage_files')
                ->where('company_id', $companyId)
                ->where('file_type', 'like', '%' . $typeLike . '%')
                ->orderBy('id')
                ->chunk(500, function ($files) use (&$deleted) {
                    foreach ($files as $f) {
                        try {
                            if (($f->storage_driver ?? null) && ($f->storage_key ?? null)
                                && Storage::disk($f->storage_driver)->exists($f->storage_key)) {
                                Storage::disk($f->storage_driver)->delete($f->storage_key);
                            }
                        } catch (\Throwable $e) {
                            // best-effort file removal
                        }
                        $deleted++;
                    }
                });

            DB::table('storage_files')
                ->where('company_id', $companyId)
                ->where('file_type', 'like', '%' . $typeLike . '%')
                ->delete();
        } catch (\Throwable $e) {
            // ignore
        }

        return $deleted;
    }

    /** Delete the backup ZIPs referenced by this company's employee_archives rows. */
    private function purgeArchiveZips(?int $companyId): int
    {
        $deleted = 0;
        try {
            if (! Schema::hasTable('employee_archives')) {
                return 0;
            }
            DB::table('employee_archives')
                ->where('company_id', $companyId)
                ->whereNotNull('storage_key')
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$deleted) {
                    foreach ($rows as $r) {
                        try {
                            if (($r->storage_driver ?? 'local') && $r->storage_key
                                && Storage::disk($r->storage_driver ?: 'local')->exists($r->storage_key)) {
                                Storage::disk($r->storage_driver ?: 'local')->delete($r->storage_key);
                                $deleted++;
                            }
                        } catch (\Throwable $e) {
                            // best-effort
                        }
                    }
                });
        } catch (\Throwable $e) {
            // ignore
        }

        return $deleted;
    }
}
