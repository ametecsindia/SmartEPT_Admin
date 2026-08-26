<?php
/**
 * Why is my rule not reaching the PC?
 *
 * Prints every policy rule in the database with a verdict: SENT, or DROPPED and
 * the reason. This exists because the console showing a rule and the endpoint
 * receiving it are two different claims, and until now nothing could tell them
 * apart without reading SQL by hand.
 *
 *   php ept-rules.php
 *
 * ponytail: a plain script, not an artisan command. It answers one question and
 * is thrown away when it stops being asked.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApplicationPolicy;
use App\Models\EnforcementState;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;

const HARD = ['CLOSE', 'BLOCK'];
const LIVE = ['BLOCKED', 'VIOLATION'];

function line(string $c = '-'): void { echo str_repeat($c, 100), PHP_EOL; }

/** The policy the endpoint will actually read: lowest id wins. */
function chosen(string $model, int $company): ?int
{
    $ids = $model::withoutGlobalScopes()
        ->where('company_id', $company)->orderBy('id')->pluck('id')->all();

    if (! $ids) {
        return null;
    }
    if (count($ids) > 1) {
        printf("  !! company %d has %d %s rows: %s — only %d is enforced\n",
            $company, count($ids), class_basename($model), implode(', ', $ids), $ids[0]);
    }

    return $ids[0];
}

$companies = PolicyRule::withoutGlobalScopes()->distinct()->pluck('company_id')->sort()->values();

if ($companies->isEmpty()) {
    echo "No policy rules exist in the database at all.\n";
    exit(0);
}

foreach ($companies as $company) {
    line('=');
    printf("COMPANY %d\n", $company);
    line('=');

    $state = EnforcementState::forCompany((int) $company);
    printf("  enforcement mode : %s\n", $state->mode ?? 'OFF');
    if (($state->mode ?? '') !== 'ENFORCE') {
        echo "  NOTE: nothing is refused on any PC unless the mode is ENFORCE.\n";
        echo "        AUDIT means learning: launches are logged only.\n";
    }
    echo PHP_EOL;

    $use = [
        'APPLICATION' => chosen(ApplicationPolicy::class, (int) $company),
        'WEBSITE'     => chosen(WebsitePolicy::class, (int) $company),
    ];
    printf("  policy row used  : APPLICATION=%s  WEBSITE=%s\n\n",
        $use['APPLICATION'] ?? 'none', $use['WEBSITE'] ?? 'none');

    $rules = PolicyRule::withoutGlobalScopes()
        ->where('company_id', $company)
        ->orderBy('policy_type')->orderBy('item')
        ->get();

    printf("  %-12s %-22s %-9s %-10s %-8s %s\n",
        'TYPE', 'ITEM', 'ACTION', 'STATUS', 'POLICY', 'VERDICT');
    line();

    $sent = $dropped = 0;

    foreach ($rules as $r) {
        $type = strtoupper((string) $r->policy_type);
        $action = strtoupper((string) $r->action);
        $status = strtoupper((string) $r->status);

        $why = [];

        // Exactly the conditions PolicyRule::scopeEnforcing applies. Kept
        // literal rather than reusing the scope so the reason can be named.
        if (! in_array($action, HARD, true)) {
            $why[] = sprintf('action is %s, needs CLOSE or BLOCK', $action ?: 'empty');
        }
        if (! in_array($status, LIVE, true)) {
            $why[] = sprintf('status is %s, needs BLOCKED or VIOLATION', $status ?: 'empty');
        }
        if (! isset($use[$type])) {
            $why[] = sprintf('policy_type %s is not APPLICATION or WEBSITE', $type ?: 'empty');
        } elseif ($use[$type] === null) {
            $why[] = sprintf('the company has no %s policy row', $type);
        } elseif ((int) $r->policy_id !== (int) $use[$type]) {
            $why[] = sprintf('sits on policy %d, but %d is the one enforced', $r->policy_id, $use[$type]);
        }

        $verdict = $why ? 'DROPPED — ' . implode('; ', $why) : 'SENT';
        $why ? $dropped++ : $sent++;

        printf("  %-12s %-22s %-9s %-10s %-8s %s\n",
            $type, mb_strimwidth((string) $r->item, 0, 22, '…'),
            $action, $status, (string) $r->policy_id, $verdict);
    }

    line();
    printf("  %d rule(s) reach the endpoint, %d dropped.\n\n", $sent, $dropped);

    if ($dropped > 0) {
        echo "  Every DROPPED line above is a rule an admin set in the console and no PC\n";
        echo "  will ever act on. Fix the reason, or the console is lying to the client.\n\n";
    }
}
