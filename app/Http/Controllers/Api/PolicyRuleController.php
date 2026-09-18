<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPolicy;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use App\Services\ProtectionCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-rule actions, saved from the Rules screen.
 *
 * Deliberately its own controller rather than more passthrough in
 * PolicyController: that one validates nothing by design (`passthroughRule()`
 * returns []) and serves twelve policy types. Rules need real validation,
 * because this is the endpoint that decides which programs a company's PCs will
 * refuse to open.
 *
 * The old JSON columns are still written by the existing save, so an agent at
 * or below 0.14 is unaffected. This endpoint owns policy_rules only.
 */
class PolicyRuleController extends Controller
{
    private const TYPES = [
        'application' => ['type' => 'APPLICATION', 'model' => ApplicationPolicy::class],
        'website'     => ['type' => 'WEBSITE',     'model' => WebsitePolicy::class],
    ];

    /**
     * Items no rule may ever be set to CLOSE or BLOCK.
     *
     * Terminating any of these ranges from "no desktop" to an immediate
     * bugcheck, and blocking our own components leaves the endpoint unable to
     * receive a corrected policy. Refused outright — there is no confirmation
     * that makes this a good idea.
     *
     * Mirrors agent/src/enforce/protected.js (NEVER_TERMINATE) and the
     * enforcer's catalog/protected.json. All three must change together.
     */
    private const NEVER_ENFORCE = [
        'explorer', 'winlogon', 'userinit', 'csrss', 'lsass', 'services', 'smss',
        'wininit', 'svchost', 'dwm', 'sihost', 'ctfmon', 'runtimebroker', 'logonui',
        'msmpeng', 'mpcmdrun', 'securityhealthservice',
        'trustedinstaller', 'tiworker', 'usoclient',
        'smartept agent', 'smartept', 'smartept-enforcer', 'smarteptsvc',
        'finder', 'systemd', 'gnome-shell',
    ];

    /**
     * Legitimate to enforce, but each is also how somebody works or how IT
     * repairs a PC. Allowed with a recorded confirmation, never silently.
     */
    private const CONFIRM_ENFORCE = [
        'cmd', 'powershell', 'pwsh', 'taskmgr', 'regedit', 'mmc', 'msconfig',
        'chrome', 'msedge', 'firefox', 'brave',
        'outlook', 'ms-teams', 'teams', 'zoom', 'slack',
        'anydesk', 'teamviewer', 'quicksupport', 'rustdesk',
    ];

    /**
     * GET the protection capability matrix.
     *
     * The console calls this once when the Rules screen opens and uses it to
     * decide which protection checkboxes to render, which to disable, and what
     * consequence to print next to a browser-wide one. Without it the screen
     * would offer every protection on every item and quietly enforce a third of
     * them — the exact defect the client wrote a whole section about.
     */
    public function capabilities(ProtectionCapabilities $capabilities): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $capabilities->forConsole()]);
    }

    /** GET the rules for one policy. */
    public function index(Request $request, string $type, int $policy): JsonResponse
    {
        [$typeName] = $this->resolve($request, $type, $policy);

        $rules = PolicyRule::withoutGlobalScopes()
            ->where('company_id', (int) $request->user()->company_id)
            ->where('policy_type', $typeName)
            ->where('policy_id', $policy)
            ->orderBy('item')
            ->get();

        return response()->json(['ok' => true, 'data' => $rules]);
    }

    /**
     * Replace the rule set for one policy.
     *
     * A full replace, not a merge: the screen always sends the complete list,
     * and a merge would leave a removed row enforcing on every endpoint with
     * nothing in the console to show for it.
     */
    public function replace(Request $request, string $type, int $policy): JsonResponse
    {
        [$typeName, $policyModel] = $this->resolve($request, $type, $policy);

        $data = $request->validate([
            'rules'                  => ['present', 'array', 'max:2000'],
            'rules.*.item'           => ['required', 'string', 'max:191'],
            'rules.*.label'          => ['nullable', 'string', 'max:191'],
            'rules.*.status'         => ['required', 'in:' . implode(',', PolicyRule::STATUSES)],
            'rules.*.action'         => ['required', 'in:' . implode(',', PolicyRule::ACTIONS)],
            'rules.*.catalog_app_id' => ['nullable', 'string', 'max:64'],
            'rules.*.identifiers'    => ['nullable', 'array'],
            'rules.*.confirmed'      => ['nullable', 'boolean'],
            // Protections are a SECOND lever, independent of status and action:
            // an ALLOWED row may carry them, which is the whole point of the
            // feature. Accepted as a map so the console can send the checkbox
            // state verbatim; normalised to a canonical map below.
            'rules.*.protections'          => ['nullable', 'array'],
            'rules.*.protections.file'     => ['nullable', 'boolean'],
            'rules.*.protections.image'    => ['nullable', 'boolean'],
            'rules.*.protections.camera'   => ['nullable', 'boolean'],
            'rules.*.protections.video'    => ['nullable', 'boolean'],
        ]);

        $companyId = (int) $request->user()->company_id;
        $userId    = $request->user()->id;

        // Validate the whole set before writing any of it. A half-applied rule
        // set is worse than a rejected one.
        $refused = [];
        $needsConfirmation = [];
        $unenforceable = [];
        $normalised = [];
        $capabilities = app(ProtectionCapabilities::class);

        foreach ($data['rules'] as $r) {
            $item = $this->normalise((string) $r['item'], $typeName);
            if ($item === '') {
                continue;
            }

            $status = strtoupper($r['status']);
            $action = strtoupper($r['action']);
            $hard = in_array($action, PolicyRule::HARD_ACTIONS, true)
                && in_array($status, ['BLOCKED', 'VIOLATION'], true);

            $protections = $this->normaliseProtections($r['protections'] ?? null);

            // Backstop for the console, not a substitute for it. A protection
            // the capability matrix says cannot be enforced on this item is
            // REFUSED rather than stored: storing it would put a control in the
            // console, in the audit trail and in front of a bank's auditor that
            // no endpoint will ever act on.
            foreach (array_keys($protections) as $protection) {
                if (! $capabilities->isOffered($item, $typeName, (string) $protection)) {
                    $unenforceable[] = $item . ' → ' . $protection;
                    unset($protections[$protection]);
                }
            }

            if ($typeName === 'APPLICATION' && in_array($item, self::NEVER_ENFORCE, true)) {
                // Refused for a full block for the reasons in the constant, and
                // refused for protections too: denying explorer.exe the camera or
                // closing its file dialogs is the same class of damage by a
                // quieter route, and nothing on that list is an app an employee
                // shares files from.
                if ($hard || $protections !== []) {
                    $refused[] = $item;
                    continue;
                }
            }

            if ($hard && $typeName === 'APPLICATION'
                && in_array($item, self::CONFIRM_ENFORCE, true) && empty($r['confirmed'])) {
                $needsConfirmation[] = $item;
                continue;
            }

            // "steam" and "steam.exe" arrive as two rows and normalise to one.
            // Last-wins silently dropped whatever the first row carried - a
            // camera tick on "steam" vanished because "steam.exe" followed it
            // with none. Protections are unioned; the rest of the row is the
            // later one, as before.
            if (isset($normalised[$item])) {
                $protections = (array) $normalised[$item]['protections'] + $protections;
            }

            $normalised[$item] = [
                'item'           => $item,
                'label'          => $r['label'] ?? $r['item'],
                'status'         => $status,
                'action'         => $action,
                'catalog_app_id' => $r['catalog_app_id'] ?? null,
                'identifiers'    => $r['identifiers'] ?? null,
                'protections'    => $protections,
                'confirmed'      => ! empty($r['confirmed']) && $hard,
            ];
        }

        // Refused outright: never-enforce items and unconfirmed guarded ones.
        // An unenforceable protection is NOT a refusal: it has already been
        // dropped above, the rest of the row saves, and the response says what
        // was dropped. Refusing the whole save for it meant a protection that
        // was offered yesterday and withdrawn today blocked every other edit.
        if ($refused || $needsConfirmation) {
            return response()->json([
                'error' => [
                    'code' => 'RULE_REFUSED',
                    'message' => $this->refusalMessage($refused, $needsConfirmation, $unenforceable),
                    'refused' => $refused,
                    'needs_confirmation' => $needsConfirmation,
                    'unenforceable' => $unenforceable,
                ],
            ], 422);
        }

        DB::transaction(function () use ($normalised, $companyId, $typeName, $policy, $userId, $policyModel) {
            $existing = PolicyRule::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('policy_type', $typeName)
                ->where('policy_id', $policy)
                ->get()
                ->keyBy('item');

            foreach ($normalised as $item => $row) {
                $rule = $existing[$item] ?? new PolicyRule();
                $wasConfirmed = $rule->exists && $rule->confirmed_at !== null;

                $rule->forceFill([
                    'company_id'     => $companyId,
                    'policy_type'    => $typeName,
                    'policy_id'      => $policy,
                    'item'           => $item,
                    'label'          => $row['label'],
                    'status'         => $row['status'],
                    'action'         => $row['action'],
                    'catalog_app_id' => $row['catalog_app_id'],
                    'identifiers'    => $row['identifiers'],
                    // NULL, not [], when nothing is set: the column's "no
                    // protections" state and the pre-upgrade state are then the
                    // same value, so a rule saved by this build and one that
                    // predates the column are indistinguishable to every reader.
                    'protections'    => $row['protections'] !== [] ? $row['protections'] : null,
                    'version'        => (int) ($rule->version ?? 0) + 1,
                ]);

                // Record who confirmed a guarded rule, once. Re-saving an
                // already-confirmed rule must not silently reassign blame.
                if ($row['confirmed'] && ! $wasConfirmed) {
                    $rule->confirmed_by_user_id = $userId;
                    $rule->confirmed_at = now();
                } elseif (! $row['confirmed']) {
                    $rule->confirmed_by_user_id = null;
                    $rule->confirmed_at = null;
                }

                $rule->save();
            }

            // Anything the screen no longer lists is gone.
            PolicyRule::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('policy_type', $typeName)
                ->where('policy_id', $policy)
                ->whereNotIn('item', array_keys($normalised) ?: [''])
                ->delete();

            // Move the policy version so latest_policy_version changes and the
            // agent heartbeat tells endpoints to re-sync. Without this a rule
            // edit reaches PCs whenever the next scheduled refresh happens,
            // which reads to an admin as a rule that does not work.
            $policyModel::withoutGlobalScopes()
                ->where('id', $policy)
                ->update(['version' => DB::raw('version + 1')]);
        });

        $response = $this->index($request, $type, $policy);
        if ($unenforceable) {
            $body = $response->getData(true);
            $body['warning'] = $this->refusalMessage([], [], $unenforceable);
            $body['unenforceable'] = array_values(array_unique($unenforceable));
            $response->setData($body);
        }

        return $response;
    }

    /** @return array{0:string,1:class-string} */
    private function resolve(Request $request, string $type, int $policy): array
    {
        $key = strtolower($type);
        abort_unless(isset(self::TYPES[$key]), 404, 'Unknown policy type.');

        $model = self::TYPES[$key]['model'];
        $row = $model::withoutGlobalScopes()->find($policy);

        abort_unless($row, 404, 'Policy not found.');
        // Tenant isolation: the global scope is bypassed above so the console
        // and console-less contexts behave the same, so check the owner here.
        abort_unless((int) $row->company_id === (int) $request->user()->company_id, 403, 'Not your policy.');

        return [self::TYPES[$key]['type'], $model];
    }

    /**
     * The protection map, reduced to the keys this build knows and to booleans.
     *
     * Unknown keys are dropped rather than stored: the column is JSON precisely
     * so the set can grow, and letting an arbitrary key through would mean a
     * console typo lands in the database and an endpoint silently ignores it —
     * a protection an admin believes is on and that nothing enforces, which is
     * the failure mode this whole feature is written to avoid.
     *
     * @return array<string,bool>
     */
    private function normaliseProtections(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (PolicyRule::PROTECTIONS as $key) {
            if (filter_var($raw[$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /** Must match App\Services\ComplianceEvaluator's normalisation exactly. */
    private function normalise(string $s, string $type): string
    {
        $s = mb_strtolower(trim($s));
        if ($type === 'APPLICATION') {
            return trim((string) preg_replace('/\.exe$/i', '', $s));
        }
        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = (string) preg_replace('#^www\.#', '', $s);

        return trim($s, '/ ');
    }

    /**
     * @param array<int,string> $refused
     * @param array<int,string> $confirm
     * @param array<int,string> $unenforceable
     */
    private function refusalMessage(array $refused, array $confirm, array $unenforceable = []): string
    {
        $parts = [];
        if ($unenforceable) {
            $parts[] = 'SmartEPT cannot currently enforce these, so they were not saved: '
                . implode(', ', array_unique($unenforceable))
                . '. Leaving them switched on would show a control that nothing on the PC acts on.';
        }
        if ($refused) {
            $parts[] = 'These cannot be blocked, closed or restricted, ever: ' . implode(', ', $refused)
                . '. They run Windows, your antivirus or SmartEPT itself — enforcing them would break the PC.';
        }
        if ($confirm) {
            $parts[] = 'These need confirmation before they can be closed: ' . implode(', ', $confirm)
                . '. They are also how people work or how your IT repairs a PC.';
        }

        return implode(' ', $parts);
    }
}
