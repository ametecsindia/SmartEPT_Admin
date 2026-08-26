<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-rule actions. The schema blocker, fixed.
 *
 * application_policies.blocked_apps and website_policies.blocked_sites are JSON
 * arrays of bare strings, and action_on_blocked sits on the POLICY. So the model
 * literally cannot express "warn on Steam, close WhatsApp" — one action covers
 * every item in the tenant. That is why the console has always shown a single
 * "On block:" dropdown, and why nothing could ever be armed selectively.
 *
 * This table gives every item its own row, its own action, and its own audit
 * trail. The old JSON columns are LEFT IN PLACE and still written, so agents at
 * or below 0.14 keep working unchanged — they read the arrays and the
 * policy-level action exactly as before.
 *
 * Migration rule (decision 4): every existing entry keeps the behaviour it has
 * today. Each string becomes a row carrying its policy's current
 * action_on_blocked, which is WARN for essentially every tenant. Nobody gets
 * surprise enforcement from running an upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_rules')) {
            Schema::create('policy_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();

                // APPLICATION | WEBSITE. VARCHAR, not ENUM — standing lesson from
                // 2026_08_12_000200: the application layer owns the value set.
                $t->string('policy_type', 20);
                $t->unsignedBigInteger('policy_id');

                // The matched string, normalised the same way ComplianceEvaluator
                // normalises it: lowercased, no scheme, no www., no trailing .exe.
                $t->string('item', 191);
                $t->string('label', 191)->nullable();

                // TRACKED | ALLOWED | BLOCKED | VIOLATION — the per-item status the
                // Rules screen has always kept inside the categories map.
                $t->string('status', 20)->default('TRACKED');

                // LOG | WARN | NOTIFY | SCREENSHOT | CLOSE | BLOCK.
                // CLOSE and BLOCK are the two that reach the enforcement service.
                $t->string('action', 20)->default('WARN');

                // What the profile preset recommends, kept separate from what is
                // actually set (decision 17: the BFSI preset ships populated but
                // not armed, so the console can offer "arm this" per row).
                $t->string('suggested_action', 20)->nullable();

                // Links this rule to the enforcer catalogue, e.g. "whatsapp".
                // Null for a free-text rule an admin typed.
                $t->string('catalog_app_id', 64)->nullable();

                // {executable, publisher, package_name, package_family_name}.
                // Supplied from the catalogue so a tick on "WhatsApp" covers both
                // the Store package and the legacy desktop install — the thing a
                // hand-typed rule always misses.
                $t->json('identifiers')->nullable();

                // Who typed CONFIRM on a rule the protected list warned about.
                $t->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('confirmed_at')->nullable();

                $t->unsignedInteger('version')->default(1);
                $t->timestamps();

                $t->unique(['policy_type', 'policy_id', 'item'], 'policy_rules_unique_item');
                $t->index(['company_id', 'policy_type']);
                $t->index(['company_id', 'action']);
            });
        }

        $this->backfill();
    }

    /**
     * Expand the existing JSON arrays into rows. Idempotent: re-running skips
     * anything already present, so a partially-applied migration is safe to
     * repeat.
     */
    public function backfill(): void
    {
        $sets = [
            [
                'table'   => 'application_policies',
                'type'    => 'APPLICATION',
                'allowed' => 'allowed_apps',
                'blocked' => 'blocked_apps',
            ],
            [
                'table'   => 'website_policies',
                'type'    => 'WEBSITE',
                'allowed' => 'allowed_sites',
                'blocked' => 'blocked_sites',
            ],
        ];

        $now = now();

        foreach ($sets as $set) {
            if (! Schema::hasTable($set['table'])) {
                continue;
            }

            foreach (DB::table($set['table'])->orderBy('id')->cursor() as $policy) {
                // Decision 4: inherit the policy's action verbatim. WARN for
                // essentially every tenant, which is exactly today's behaviour.
                $action     = strtoupper((string) ($policy->action_on_blocked ?: 'WARN'));
                $allowed    = $this->jsonList($policy->{$set['allowed']} ?? null);
                $blocked    = $this->jsonList($policy->{$set['blocked']} ?? null);
                $categories = $this->jsonMap($policy->categories ?? null);

                $rows = [];

                // The categories map is where the Rules screen has been keeping
                // per-item status all along. Read it first so an explicit
                // TRACKED/VIOLATION survives the migration.
                foreach ($categories as $item => $cat) {
                    $cat = strtoupper((string) $cat);
                    if (in_array($cat, ['TRACKED', 'ALLOWED', 'BLOCKED', 'VIOLATION'], true)) {
                        $rows[$this->norm($item, $set['type'])] = ['item' => $item, 'status' => $cat];
                    }
                }
                foreach ($allowed as $item) {
                    $rows[$this->norm($item, $set['type'])] = ['item' => $item, 'status' => 'ALLOWED'];
                }
                foreach ($blocked as $item) {
                    $key = $this->norm($item, $set['type']);
                    $existing = $rows[$key]['status'] ?? null;
                    $rows[$key] = [
                        'item'   => $item,
                        'status' => $existing === 'VIOLATION' ? 'VIOLATION' : 'BLOCKED',
                    ];
                }

                $insert = [];
                foreach ($rows as $key => $row) {
                    if ($key === '') {
                        continue;
                    }
                    $exists = DB::table('policy_rules')
                        ->where('policy_type', $set['type'])
                        ->where('policy_id', $policy->id)
                        ->where('item', $key)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $blocking = in_array($row['status'], ['BLOCKED', 'VIOLATION'], true);

                    $insert[] = [
                        'company_id'  => $policy->company_id,
                        'policy_type' => $set['type'],
                        'policy_id'   => $policy->id,
                        'item'        => $key,
                        'label'       => $row['item'],
                        'status'      => $row['status'],
                        // A non-blocking row has no action to take. Storing LOG
                        // rather than the policy action keeps "what happens to
                        // this item" readable on its own row.
                        'action'      => $blocking ? $action : 'LOG',
                        'version'     => 1,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }

                foreach (array_chunk($insert, 200) as $chunk) {
                    DB::table('policy_rules')->insert($chunk);
                }
            }
        }
    }

    /** @return array<int,string> */
    private function jsonList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_scalar'));
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_scalar')) : [];
    }

    /** @return array<string,mixed> */
    private function jsonMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Must match App\Services\ComplianceEvaluator's normalisation, or a rule
     * saved here will never be found at classification time.
     */
    private function norm(string $s, string $type): string
    {
        $s = strtolower(trim($s));
        if ($type === 'APPLICATION') {
            return trim((string) preg_replace('/\.exe$/i', '', $s));
        }
        $s = (string) preg_replace('#^https?://#', '', $s);
        $s = (string) preg_replace('#^www\.#', '', $s);

        return trim($s, '/ ');
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rules');
    }
};
