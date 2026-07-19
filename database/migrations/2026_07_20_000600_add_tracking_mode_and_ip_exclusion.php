<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking mode + raw-IP site exclusion (Ejaz 19-Jul).
 *
 * tracking_mode is a nullable override on every level of the org chain
 * (device > employee > team > department > branch > company). NULL = inherit;
 * the resolver falls back to FULL when nothing in the chain sets it.
 *   FULL          — capture everything (default behaviour).
 *   PRESENCE_ONLY — attendance (login/out) + manual breaks only; no screenshots,
 *                   no app/website usage, no activity logs.
 *   EXCLUDED      — capture and store nothing (a liveness heartbeat still shows
 *                   the PC online as "not monitored").
 *
 * exclude_ip_sites: when a browser is on a raw-IP / local-IP host, the agent keeps
 * the working-hours TIME but stores it as "Unknown source" — no URL, title, text
 * or screenshot. Company-wide, on by default.
 */
return new class extends Migration
{
    private array $orgTables = ['employees', 'employee_devices', 'teams', 'departments', 'branches', 'companies'];

    public function up(): void
    {
        foreach ($this->orgTables as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'tracking_mode')) {
                    $table->string('tracking_mode', 16)->nullable();
                }
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'exclude_ip_sites')) {
                $table->boolean('exclude_ip_sites')->default(true);
            }
        });
    }

    public function down(): void
    {
        foreach ($this->orgTables as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (Schema::hasColumn($t, 'tracking_mode')) {
                    $table->dropColumn('tracking_mode');
                }
            });
        }

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'exclude_ip_sites')) {
                $table->dropColumn('exclude_ip_sites');
            }
        });
    }
};
