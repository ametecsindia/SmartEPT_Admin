<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Services\ScheduledReports;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 30-Sep-2026 (Ejaz): Reports → Schedule Report. CRUD for automatic Productivity report emails,
 * plus "Send test to me" and "Send now". Access is the "Report schedules" card in the role
 * matrix (App\Support\CardAccess); recipients must be employees the admin can see.
 */
class ReportScheduleController extends Controller
{
    use ScopesVisibleEmployees;

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => ReportSchedule::where('company_id', $request->user()->company_id)
            ->with('creator:id,name')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $s = ReportSchedule::create($data + ['company_id' => $request->user()->company_id, 'created_by' => $request->user()->id]);
        $this->audit($request, 'CREATE', 'report_schedule', $s->id, ['name' => $s->name]);

        return response()->json(['data' => $s->fresh('creator:id,name')], 201);
    }

    public function update(Request $request, ReportSchedule $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $data = $this->validated($request);
        // A changed send time/day may now be due again today — that is the admin's intent.
        $schedule->fill($data + ['last_period' => null])->save();
        $this->audit($request, 'UPDATE', 'report_schedule', $schedule->id, ['name' => $schedule->name]);

        return response()->json(['data' => $schedule->fresh('creator:id,name')]);
    }

    public function destroy(Request $request, ReportSchedule $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $schedule->delete();
        $this->audit($request, 'DELETE', 'report_schedule', $schedule->id, ['name' => $schedule->name]);

        return response()->json(['ok' => true]);
    }

    /** POST {id}/run — send now to everyone (period ending yesterday). ?test=1 → only to me. */
    public function run(Request $request, ReportSchedule $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $test = $request->boolean('test');
        if ($test && ! filter_var($request->user()->email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'Your login has no email address to send the test to.');
        }
        @set_time_limit(300);
        $r = app(ScheduledReports::class)->run($schedule, null, $test ? $request->user()->email : null);
        $this->audit($request, $test ? 'TEST' : 'RUN', 'report_schedule', $schedule->id, ['result' => $r['text']]);

        return response()->json(['data' => $r, 'schedule' => $schedule->fresh('creator:id,name')]);
    }

    /** GET {id}/snapshot — the WhatsApp image for the period ending yesterday (preview in the console). */
    public function snapshot(Request $request, ReportSchedule $schedule)
    {
        $this->own($request, $schedule);
        $png = app(ScheduledReports::class)->snapshotPng($schedule);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    // ---- 30-Sep-2026: WhatsApp connection (Meta WhatsApp Business Platform), one per company ----

    public function whatsappShow(Request $request): JsonResponse
    {
        $c = \App\Services\WhatsAppSender::config((int) $request->user()->company_id);

        return response()->json(['data' => [
            'phone_number_id' => $c['phone_number_id'], 'template' => $c['template'], 'language' => $c['language'],
            'api_version' => $c['api_version'], 'has_token' => $c['token'] !== '',
            'connected' => $c['phone_number_id'] !== '' && $c['token'] !== '',
            'admin_phones' => \App\Models\User::where('company_id', $request->user()->company_id)->where('status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->where('slug', 'COMPANY_ADMIN'))->get(['name', 'phone'])
                ->map(fn ($u) => ['name' => $u->name, 'phone' => $u->phone])->values(),
        ]]);
    }

    public function whatsappSave(Request $request): JsonResponse
    {
        $d = $request->validate([
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^\d*$/'],
            'token'           => ['nullable', 'string', 'max:1000'],   // blank = keep the saved one
            'template'        => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]*$/'],
            'language'        => ['nullable', 'string', 'max:10'],
            'api_version'     => ['nullable', 'string', 'max:10', 'regex:/^v\d+\.\d+$/'],
            'disconnect'      => ['boolean'],
        ]);
        $cid = (int) $request->user()->company_id;
        $old = json_decode((string) \App\Models\Setting::get(\App\Services\WhatsAppSender::key($cid), ''), true) ?: [];
        $new = $request->boolean('disconnect') ? [] : [
            'phone_number_id' => trim((string) ($d['phone_number_id'] ?? '')),
            'token'           => trim((string) ($d['token'] ?? '')) !== '' ? \Illuminate\Support\Facades\Crypt::encryptString(trim($d['token'])) : ($old['token'] ?? ''),
            'template'        => trim((string) ($d['template'] ?? '')),
            'language'        => trim((string) ($d['language'] ?? '')),
            'api_version'     => trim((string) ($d['api_version'] ?? '')),
        ];
        \App\Models\Setting::put(\App\Services\WhatsAppSender::key($cid), json_encode($new));
        $this->audit($request, 'UPDATE', 'whatsapp_config', $cid, ['phone_number_id' => $new['phone_number_id'] ?? null, 'token_changed' => trim((string) ($d['token'] ?? '')) !== '']);

        return $this->whatsappShow($request);
    }

    /** POST whatsapp-config/test {to} — a sample snapshot (yesterday, whole company) to one number. */
    public function whatsappTest(Request $request): JsonResponse
    {
        $d = $request->validate(['to' => ['required', 'string', 'max:25']]);
        abort_unless(\App\Services\WhatsAppSender::normalise($d['to']), 422, 'Not a valid mobile number.');
        $cid = (int) $request->user()->company_id;
        abort_unless(\App\Services\WhatsAppSender::connected($cid), 422, 'Save the Phone number ID and access token first.');
        $s = new ReportSchedule(['company_id' => $cid, 'created_by' => $request->user()->id, 'name' => 'WhatsApp test', 'frequency' => 'DAILY']);
        $svc = app(ScheduledReports::class);
        [$png, $label, $rows] = $svc->snapshotParts($s);
        $status = app(\App\Services\WhatsAppSender::class)->sendImage($cid, $d['to'], $png, $label . ' · WhatsApp test', ScheduledReports::summaryLine($rows));
        $log = \App\Models\MailLog::where('kind', \App\Services\WhatsAppSender::KIND)->latest('id')->first();

        return $status === 'sent'
            ? response()->json(['data' => ['status' => $status]])
            : response()->json(['message' => 'Not sent — ' . ($log?->error ?: $status), 'data' => ['status' => $status, 'error' => $log?->error]], 422);
    }

    private function own(Request $request, ReportSchedule $s): void
    {
        abort_unless((int) $s->company_id === (int) $request->user()->company_id, 404);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'enabled'           => ['boolean'],
            'frequency'         => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY'])],
            'send_time'         => ['required', 'date_format:H:i'],
            'days'              => ['nullable', 'array'],
            'days.*'            => ['integer', 'between:1,7'],
            'day_of_month'      => ['nullable', 'integer', 'between:1,28'],
            'scope'             => ['nullable', 'array'],
            'scope.branch_id'   => ['nullable', 'integer'],
            'scope.department_id' => ['nullable', 'integer'],
            'scope.team_id'     => ['nullable', 'integer'],
            'all_employees_own' => ['boolean'],
            'team_reports'      => ['boolean'],   // 07-Oct-2026: reporting team's report to each manager
            'recipients'        => ['nullable', 'array', 'max:5000'],
            'recipients.*.employee_id' => ['required', 'integer'],
            'recipients.*.email' => ['nullable', 'email', 'max:190'],
            'recipients.*.mode' => ['required', Rule::in(['OWN', 'FULL'])],
            'extra_emails'      => ['nullable', 'array', 'max:100'],
            'extra_emails.*'    => ['email', 'max:190'],
            'skip_empty'        => ['boolean'],
            'skip_holidays'     => ['boolean'],
            'attach_excel'      => ['boolean'],
            'subject'           => ['nullable', 'string', 'max:200'],
            'message'           => ['nullable', 'string', 'max:2000'],
            'whatsapp_enabled'  => ['boolean'],
            'whatsapp_admins'   => ['boolean'],
            'whatsapp_numbers'  => ['nullable', 'array', 'max:50'],
            'whatsapp_numbers.*' => ['string', 'max:25'],
        ]);

        // 30-Sep-2026: WhatsApp numbers — any format typed (+91 98…, 098…, 98…); stored as digits.
        $nums = [];
        foreach ($d['whatsapp_numbers'] ?? [] as $n) {
            $ok = \App\Services\WhatsAppSender::normalise($n);
            abort_unless($ok, 422, 'Not a valid mobile number: ' . $n);
            $nums[] = $ok;
        }
        $d['whatsapp_numbers'] = array_values(array_unique($nums));
        if (! empty($d['whatsapp_enabled']) && ! $d['whatsapp_numbers'] && empty($d['whatsapp_admins'])) {
            abort(422, 'Add at least one WhatsApp number, or tick Company Admin(s).');
        }

        if ($d['frequency'] === 'DAILY' && empty($d['days'])) {
            abort(422, 'Pick at least one day to send on.');
        }
        if ($d['frequency'] === 'WEEKLY' && count($d['days'] ?? []) !== 1) {
            abort(422, 'Pick the one day of the week to send on.');
        }

        // Recipients must be people this admin can see (a manager can't mail another team's staff).
        $visible = $this->visibleEmployeeIds($request->user());
        $ids = array_map(fn ($r) => (int) $r['employee_id'], $d['recipients'] ?? []);
        $known = \App\Models\Employee::where('company_id', $request->user()->company_id)->whereIn('id', $ids)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->pluck('id')->map(fn ($x) => (int) $x)->all();
        abort_if(array_diff($ids, $known), 422, 'Some chosen employees are not in your scope.');

        $d['recipients'] = array_values(array_map(fn ($r) => [
            'employee_id' => (int) $r['employee_id'], 'email' => ($r['email'] ?? null) ?: null, 'mode' => $r['mode'],
        ], $d['recipients'] ?? []));
        $d['extra_emails'] = array_values(array_unique(array_map('strtolower', $d['extra_emails'] ?? [])));
        $d['days'] = array_values(array_unique(array_map('intval', $d['days'] ?? [])));
        $d['day_of_month'] = (int) ($d['day_of_month'] ?? 1);
        $d['scope'] = array_filter((array) ($d['scope'] ?? []));

        return $d;
    }
}
