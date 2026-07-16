<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeDevice;
use App\Models\EmployeeWebsiteUsageLog;
use App\Services\ComplianceEvaluator;
use App\Services\PolicyResolver;
use App\Support\ResolvesAgentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UsageController extends Controller
{
    use ResolvesAgentContext;

    public function __construct(private PolicyResolver $resolver, private ComplianceEvaluator $evaluator) {}

    /**
     * POST /api/agent/app-usage
     * Batch of foreground-app usage. The server categorises each row and marks violations
     * (authoritative), independent of the agent's own instant warnings.
     */
    public function appUsage(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'               => ['required', 'string'],
            'events'                    => ['required', 'array', 'min:1', 'max:500'],
            'events.*.app_name'         => ['nullable', 'string', 'max:255'],
            'events.*.process_name'     => ['nullable', 'string', 'max:255'],
            'events.*.window_title'     => ['nullable', 'string', 'max:512'],
            'events.*.start_at'         => ['required', 'date'],
            'events.*.end_at'           => ['nullable', 'date'],
            'events.*.duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $bundle = $this->resolver->bundleForEmployee($employee);
        abort_unless($bundle['policies']['monitoring']['app_usage_enabled'] ?? false, 403, 'App usage tracking is not enabled.');
        $appPolicy = $bundle['policies']['application'] ?? null;

        $now = now();
        $rows = [];
        foreach ($data['events'] as $e) {
            $name = $e['app_name'] ?? $e['process_name'] ?? '';
            $verdict = $this->evaluator->classifyApp($appPolicy, (string) $name);
            $start = Carbon::parse($e['start_at']);
            $end = isset($e['end_at']) ? Carbon::parse($e['end_at']) : null;

            $rows[] = [
                'company_id'        => $employee->company_id,
                'employee_id'       => $employee->id,
                'device_uuid'       => $data['device_uuid'],
                'app_name'          => $e['app_name'] ?? null,
                'process_name'      => $e['process_name'] ?? null,
                'window_title'      => $e['window_title'] ?? null,
                'start_at'          => $start,
                'end_at'            => $end,
                'duration_seconds'  => $e['duration_seconds'] ?? ($end ? (int) $end->diffInSeconds($start, true) : 0),
                'category'          => $verdict['category'],
                'compliance_status' => $verdict['blocked'] ? 'VIOLATION' : 'OK',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        EmployeeAppUsageLog::insert($rows);
        EmployeeDevice::where('device_uuid', $data['device_uuid'])->update(['last_sync_at' => $now]);

        return response()->json(['ok' => true, 'stored' => count($rows)], 202);
    }

    /**
     * POST /api/agent/website-usage
     * Batch of browser usage. Note: without the browser extension (enterprise phase), the
     * domain/title are best-effort from the browser window title.
     */
    public function websiteUsage(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);

        $data = $request->validate([
            'device_uuid'               => ['required', 'string'],
            'events'                    => ['required', 'array', 'min:1', 'max:500'],
            'events.*.browser'          => ['nullable', 'string', 'max:255'],
            'events.*.domain'           => ['nullable', 'string', 'max:255'],
            'events.*.full_url'         => ['nullable', 'string', 'max:1024'],
            'events.*.page_title'       => ['nullable', 'string', 'max:512'],
            'events.*.start_at'         => ['required', 'date'],
            'events.*.end_at'           => ['nullable', 'date'],
            'events.*.duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $bundle = $this->resolver->bundleForEmployee($employee);
        abort_unless($bundle['policies']['monitoring']['website_usage_enabled'] ?? false, 403, 'Website usage tracking is not enabled.');
        $sitePolicy = $bundle['policies']['website'] ?? null;

        $now = now();
        $rows = [];
        foreach ($data['events'] as $e) {
            $verdict = $this->evaluator->classifyWebsite($sitePolicy, $e['domain'] ?? null, $e['page_title'] ?? null);
            $start = Carbon::parse($e['start_at']);
            $end = isset($e['end_at']) ? Carbon::parse($e['end_at']) : null;

            $rows[] = [
                'company_id'        => $employee->company_id,
                'employee_id'       => $employee->id,
                'device_uuid'       => $data['device_uuid'],
                'browser'           => $e['browser'] ?? null,
                'domain'            => $e['domain'] ?? null,
                'full_url'          => $e['full_url'] ?? null,
                'page_title'        => $e['page_title'] ?? null,
                'start_at'          => $start,
                'end_at'            => $end,
                'duration_seconds'  => $e['duration_seconds'] ?? ($end ? (int) $end->diffInSeconds($start, true) : 0),
                'category'          => $verdict['category'],
                'compliance_status' => $verdict['blocked'] ? 'VIOLATION' : 'OK',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        EmployeeWebsiteUsageLog::insert($rows);

        return response()->json(['ok' => true, 'stored' => count($rows)], 202);
    }

    /** GET /api/reports/employee/{employee}/app-usage — aggregated by app for a date. */
    public function appReport(Request $request, \App\Models\Employee $employee): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $rows = EmployeeAppUsageLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $date)
            ->selectRaw('app_name, category, SUM(duration_seconds) as seconds, MAX(compliance_status) as status')
            ->groupBy('app_name', 'category')
            ->orderByDesc('seconds')
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** GET /api/reports/employee/{employee}/website-usage — aggregated by domain for a date. */
    public function websiteReport(Request $request, \App\Models\Employee $employee): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $rows = EmployeeWebsiteUsageLog::where('employee_id', $employee->id)
            ->whereDate('start_at', $date)
            ->selectRaw('COALESCE(domain, page_title) as site, category, SUM(duration_seconds) as seconds, MAX(compliance_status) as status')
            ->groupBy('site', 'category')
            ->orderByDesc('seconds')
            ->get();

        return response()->json(['data' => $rows]);
    }
}
