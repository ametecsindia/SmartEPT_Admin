<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protections: block an ACTIVITY inside an application, not the application.
 *
 * Until now a rule had exactly one lever — `action` — and its only preventive
 * value was CLOSE/BLOCK, which stops the whole program. A bank that wants its
 * collections floor to keep using WhatsApp for chat and calls, but never to
 * send a customer statement out of it, could not be expressed at all: the only
 * offer was "close WhatsApp".
 *
 * `protections` is that second lever, and it is deliberately ORTHOGONAL to
 * `action` and to `status`:
 *
 *   - A row can be ALLOWED and still carry protections. That is the whole
 *     point — the app is allowed, one activity inside it is not. So this must
 *     never be folded into the `action` column, whose values only mean anything
 *     on a BLOCKED/VIOLATION row.
 *   - A row set to CLOSE/BLOCK (the console now calls that "Full Block &
 *     Close") does not need protections; the program never starts.
 *
 * JSON rather than three boolean columns, on purpose. The set of protections
 * will grow — microphone, clipboard, printing and screen share are all the same
 * shape of control — and each of those should be a value the application layer
 * owns, not another migration and another column on a hot table. Same reasoning
 * as the standing VARCHAR-not-ENUM rule from 2026_08_12_000200.
 *
 * Shape (all keys optional, absent == false):
 *
 *   {"file": true, "image": true, "camera": false}
 *
 * BACKWARD COMPATIBILITY. The column is nullable with no default and nothing is
 * backfilled. NULL means "no protections", which is exactly today's behaviour,
 * so every existing rule keeps working unchanged and no existing configuration
 * is touched. Agents and enforcers that do not know the key ignore it; the old
 * `action` column is untouched, so an endpoint at or below the current build
 * still reads Full Block & Close as the CLOSE/BLOCK it has always been.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_rules')) {
            return;
        }

        if (! Schema::hasColumn('policy_rules', 'protections')) {
            Schema::table('policy_rules', function (Blueprint $t) {
                $t->json('protections')->nullable()->after('action');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('policy_rules') && Schema::hasColumn('policy_rules', 'protections')) {
            Schema::table('policy_rules', function (Blueprint $t) {
                $t->dropColumn('protections');
            });
        }
    }
};
